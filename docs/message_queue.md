Introduction
============

The MessageQueue class provides a simple, lightweight message queueing mechanism
for SilverStripe applications. It can handle simple uses such as queueing
actions for long running process execution, as well as bi-directional
interaction with external messaging systems such as ApacheMQ.

Key Characteristics
-------------------

The behaviour of the module is mostly determined by it's configuration, set by
the application. The configuration is set via the methods
MessageQueue::add_interface() and MessageQueue::remove_interface(). These are
used to set one or more named *interfaces*.

Each interface provides one or more named *queues*. Each interface also
specifies an *implementation*, a class that implements a message queue or
interfaces to an external component that is the message queue. The module
includes two implementations:

* SimpleDBMQ - this implements message queueing within SilverStripe, in the
  database. This implementation has no external dependencies.
* StompMQ - this implements an interface to external messaging queueing
  components (such as ApacheMQ) using the Stomp protocol.

There are two primary operations:

* send - the application can send a message to a queue.
* receive - messages can be received from a queue, and delivered to the
  application.


Message Format
--------------

A message is encapsulated in a MessageFrame object, which contains:

* headers - a map of name => value pairs.
* body - the message body.

Send Behaviour
--------------

Messages are sent using MessageQueue::send(). This nominates a queue and the
message body. First, the interface to send through is determined from the queue
name. The selected interface is the first whose queueName option matches the
queue name provided. The queueName can be a regular expression, an array of
queue names, a single queue name, or null. If null, it will match the queue
name, so acts as a catch-all.

The interface configuration also includes an encoding. send() will encode the
message using this encoding. The default is "php-serialize", which calls that
PHP function to encode the message. The effect of this is that any PHP object
can be passed and received as a message, useful if the application is sending
messages to itself.

Once the message is encoded, it is sent via the specified implementation class.

Receive Behaviour
-----------------

There are two distinct phases to receiving messages:

* Getting the messages from the queue implementation. Retrieval is delegated to
  the implementation class.
* Delivering the messages to the application. This is done centrally by the
  MessageQueue class so the behaviour is consistent.

Message receiving can be triggered in different ways depending on the
circumstance:

* via a cron job or other external trigger.
* in a sub-process initiated after php shutdown (so that events queued in the
  application can be processed near-simultaneously without introducing delay in
  the user interaction.)
* programmatically within the application

Messages are generally received in bulk, with options to limit this. Once a set
of messages is received via the implementation class, each message is decoded
according to the encoding option on the interface, and delivery is attempted.

There are 3 message delivery options:

* If the message is a PHP object with an execute() method, the method is
  executed.
* If the interface provides a callback, that is called with the message as a
  parameter.
* If the interface specifies that the application fetches its own messages, the
  messages are only delivered in response to the application requesting them
  (this option is for where the application's normal page request execution is
  to process the messages synchronously with the user interaction.)

Exception Handling
------------------

If an exception occurs during delivery (i.e. during the execution of an object's
execute() method or the callback), the 'onerror' section of the interfaces
config determines what gets done. In general, this is an array of commands
which can include:

* log the error via SS_Log::log()
* drop the message
* re-queue the message, on the same queue (for retry), or onto another queue
  (e.g. might have a queue for errors)

Auto-executing Messages
-----------------------

As a convenience, a class MethodInvocationMessage is provided which encapsulates
a method call in one of the following forms:

* A static method call with parameters
* An instance method call to a DataObject instance with parameters
* An instance method call to an arbitrary object with parameters

When a message is received that is an object of this class, it will
automatically be executed, rather than delivered to the application via
callback. A further feature of this class is that user_errors are trapped and
thrown as exceptions, so user_errors are subject to the same exception handling
as real exceptions in the message processing engine.

In general, any class that is serializable and implements MessageExecutable can
be called this way. This is particularly useful for easy creation of actions or
commands to execute in a long running process. These messages are considered
"self-delivering", although are subject to exception processing.

Configuration Options and Examples
==================================

Default Configuration
---------------------

The default configuration is:
`
	MessageQueue::add_interface("default", array(
		"queues" => "/.*/",
		"implementation" => "SimpleDBMQ",
		"encoding" => "php_serialize",
		"send" => array(
			"processOnShutdown" => true
		),
		"delivery" => array(
			"onerror" => array(
					"log"
			)
		)));
`

The effective behaviour is that messages sent to any queue will be processed on
PHP shutdown. (Note: if this option is set, messages sent from another PHP
shutdown function will not be consumed).

Example 1:
`
	MessageQueue::send(
		"myqueue",
		new MethodInvocationMessage("MyClass", "someStatic", "p1", 2)
	);
`

This will cause the static method MyClass::someStatic("p1", 2) to be called in
a sub-process that is initiated from PHP shutdown. Errors will be logged.

NOTE: If you don't want the default behaviour in your site, you must call
MessageQueue::remove_interface("default") before adding the interfaces you want
to use.

Multiple Queues
---------------
`
	MessageQueue::add_interface("myinterface", array(
		"queues" => array("queue1", "queue2"),
		"implementation" => "SimpleDBMQ",
		"encoding" => "php_serialize",
		"delivery" => array(
			"onerror" => array(
					"log",
					"requeue" => "queue2"
			)
		)));
	MessageQueue::add_interface("default", array(
		"queues" => "/.*/",
		"implementation" => "SimpleDBMQ",
		"encoding" => "php_serialize",
		"send" => array(
			"processOnShutdown" => true
		),
		"delivery" => array(
			"onerror" => array(
					"log"
			)
		)));
`

This configuration has two explicitly named queues, queue1 and queue2. They will
not be processed on shutdown, so MessageQueue_Consume must be explicitly called
to process messages received on these queues. Errors on either of these queues
will be logged and re-queued onto queue2. Messages sent to any other queue will
be handled by the second interface, which is processed on PHP shutdown.

ApacheMQ
--------

(This example is incomplete. We need to document how to pass authentication
details thru, and how to use the durable clients feature of Stomp.)

`
	MessageQueue::add_interface("myinterface", array(
		"queues" => array("stompqueue1", "stompqueue2"),
		"implementation" => "StompMQ",
		"encoding" => "raw",
		"delivery" => array(
			"onerror" => array(
					"log",
					"requeue" => "queue2"
			)
		)));
	MessageQueue::add_interface("default", array(
		"queues" => "background",
		"implementation" => "SimpleDBMQ",
		"encoding" => "php_serialize",
		"send" => array(
			"processOnShutdown" => true
		),
		"delivery" => array(
			"onerror" => array(
					"log"
			)
		)));
`

In this example, two queues "stompqueue1" and "stompqueue2" are defined, and
message processing is handled through the StompMQ class. These queue names are
passed directly to Stomp, so they are the queue names identified externally, not
just within the SilverStripe application.

The second interface provides the "background" queue, with internal queuing and
processing on shutdown as before.

Error Handling Options
----------------------

The following configuration snippet shows the currently available forms for
processing delivery exceptions.

`
	...
	"delivery" => array(
		"log",
		"requeue",
		"requeue" => "errorQueue",
		"drop"
	),
	...
`

It is a list of commands of these forms, so more than one action can be taken.

* "log" logs the message via SS_Log::log
* "requeue" puts the message back in the same queue for later processing.
  (existing queue behaviour will exclude the message being executed again in
  the same queue consumption call)
* "requeue" => "queue" puts the message onto the named queue for later
  processing.
* "drop" does nothing. If used alone, the exceptioned message will be dropped.

Specifying a Callback for Delivery
----------------------------------

`
	...
	"delivery" => array(
		"callback" => array("MyClass", "method")
	),
	...
`

With this option, any message received that does not implement
MethodExecutable will be passed to the specified callback function. The
value is a method specifier for call_user_func_array, so can identify a static
function by supplying the class name and a static method name. The signature
of the callback is
`	function callback($msgFrame, $config)`
It is passed the incoming message frame (decoded) and the configuration of
the interface from which it was received.

Queue Syntaxes
--------------

The "queues" option in an interface configuration can be one of the following
forms:

* `"queues" => "myqueue"` specifies a single named queue.
* `"queues" => array("queue1", "queue2")` specifies a list of named queues.
* `"queues" => "/.*AppQ$/" specifies a regular expression to match against
				queue names. The regular expression must start and finish with
				forward slash. In this example, any queue name ending with
				AppQ, such as MyAppQ, will be matched against the interface.

Initiating Message Queue Processing
===================================

On PHP Shutdown
---------------
To initiate queue consumption on the PHP shutdown of the process that initiated
the send, you need to set that option (as above) on the interface
configuration.

By default, this calls the MessageQueue_Consume controller in a sub-process,
using 'sake'. This process can continue to execute after the main request
process has finished.

In some environments (particularly where there are multiple PHP binaries
that are not compiled the same - MacOS X built-in vs MAMP is a classic
example), 'sake' make not work. If this is the case, you can call the
following in mysite/_config.php:

`MessageQueue::set_onshutdown_option("phppath", $pathToPhp);`

If this is set, the provided php binary is used instead of sake in the
sub-process.

Note: this may vary between development, testing and production environments.


Externally Using Sake
---------------------
Messages in a queue can be recieved and processed using the command:

`
	sake MessageQueue_Consume "queue=myqueue"
`

This will read all messages on myqueue and deliver them to the application.

You can limit the number of entries processed:
`
	sake MessageQueue_Consume "queue=myqueue&limit=10"
`

You can schedule queue consumption using cron.

Externally Using wget
---------------------

In environments where there is no external php binary (e.g. only mod_php), you
may need to use wget to initiate the call to the MessageQueue_Consume
controller.

To Do
=====

* Complete StompMQ and test it.
* More test cases
* Option on queue consumption to process messages atomically. That is, do one
  message at a time, so that if there is a failure, we haven't lost a whole
  bunch of messages. We can also use transactions in the implementor class so
  that a complete failure can still leave the message there. Upside: more
  robust. Downside: worse performance (quite a bit more overhead)
* Options for re-try messages with exceptions. May be frequency of re-tries,
  max number of re-tries. Need to either: use headers to hold info (but cannot
  be guaranteed across different implementers); or push it to the implementer
  layer to handle.