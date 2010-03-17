<?php

/**
 * Simple message queueing implementor. Queues messages in a database table.
 *
 * @author Mark Stephens <mark@silverstripe.com>
 */

class SimpleDBMQ extends DataObject implements MessageQueueImplementation {
	static $db = array(
		"QueueName" => "Varchar(255)",
		"Header" => "Text",
		"Message" => "Text"
	);

	function send($queue, $msgframe, $interfaceConfig) {
		$msg = new SimpleDBMQ();
		$msg->QueueName = $queue;
		$msg->Message = $msgframe->body;
		$msg->Header = serialize($msgframe->header);
		$msg->write();
	}

	/**
	 * @TODO: This really needs to use transactions to ensure that only one reader will get each message. Might need
	 *	to implement a lock or something for MySQL MyISAM :-(
	 * @param String $queue
	 * @param <type> $interfaceConfig
	 * @param <type> $options
	 * @return <type>
	 */
	function receive($queue, $interfaceConfig, $options) {
		$result = new DataObjectSet();
		$limit = ($options && isset($options["limit"])) ? $options["limit"] : null;

		$conn = DB::getConn();

		// OK, start a transaction, or if we are in MySQL, create a lock on the SimpleDBMQ table.
		if ($conn instanceof MySQLDatabase) $res = $conn->query('lock table SimpleDBMQ write');
		else if (method_exists($conn, 'startTransaction')) $conn->startTransaction();

		try {
			$msgs = DataObject::get("SimpleDBMQ", $queue ? ("QueueName='$queue'") : "", null, null, $limit ? array("limit" => $limit, "start" => 0) : null);
			if (!$msgs) return $result;

			foreach ($msgs as $do) {
				$result->push(new MessageFrame($do->Message, unserialize($do->Header), $do->QueueName));
				$do->delete();
				$do->flushCache();
			}

			// Commit transaction, or in MySQL just release the lock
			if ($conn instanceof MySQLDatabase) $res = $conn->query('unlock tables');
			else if (method_exists($conn, 'endTransaction')) $conn->endTransaction();
		}
		catch (Exception $e) {
			// Rollback, or in MySQL just release the lock
			if ($conn instanceof MySQLDatabase) $res = $conn->query('unlock tables');
			else if (method_exists($conn, 'transactionRollback')) $conn->transactionRollback();

			throw $e;
		}

		return $result;
	}
}

?>
