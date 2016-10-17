<?php

namespace streaky\rethink_php_session;

class handlerException extends \Exception {}

class handler {

	/**
	 * @var \r\Connection
	 */
	private static $conn;

	/**
	 * @var \r\Queries\Tables\Table
	 */
	private static $table;

	public static function init(\r\Connection $conn, \r\Queries\Tables\Table $table) {
		self::$conn = $conn;
		self::$table = $table;
		self::setHandler();
	}

	private static function setHandler() {

		// NOTE: we need to call session_write_close explicitly as shutdown function so we still have
		// the connection object available when we need to write it
		register_shutdown_function('session_write_close');

		session_set_save_handler(
			array(__CLASS__, "open"),
			array(__CLASS__, "close"),
			array(__CLASS__, "read"),
			array(__CLASS__, "write"),
			array(__CLASS__, "destroy"),
			array(__CLASS__, "gc")
		);
	}

	public static function open($save_path, $name) {
		return true;
	}

	/**
	 * Closes the session
	 *
	 * @return bool Always returns true, because there's nothing for us to clean up.
	 */
	public static function close() {
		return true;
	}

	/**
	 * Retrieves the session by its ID (document's id).
	 *
	 * @param string $session_id The session ID.
	 *
	 * @return string The serialized session data (PHP takes care of deserialization for us).
	 */
	public static function read($session_id) {
		$resp = self::$table->get($session_id)->run(self::$conn);
		if($resp === null) {
			return "";
		}
		return $resp['session_data'];
	}

	/**
	 * Insert or overwrite session data. This will also advance the session's updated timestamp
	 * to time(), pushing out when it will expire and be garbage collected.
	 *
	 * @param string $session_id The sesion ID.
	 * @param string $session_data The serialized data to store
	 *
	 * @return bool Whether or not the operation was successful
	 */
	public static function write($session_id, $session_data) {
		$document = [
			"id" => $session_id,
			"updated" => time(),
			"session_data" => $session_data,
		];
		$result = self::$table->insert($document, ["conflict" => "update"])->run(self::$conn);
		return (bool) ($result['inserted'] + $result['replaced']);
	}

	/**
	 * Destroys the session, deleting it from the db
	 *
	 * @param string $session_id The session ID.
	 *
	 * @return bool Whether or not the operation was successful.
	 */
	public static function destroy($session_id) {
		$result = self::$table->get($session_id)->delete()->run(self::$conn);
		return (bool) $result['deleted'];
	}

	/**
	 * Runs garbage collection against the sessions, deleting all those that are older than
	 * the number of seconds passed to this function.
	 *
	 * @param int $maxlifetime The maximum life of a session in seconds.
	 *
	 * @return bool Whether or not the operation was successful.
	 */
	public static function gc($maxlifetime) {
		$end = time() - $maxlifetime;
		self::$table->filter(function($x) use ($end) {
			return $x('updated')->lt($end);
		})->delete()->run(self::$conn);
		return true;
	}
}
