<?php
// Entry types
define( 'LG_LOG',      1 );
define( 'LG_SERVER',   2 );
define( 'LG_USER',     3 );
define( 'LG_SESSION',  4 );
define( 'LG_SYNC', 5 );
define( 'LG_VERSION',  6 );
define( 'LG_DATABASE', 7 );

// Flags
define( 'LG_NEW',     1 << 0 ); // This item was just created (has never been modified)
define( 'LG_LOCAL',   1 << 1 ); // This item's changes never routes anywhere
define( 'LG_PRIVATE', 1 << 2 ); // This item's changes only route to the main server

/**
 * Class representing a single generic object in the distributed database
 */
class LigminchaGlobalObject {

	// Sub-classes to use for distributed object types (non-existent class means generic base class)
	public static $classes = array(
		LG_LOG     => 'Log',
		LG_SERVER  => 'Server',
		LG_SESSION => 'Session',
		LG_USER    => 'User',
		LG_SYNC    => 'Sync',
		LG_VERSION => 'Version',
	);

	// Whether the object exists in the database
	public $exists = false;

	// Properties for the database row fields
	var $id;
	var $ref1;
	var $ref2;
	var $tag;
	var $type;
	var $creation;
	var $modified;
	var $expire;
	var $flags;
	var $owner;
	var $group;
	var $name;
	var $data;

	/**
	 * There's nothing much in here because the automatic properties are added at update time
	 */
	function __construct() {
		$this->id = $this->uuid();
		$this->exists = false;
	}

	/**
	 * Make a new object given an id
	 */
	public static function newFromId( $id, $type = false ) {
		if( !$id ) die( __METHOD__ . ' called without an ID.' );
		if( $row = LigminchaGlobalDistributed::getObject( $id ) ) {
			$class = self::typeToClass( $row['type'] );
			$obj = new $class();
			$obj->exists = true;
			foreach( $row as $field => $val ) {
				$prop = "$field";
				$obj->$prop = $val;
			}
		} elseif( $type ) {
			$class = self::typeToClass( $type );
			$obj = new $class();
			$obj->id = $id;
			$obj->exists = false;
		}
		return $obj;
	}

	/**
	 * Load the data into this object from the DB (return false if no data found)
	 */
	protected function load() {

		// Get the objects row from the database
		if( !$row = LigminchaGlobalDistributed::getObject( $this->id ) ) return false;

		// TODO: Also check if it's a matching type of type already set
		foreach( $row as $field => $val ) {
			$prop = "$field";
			$this->$prop = $val;
		}

		// Mark this object as existing in the database
		$this->exists = true;

		return true;
	}

	/**
	 * Update or create an object in the database and queue the changes if necessary
	 * - $origin is passed if this changed arrived from a remote queue
	 * - $silent is used to stop any sync objects being generated by the change
	 */
	public function update( $origin = false, $silent = false ) {
		$db = JFactory::getDbo();
		$table = LigminchaGlobalDistributed::sqlTable();

		// Bail if no type
		if( $this->type < 1 ) die( 'Typeless distributed objects not allowed!' );

		// Update an existing object in the database
		if( $this->exists ) {

			// TODO: Validate cond

			// Update automatic properties
			$this->flag( LG_NEW, false );
			$this->modified = self::timestamp();

			$sqlVals = $this->makeValues( false );
			$db->setQuery( "UPDATE $table SET $sqlVals WHERE `id`=0x{$this->id}" );
			$db->query();
		}

		// Create a new object in the database
		else {

			// Only set the automatic properties for locally created non-existent objects
			if( !$origin ) {
				$this->flag( LG_NEW, true );
				$this->modified = null;
				$this->creation = self::timestamp();

				// The entry is owned by the user unless it's a server/sync/user object
				if( $this->type == LG_SERVER || $this->type == LG_USER || $this->type == LG_SYNC ) $this->owner = null;
				else $this->owner =  LigminchaGlobalUser::getCurrent() ? LigminchaGlobalUser::getCurrent()->id : null;
			}

			$sqlVals = $this->makeValues();
			$db->setQuery( "REPLACE INTO $table SET $sqlVals" );
			$db->query();
		}

		// Add outgoing sync objects depending on the context of this change
		// TODO: $private = $this->flag( LG_PRIVATE ) ? $this->owner->server : false
		if( !$silent && !$this->flag( LG_LOCAL ) ) LigminchaGlobalSync::create( 'U', $this->fields(), $origin, $private = false );
	}

	/**
	 * This is the update interface used by incoming sync objects being processed
	 * - Create a local object from the sync object so we can call the regular update method on it
	 */
	public static function updateFromSync( $fields, $origin ) {
		$obj = LigminchaGlobalObject::newFromFields( $fields );
		$obj->exists = (bool)LigminchaGlobalDistributed::getObject( $obj->id );
		$obj->update( $origin );
	}

	/**
	 * Delete objects matching the condition array
	 * - this is used for processing sync objects and normal delete calls alike
	 */
	public static function del( $cond, $origin = false, $silent = false ) {
		$db = JFactory::getDbo();
		$table = LigminchaGlobalDistributed::sqlTable();

		// Make the condition SQL syntax, bail if nothing
		$sqlcond = LigminchaGlobalDistributed::makeCond( $cond );
		if( empty( $sqlcond ) ) return false;

		// TODO: validate cond
		// TODO: check no LG_LOCAL in results

		// Do the deletion
		$db->setQuery( "DELETE FROM $table WHERE $sqlcond" );
		$db->query();

		// Add sync object(s) depending on the context of this change
		if( !$silent ) LigminchaGlobalSync::create( 'D', $cond, $origin );
	}

	/**
	 * Set, reset or return a flag bit
	 */
	public function flag( $flag, $set = null ) {
		if( $set === true ) $this->flags |= $flag;
		elseif( $set === false ) $this->flags &= ~$flag;
		else return (bool)($this->flags & $flag);
	}

	/**
	 * Find an object in the DB given the passed conditions
	 * TODO: really inefficient at the moment
	 */
	public static function find( $cond ) {
		$db = JFactory::getDbo();
		$table = LigminchaGlobalDistributed::sqlTable();
		$all = LigminchaGlobalDistributed::sqlFields();
		$sqlcond = LigminchaGlobalDistributed::makeCond( $cond );
		if( empty( $sqlcond ) ) return false;
		$db->setQuery( "SELECT $all FROM $table WHERE $sqlcond" );
		$db->query();
		if( !$result = $db->loadAssocList() ) return false;
		foreach( $result as $i => $assoc ) {
			$result[$i] = self::newFromFields( $assoc );
			$result[$i]->exists = true;
		}
		return $result;
	}

	/**
	 * Return just a single row instead of a list of rows
	 */
	public static function findOne( $cond ) {
		$result = self::find( $cond );
		return $result ? $result[0] : false;
	}

	/**
	 * Make object's properties into SQL set-values list
	 */
	private function makeValues( $priKey = true ) {
		$vals = array();
		foreach( LigminchaGlobalDistributed::$tableStruct as $field => $type ) {
			if( $priKey || $field != 'id' ) {
				$prop = "$field";
				$val = LigminchaGlobalDistributed::sqlField( $this->$prop, $type );
				$vals[] = "`$field`=$val";
			}
		}
		return implode( ',', $vals );
	}

	/**
	 * Make a hash of the passed content for an object ID
	 */
	public static function hash( $content ) {
		return strtoupper( sha1( $content ) );
	}

	/**
	 * Generate a new globally unique ID
	 */
	protected function uuid() {
		static $uuid;
		if( !$uuid ) $uuid = uniqid( $_SERVER['HTTP_HOST'], true );
		$uuid .= microtime() . uniqid();
		return $this->hash( $uuid );
	}

	/**
	 * Return a timestamp in the format used by the database entries
	 */
	public static function timestamp() {
		return microtime( true );
	}

	/**
	 * Set the object's data field
	 */
	public function setData( $data ) {
		if( is_array( $data ) ) $data = json_encode( $data );
		$this->data = $data;
	}

	/**
	 * Get an object's data
	 */
	public function getData() {
		$data = $this->data;
		$c1 = substr( $data, 0, 1 );
		if( $c1 == '[' || $c1 == '{' ) $data = json_decode( $data, true );
		return $data;
	}

	/**
	 * Convert a DB row assoc array into a LigminchaGlobalObject or sub-class
	 */
	public static function newFromFields( $fields ) {
		$class = 'LigminchaGlobalObject';
		if( array_key_exists( 'type', self::$classes ) ) {
			$c = 'LigminchaGlobal' . self::$classes[$fields['type']];
			if( !class_exists( $c ) ) $class = $c;
		}
		$obj = new $class();
		foreach( $fields as $field => $val ) $obj->$field = $val;
		return $obj;
	}

	/**
	 * Given an object type constant, get the glass name
	 */
	public static function typeToClass( $type ) {
		$class = 'LigminchaGlobalObject';
		if( array_key_exists( $type, self::$classes ) ) {
			$c = 'LigminchaGlobal' . self::$classes[$type];
			if( class_exists( $c ) ) $class = $c;
		}
		return $class;
	}

	/**
	 * Convert an object into an assoc array
	 */
	public function fields() {
		$fields = array();
		foreach( LigminchaGlobalDistributed::$tableStruct as $field => $type ) {
			$fields[$field] = $this->$field;
		}
		return $fields;
	}
}
