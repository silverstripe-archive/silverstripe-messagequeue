<?php

/**
 * Message queueing implementation class that uses Stomp to exchange messages with an
 * external message system.
 *
 * NOTE: THIS IS NOT COMPLETE OR TESTED IN ANY WAY, SO DON'T USE IT!
 *
 * Requires Stomp.php library.
 *
 * @TODO:
 *   * include stomp redistributables if legal.
 *   * complete the implementation and test it against ApacheMQ
 *
 * @author Mark Stephens <mark@silverstripe.com>
 */

class StompMQ implements MessageQueueImplementation {

	static $conn = null;

	// Constructor needed for singleton()
	function __construct() {
	}

	/**
	 * Set up for interacting with Stomp, icnluding creating the connection. Configuration
	 * info is taken from the interface configuration.
	 * @param <type> $config
	 * @return void
	 */
	protected function init($config) {
		if (self::$conn) return;

		require_once(Director::getAbsFile("messagequeue/thirdparty/stomp-php-1.0.0/Stomp.php"));

		$conf = $config["stomp"];
		self::$conn = new Stomp($conf["server"]);

		if (isset($conf["durableClientId"])) self::$conn->clientId = $conf["durableClientId"];

		// @TODO: handle authentication and any other connection properties
		self::$conn->connect();
	}

	function send($queue, $msgframe, $interfaceConfig) {
		$this->init($interfaceConfig);
		$result = self::$conn->send("/queue/" . $queue, $msgframe->body, $msgframe->header);
	}

	/**
	 * Get a bunch of messages via Stomp.
	 * @TODO Handle exceptions, and possibly using stomp transactions. If an exception occurs
	 * while receiving multiple messages, we need to ensure that the messages successfully retrieved
	 * are returned, because the server thinks these are done.
	 * @param String $queue
	 * @param Map $interfaceConfig
	 * @param Map $options
	 * @return DataObjectSet
	 */
	function receive($queue, $interfaceConfig, $options) {
		$this->init($interfaceConfig);

		self::$conn->subscribe("/queue/" . $queue);

		$result = new DataObjectSet();
		$count = 0;
		$limit = ($options && isset($options["limit"])) ? $options["limit"] : 0;
		while ((!$limit || $count < $limit) && ($frame = self::$conn->readFrame())) {
			$result->push(new MessageFrame($frame->body, $frame->headers, $queue));
			self::$conn->ack($frame);
			$count++;
		}

		self::$conn->unsubscribe($queue);

		return $result;
	}

}

?>
