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

		$msgs = DataObject::get("SimpleDBMQ", $queue ? ("QueueName='$queue'") : "", null, null, $limit ? array("limit" => $limit, "start" => 0) : null);
		if (!$msgs) return $result;

		foreach ($msgs as $do) {
			$result->push(new MessageFrame($do->Message, unserialize($do->Header), $do->QueueName));
			$do->delete();
			$do->flushCache();
		}

		return $result;
	}
}

?>
