<?php
/**
 * This class encapsulates all the distributed database functionality for the LigminchaGlobal extension
 */

// TYPE-SPECIFIC FLAGS (top eight bits - only need to be unique within the scope of their type)

class LigminchaGlobalDistributed {

	// Make singleton available if we need it
	public static $instance;

	// The query-string command for routing changes
	private static $cmd = 'changes';

	// The queue of changes to route at the end of the request
	private static $queue = array();

	// Our distributed data table
	public static $table = '#__ligmincha_global';

	// Table structure
	public static $tableStruct = array(
		'obj_id'   => 'BINARY(20) NOT NULL',
		'ref1'     => 'BINARY(20)',
		'ref2'     => 'BINARY(20)',
		'type'     => 'INT UNSIGNED NOT NULL',
		'creation' => 'INT UNSIGNED',
		'modified' => 'INT UNSIGNED',
		'expire'   => 'INT UNSIGNED',
		'flags'    => 'INT UNSIGNED',
		'owner'    => 'BINARY(20)',
		'group'    => 'TEXT',
		'tag'      => 'TEXT',
		'data'     => 'TEXT',
	);

	function __construct() {

		// Make singleton available if we need it
		self::$instance = $this;

		// Check that the local distributed database table exists and has a matching structure
		$this->checkTable();

		// Delete any objects that have reached their expiry time
		$this->expire();

		// If this is a changes request commit the data (and re-route if master)
		if( array_key_exists( self::$cmd, $_POST ) ) {
			self::recvQueue( $_POST['changes'] );
			exit;
		}
	}

	/**
	 * Check that the local distributed database table exists and has a matching structure
	 */
	private function checkTable() {
		$db = JFactory::getDbo();
		$table = '`' . self::$table . '`';

		// Create the table if it doesn't exist
		$def = array();
		foreach( self::$tableStruct as $field => $type ) $def[] = "`$field` $type";
		$query = "CREATE TABLE IF NOT EXISTS $table (" . implode( ',', $def ) . ",PRIMARY KEY (obj_id))";
		$db->setQuery( $query );
		$db->query();
		$this->log( LG_LOG, 'ligmincha_global table added' );

		// Get the current structure
		$db->setQuery( "DESCRIBE $table" );
		$db->query();

		// If the table exists, check that it's the correct format
		if( $db ) {
			$curFields = $db->loadAssocList( null, 'Field' );

			// For now only adding missing fields is supported, not removing, renaming or changing types
			$alter = array();
			foreach( self::$tableStruct as $field => $type ) {
				if( !in_array( $field, $curFields ) ) $alter[$field] = $type;
			}
			if( $alter ) {
				$cols = array();
				foreach( $alter as $field => $type ) $cols[] = "ADD COLUMN `$field` $type";
				$db->setQuery( "ALTER TABLE $table " . implode( ',', $cols ) );
				$db->query();
				$this->log( LG_LOG, 'ligmincha_global table fields added: (' . implode( ',', array_keys( $alter ) ) . ')' );
			}
		}
	}

	/**
	 * Add a new change item to the queue
	 */
	public static function appendQueue( $cmd, $fields ) {
		self::$queue[] = array( $cmd, $fields );
	}

	/**
	 * Send all queued changes
	 */
	public static function sendQueue() {

		// Bail if nothing to send
		if( count( self::$queue ) == 0 ) return false;

		// If this is the master, then use zero for session ID
		$sid = LigminchaGlobalServer::getCurrent()->isMaster ? 0 : LigminchaGlobalServer::getCurrent()->obj_id;

		// Session ID is the first element of the queue
		array_unshift( self::$queue, $sid );

		// Zip up the data in JSON format
		// TODO: encrypt using shared secret or public key
		$data = gzcompress( json_encode( self::$queue ) );

		print_r(self::$queue);

		return true;
	}

	/**
	 * Receive changes from remote queue
	 */
	private static function recvQueue( $data ) {

		// Unzip and decode the data
		// TODO: decrypt using shared secret or public key
		$queue =  json_decode( gzuncompress( $data ), true );

		if( LigminchaGlobalServer::getCurrent()->isMaster ) {
			// TODO: check group and re-route
		} else {
			// TODO: Check these changes are from the master
		}
	}

	/**
	 * Log an event in the global DB
	 */
	private function log( $text, $user = false ) {

		// If user set to true, get the current user's ID
		if( $user === true ) {
			// TODO
		}

		// TODO: set ref1 to the siteID, ref2 to the user if applicable, set timestamp
		// should use LG_PRIVATE and LG_QUEUED flags

	}

	/**
	 * Remove all expired items (these changes are not routed because all servers handle expiration themselves)
	 */
	private function expire() {
		$db = JFactory::getDbo();
		$table = '`' . self::$table . '`';
		$db->setQuery( "DELETE FROM $table WHERE `expire` > 0 AND `expire`<" . time() );
		$db->query();
	}

}
