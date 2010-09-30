<?php

/**
 * Main interface to simple message queueing system. See docs/message_queue.md
 * for details.
 *
 * @author Mark Stephens <mark@silverstripe.com>
 */
class MessageQueue {
	/**
	 * Main configuration for the module, defaulted to a simple db MQ for all
	 * queues with automatic clearing of queues on PHP shutdown. This should
	 * only be altered/replaced with calls to add_interface()/remove_interface().
	 */
	protected static $interfaces = array(
		"default" => array(
			"queues" => "/.*/",
			"implementation" => "SimpleDBMQ",
			"encoding" => "php_serialize",
			"send" => array(
				"onShutdown" => "all"
			),
			"delivery" => array(
				"onerror" => array(
					"log",
					"requeue"
				)
			)
		)
	);

	/**
	 * An array of queues that need to be consumed after PHP shutdown. If
	 * this is null, there are none to consume, and the php shutdown function
	 * won't be called. Otherwise it is a map of queue names => true.
	 * @var Array
	 */
	protected static $queues_to_flush_on_shutdown = null;

	/**
	 * Add an interface with it's configuration. If there is already a
	 * configuration of that name, it will be replaced.
	 * @param String $name			Name of the interface.
	 * @param Map $config			Configuration for the interface.
	 */
	static function add_interface($name, $config) {
		self::$interfaces[$name] = $config;
	}

	/**
	 * Removed a named interface.
	 * @param String $name		Name of the interface.
	 */
	static function remove_interface($name) {
		unset(self::$interfaces[$name]);
	}

	/**
	 * Method to return the interfaces configuration, primarily for the message queue so it can restore back.
	 * @static
     * @return void
	 */
	static function get_interfaces() {
		return self::$interfaces;
	}

	/**
	 * Location of debugging files, null if not debugging.
	 * @var String
	 */
	protected static $debugging_path = null;

	/**
	 * Supports debugging, specifically for php shutdown debugging which is
	 * otherwise impossible. If not set, stderr and stdout on php shutdown
	 * processes are redirected to /dev/null. If the path is set,
	 * stdout is redirector to $path/msgq.stdout and $path/msgq.stderr.
	 * @param String $path	A path to a writable location where two files are
	 *						created, msgq.stdout and msgq.stderr
	 */
	static function set_debugging($path) {
		if (substr($path, -1) == "/") $path = substr($path, 0, -1);
		self::$debugging_path = $path;
	}

	/**
	 * Short-circuit the MessageQueue, so it delivers immediately.
	 * This is intended for debug purposes and for testing.
	 */
	protected static $force_immediate_delivery = false;

	static function set_force_immediate_delivery($value) {
		self::$force_immediate_delivery = $value;
	}

	static function get_force_immediate_delivery() {
		return self::$force_immediate_delivery;
	}

	protected static $onshutdown_option = "sake";

	protected static $onshutdown_arg = "";

	/**
	 * By default, when unit tests are run, the onshutdown is not actually
	 * registered and the action is not executed, as it is executed outside
	 * the test environment. It is difficult but not impossible for unit tests
	 * to check that messages have been delivered. If this is set to true,
	 * the shutdown function will be registered even when running unit tests,
	 * to cater for those tests that have a way to check delivery of the
	 * message. Set via set_force_onshutdown_when_testing().
	 */
	protected static $force_onshutdown_when_testing = false;

	/**
	 * This sets the mode in which onShutdown is handled, and may need
	 * to be called if the shutdown processing doesn't work.
	 * There are 2 options:
	 *  - "sake" (default)	Sub-processes are run using exec, with sapphire/sake
	 *						being called to run the process. This requires
	 *						that php is on the path, and is the same php
	 *						interpreter as apache is using. (e.g. on MacOS X
	 *						the supplied php may be compiled differently that
	 *						under MAMP.)
	 * - "phppath"			Sub-processes are run using an explicitly identified
	 *						PHP binary, supplied as the arg.
	 * @param String $option		The option to use
	 * @param String $arg			The optional argument to that option.
	 */
	static function set_onshutdown_option($option, $arg = null) {
		self::$onshutdown_option = $option;
		self::$onshutdown_arg = $arg;
		if ($option == "phppath" && !$arg) throw new Exception("set_onshutdown_option: Path is required for phppath option");
	}

	static function set_force_onshutdown_when_testing($value) {
		self::$force_onshutdown_when_testing = $value;
	}

	static function get_force_onshutdown_when_testing() {
		return self::$force_onshutdown_when_testing;
	}

	/**
	 * Send a message to queue. Works out which interface to send it to, and dispatches it.
	 * @param String $queue Name of queue to send message to
	 * @param Any $message			The message to send.
	 * @param Map $header			A map of header items.
	 * @param Boolean $buffered		If the queue specifies a buffer and this is true, the messages will be
	 * 								sent to the buffer instead. If the queue specifies a buffer and this is false,
	 * 								the buffer is bypassed, and the messages are sent directly to destination.
	 * 								If the queue does not specify a buffer, this has no effect.
	 */
	static function send($queue, $message, $header = array(), $buffered = true) {
		$conf = self::get_queue_config($queue);
		$sendOptions = isset($conf["send"]) ? $conf["send"] : array();
		$sendQueue = $queue;

		// If we are buffering and the queue is configured with a buffer, we'll use the buffer queue's config
		// instead, because we're queueing to that.
		$buffer = "";
		if ($buffered && isset($conf["send"]) && is_array($conf["send"]) && isset($conf["send"]["buffer"])) $buffer = $conf["send"]["buffer"];
		if ($buffer) {
			$sendQueue = $buffer;
			$conf = self::get_queue_config($buffer);
		}

		$inst = singleton($conf["implementation"]);
		if (is_object($message) && $message instanceof MessageFrame) {
			$msgframe = $message;
			if (!$header == null) $header = array();
			if (!$msgframe->header) $msgframe->header = array();
			$msgframe->header = array_merge($msgframe->header, $header);
		}
		else $msgframe = new MessageFrame($message, $header);

		if (self::$force_immediate_delivery) {
			// Cut the loop short
			self::deliver_message($msgframe, $conf);
		}
		else {
			self::encode_message($msgframe, $conf);
			$inst->send($sendQueue, $msgframe, $conf);

			// If we are asked to process this queue on shutdown, ensure the php shutdown function
			// is registered, and that this queue has been added to the list of queues to process.
			// We sort out what actions are needed later.
			if (isset($sendOptions["onShutdown"]) &&
				(!SapphireTest::is_running_test() || self::$force_onshutdown_when_testing)) {
				if (!self::$queues_to_flush_on_shutdown) {
					// only register the shutdown function once, and only if asked for or defaulted
					if (!isset($sendOptions["registerShutdown"]) || $sendOptions["registerShutdown"])
						register_shutdown_function(array(__CLASS__, "consume_on_shutdown"));
					self::$queues_to_flush_on_shutdown = array();
				}
				self::$queues_to_flush_on_shutdown[$queue] = true;
			}
		}
	}

	/**
	 * PHP shutdown function for processing any	queues that need to be processed/consumed
	 * after the PHP process is done. For each queue that needs to be processed,
	 * it starts a new sub-process for queue.
	 */
	static function consume_on_shutdown() {
		if (!self::$queues_to_flush_on_shutdown) return;

		if (self::$debugging_path) {
			$stdout = ">> " . self::$debugging_path . "/msgq.stdout";
			$stderr = "2>>" . self::$debugging_path . "/msgq.stderr";
		}
		else {
			$stdout = "> /dev/null";
			$stderr = "2> /dev/null";
		}

		// If we're debugging, dump the simpleDBMQ messages to the output.
		// This is the typical case, obviously not applicable if not using
		// simpledbmq, but the interface can't provide a guaranteed method to
		// get the messages without consuming them.
		if (self::$debugging_path) {
			$msgs = DataObject::get("SimpleDBMQ");
			if ($msgs) {
				`echo "messages currently in queue:\n" $stdout`;
				foreach ($msgs as $msg) `echo " queue={$msg->QueueName} msg={$msg->Message}\n" $stdout`;
			}
		}

		foreach (self::$queues_to_flush_on_shutdown as $queue => $dummy) {
			$config = MessageQueue::get_queue_config($queue);
			if (!isset($config["send"]) || !is_array($config["send"])) throw new Exception("MessageQueue: unexpectedly invalid/absent send config on onShutdown");
			$opts = $config["send"] ? $config["send"] : array();
			$opts = isset($opts["onShutdown"]) ? $opts["onShutdown"] : "";

			if (is_string($opts)) $opts = explode(",", $opts);
			if (in_array("none", $opts)) $opts = array();
			if (in_array("all", $opts)) $opts = array("flush", "consume");
			$actions = implode(",", $opts);

			switch (self::$onshutdown_option) {
				case "sake":
					$exec = Director::getAbsFile("sapphire/sake");
					`$exec MessageQueue_Process queue=$queue actions=$actions $stdout $stderr &`;
					break;
				case "phppath":
					$php = self::$onshutdown_arg;
					$sapphire = Director::getAbsFile("sapphire");
					$cmd = "$php $sapphire/cli-script.php MessageQueue_Process queue=$queue actions=$actions $stdout $stderr &";
					`$cmd`;
					if (self::$debugging_path) {
						`echo "queue is $queue\n" $stdout`;
						`echo "command was $cmd\n" $stdout`;
					}
					break;
				default:
					throw new Exception("MessageQueue::consume_on_shutdown: invalid option " . self::$queues_to_flush_on_shutdown);
			}
		}
	}

	/**
	 * Given a queue name, return the name of the interface that will handle it. If the queue name
	 * is not provided, the first interface is returned. If there is no interface that handles
	 * the queue, returns null.
	 * @param String $queue Name of the queue.
	 * @return String
	 */
	static function get_queue_interface($queue = null) {
		$interfaceName = null;
		foreach (self::$interfaces as $name => $conf) {
			if ($queue == null) {
				$interfaceName = $name;
				break;
			}
			else if (isset($conf['queues'])) {
				if (is_array($conf["queues"]) && in_array($queue, $conf["queues"])) {
					$interfaceName = $name;
					break;
				}
				else if (is_string($conf["queues"])) {
					if ((substr($conf['queues'], 0, 1) == '/' && preg_match($conf['queues'], $queue)) ||
						(is_string($conf['queues']) && $conf['queues'] == $queue)) {
						$interfaceName = $name;
						break;
					}
				}
			}
		}
		return $interfaceName;
	}

	/**
	 * Given a queue name, find the interface that will handle this queue. Returns the configuration
	 * of the interface, and does some basic checks that it has what's needed to work with it. If
	 * checks fail, exceptions are thrown.
	 * @param String $queue
	 * @return Array
	 */
	static function get_queue_config($queue) {
		$interface = self::get_queue_interface($queue);
		$conf = self::$interfaces[$interface];

		if (!$conf) throw new Exception("Error sending message to queue '$queue': no matching configured queue");
		if (!isset($conf["implementation"])) throw new Exception("Error sending message to queue '$queue': configuration doesnt provide a message queue implementation class");
		return $conf;
	}

	/**
	 * Flush any buffered messages on the specified queue. If the queue is not buffered, or the buffer is empty, it has
	 * no effect.
	 * @static
	 * @param  $queue
	 * @return void
	 */
	static function flush($queue) {
		$conf = self::get_queue_config($queue);

		if (!$conf) throw new Exception("Error flushing queue '$queue': no matching configured queue");

		if (!isset($conf["send"]) || !(is_array($sendOpts = $conf["send"]))) return;
		if (!isset($sendOpts["buffer"])) return; // no buffer
		$buffer = $sendOpts["buffer"];

		// The buffer is a queue. Get all the messages from that queue, and then invoke send on our implementation class
		$messages = MessageQueue::get_messages($buffer);

		// Send these messages to the original queue, with no buffering.
		if ($messages) foreach ($messages as $m) MessageQueue::send($queue, $m, array(), false);
	}

	/**
	 * Consume messages from a queue and deliver them.
	 * @param String $queue		Name of queue.
	 * @param Map $options		Options for controlling the receiving process. These are:
	 *							"limit" => n		Specifies the maximum number of messages to recieve in one
	 *												call. Default is to retrieve all messages.
	 * @return Int   The number of messages processed. @TODO should this be total number processed, or those delivered without error.
	 */
	static function consume($queue, $options = null) {
		$conf = self::get_queue_config($queue);
		$inst = singleton($conf["implementation"]);

		// Get a set of messages from this queue
		if (!$msgs = $inst->receive($queue, $conf, $options)) return 0;
		foreach ($msgs as $msgframe) {
			self::decode_message($msgframe, $conf);
			self::deliver_message($msgframe, $conf);
		}
		return $msgs->Count();
	}

	/**
	 * Get messages from a queue. This should only be used in circumstances
	 * where the retrieved messages are being processed as part of the user
	 * interaction, or where for some reason normal message delivery is not
	 * appropriate.
	 *
	 * This method will decode the message using the configured encoding, but
	 * does not deliver the message, which means that InvocableMessage objects
	 * will not be executed, and exception handling is to be done by the caller.
	 *
	 * @param String $queue		Queue name
	 * @param Map $options		Options passed to the implementator
	 * @return DataObjectSet	A data object set of MessageFrame objects.
	 */
	static function get_messages($queue, $options = null) {
		$conf = self::get_queue_config($queue);
		$inst = singleton($conf["implementation"]);

		// Get a set of messages from this queue
		if (!$msgs = $inst->receive($queue, $conf, $options)) return $msgs;
		foreach ($msgs as $msgframe) self::decode_message($msgframe, $conf);
		return $msgs;
	}

	/**
	 * Encode the message using the encoding specified in the configuration provided.
	 * @TODO Generalise this. Note this may not be a function purely of the message body,
	 *  and may entail reading and/or updating message headers, hence passing the whole frame.
	 */
	static function encode_message(&$msgframe, $conf) {
		$encoding = isset($conf["encoding"]) ? $conf["encoding"] : "php_serialize";
		switch ($encoding) {
			case "php_serialize":
				$msgframe->body = serialize($msgframe->body);
				break;
			case "raw":
				break;
			default:
				throw new Exception("Unknown message encoding '{$config["encoding"]}'");
		}
	}

	/**
	 * Decode the message using the encoding specified in the configuration provided.
	 */
	static function decode_message(&$msgframe, $config) {
		$encoding = isset($config["encoding"]) ? $config["encoding"] : "php_serialize";
		switch ($encoding) {
			case "php_serialize":
				$msgframe->body = unserialize($msgframe->body);
				break;
			case "raw":
				break;
			default:
				throw new Exception("Unknown message encoding '{$config["encoding"]}'");
		}
	}

	/**
	 * Consume messages from all queues on a specific interface.
	 * @param String $interfaceName
	 * @param Map $options
	 */
	static function consume_all_queues($interfaceName, $options = null) {
		if (!isset(self::$interfaces[$interfaceName])) throw new Exception("consume_all_queues: unknown interface '$interfaceName'");
		$conf = self::$interfaces[$interfaceName];
		$inst = singleton($conf["implementation"]);

		$msgs = $inst->receive(null, $conf, $options);
		foreach ($msgs as $msgframe) {
			self::decode_message($msgframe, $conf);
			self::deliver_message($msgframe, $conf);
		}
		return $msgs->Count();
	}

	/**
	 * Determine how to deliver the message. This depends on what's in the interface configuration, delivery section.
	 * If a callback is supplied, that is executed.
	 * @param <type> $msg
	 * @param <type> $conf
	 * @TODO: would be easy to generalise onerror into a list of these actions that are
	 *       done in sequence. e.g. log an error and then requeue it.
	 * @TODO: would be good to have a failure count on the error message. Not sure how to
	 *       represent this generally.
	 */
	static function deliver_message($msgframe, $conf) {
		if (!$conf || !isset($conf["delivery"])) throw new Exception("deliver_message failed because it was not passed valid configuration with delivery section");
		$del = $conf["delivery"];

		try {
			if (isset($del["requeue"])) {
				// delivery means stick it in another queue. This is expected to be an array with at least a queue name
				if (!is_array($del["requeue"])) throw new Exception("delivery of message failed because it specifies requeue, but it is not the expected array");
				$newQueue = isset($del["requeue"]["queue"]) ? $del["requeue"]["queue"] : null;
				if (!$newQueue) throw new Exception("delivery of message failed because it specified requeue, but doesn't specify a queue");
				$newConf = MessageQueue::get_queue_config($newQueue);
				if (isset($del["requeue"]["immediate"]) && $del["requeue"]["immediate"]) {
					// Immediate execution - get the configuration for the queue, and recurse to deliver immediately.
					MessageQueue::deliver_message($msgframe, $newConf);
				}
				else {
					// Not immediate, so put this message on the specified queue, and it will hopefully get delivered at
					// some later time.
					MessageQueue::send($newConf, $msgframe);
				}
			}
			else if (isset($del["callback"])) {
				// delivery is via a callback
				call_user_func_array($del["callback"], array($msgframe, $conf));
			}
			else if (is_object($msgframe->body) && $msgframe->body instanceof MessageExecutable) $msgframe->body->execute($msgframe, $conf);
			else throw new Exception("delivery of message failed because there is no specification of what to do with it");
		}
		catch (Exception $e) {
			// Look at the config to determine what to do with a failed message.
			$onerror = isset($del["onerror"]) ? $del["onerror"] : "drop";
			if (!is_array($onerror)) $list = array($onerror);
			else $list = $onerror;

			// There are two types of entry. Those with numeric keys have the action
			// as the value. Those with non-numeric keys have the key as the action,
			// and the value as an argument.
			foreach ($list as $key => $value) {
				if (is_numeric($key)) {
					$action = $value;
					$arg = null;
				}
				else {
					$action = $key;
					$arg = $value;
				}
				switch ($action) {
					case "drop":
						break;		// do nothing
					case "requeue":
						if (!$arg) $arg = $msgframe->queue; // requeue on the same queue if not specified.
						MessageQueue::send($arg, $msgframe->body, $msgframe->header);
						break;
					case "log":
						SS_Log::log($e, null);
						echo $e->getMessage();
						break;
					case "callback":
						if (!$arg) throw new Exception("delivery of message failed with error callback indicated, but no callback function supplied");
						call_user_func_array($arg, array($e, $msgframe));
						break;
					default:
						throw new Exception("Invalid onerror action '$action'");
				}
			}
		}
	}
}

/**
 * Interface to classes that provide an implementation for sending and receiving
 * messages.
 */
interface MessageQueueImplementation {
	/**
	 * Send a message on a queue.
	 * @param String $queue		Queue name, as interpreted by the the MQ implementor.
	 * @param <type> $msgframe	The message frame containing body and header. The header
	 *							is subject to interpretation by the queue implementor.
	 *							The message body should already be in an encoded
	 *							form acceptable to the MQ implementation. For
	 *							Stomp, this is a string, so generally the message
	 *							should be encoded in some string-based format.
	 *							Specific implementor classes may not require this, however.
	 * @param <type> $interfaceConfig	The interface configuration for the queue.
	 */
	function send($queue, $msgframe, $interfaceConfig);

	/**
	 * Receive one or more messages from a queue.
	 * Notes:
	 *   - the implementor class is responsible for ensuring that message retrieval
	 *	   is atomic, and specifically that if the MessageQueue::consume() is
	 *	   called simultaneously by multiple processes, each message is only
	 *     processed once.
	 * @param String $queue
	 * @param Map $interfaceConfig	The interface configuration for the queue.
	 * @param Map $options
	 * @return DataObjectSet		Returns a set of MessageFrame objects. The headers are MQ implementation
	 *								dependent. The body is still in its encoded form.
	 */
	function receive($queue, $interfaceConfig, $options);
}

/**
 * An interface that can be applied if a message object is capable of being
 * executed.
 */
interface MessageExecutable {
	/**
	 * Execute the method. No result is returned. This should throw an
	 * exception if there are problems, rather than use user_error which
	 * cannot be caught (and bypasses error handling in the message engine).
	 * 
	 * @param MessageFrame		The message frame, which provides access to the
	 *							headers.
	 * @param Map $config		The interface configuration that applied.
	 * @return void
	 */
	public function execute(&$msgFrame, &$config);
}

/**
 * Message frame is what is passed to/from the message queue implementation classes.
 * The header is interpreted by the implementation class. The body is the message
 * itself.
 */
class MessageFrame extends ViewableData {
	var $header = null;
	var $body = null;

	/**
	 * Name of queue that message was received from.
	 * @var String queue
	 */
	var $queue = null;

	function __construct($body = null, $header = null, $queue = null) {
		parent::__construct();
		$this->body = $body;
		if ($header && !is_array($header)) throw new Exception("Message frame expects header to be an array");
		$this->header = $header;
		$this->queue = $queue;
	}
}

/**
 * A simple controller that can be used to consume messages.
 */
class MessageQueue_Process extends Controller {
	function index() {
		$req = $this->getRequest()->requestVars();
		$queue = ($req && isset($req["queue"])) ? $req["queue"] : null;
		$limit = ($req && isset($req["limit"])) ? $req["limit"] : null;

		// Work out what processes need to be run. Tries 'actions' and 'action', which are synonyms.
		if ($req && isset($req["actions"])) $actions = $req["actions"];
		else if ($req && isset($req["action"])) $actions = $req["action"];
		else $actions = "all";

		$actions = explode(",", $actions);
		$flush = false;
		$consume = false;
		foreach ($actions as $a) {
			if ($a == "flush" || $a == "all") $flush = true;
			if ($a == "consume" || $a == "all") $consume = true;
		}

		if ($flush) MessageQueue::flush($queue);

		if ($consume) {
			$count = MessageQueue::consume($queue, $limit ? array("limit" => $limit) : null);
			if (!$count) return $this->httpError(404, 'No messages');
		}
		return 'True';
	}
}
?>
