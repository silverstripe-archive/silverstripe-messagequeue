# Message Queue Module

[![Build Status](https://secure.travis-ci.org/silverstripe-labs/silverstripe-messagequeue.png?branch=master)](http://travis-ci.org/silverstripe-labs/silverstripe-messagequeue)

## Introduction

The MessageQueue module provides a simple, lightweight message queueing mechanism
for SilverStripe applications. It supports the following features:
* queueing actions for long running process execution
* message sending between SilverStripe installations
* bi-directional interaction with external messaging systems ssuch as ApacheMQ.

## Requirements

- Stomp.php if Stomp is being used (Note: experimental only)
- processOnShutdown option requires *nix or OS X (won't work on Windows)

## Installation

Extract messagequeue into the base folder of your SilverStripe application. Default
configuration applies, documented below. Ensure its called "messagequeue".
In mysite/_config.php, put any code for setting the interface configuration
of the module.


## Key Characteristics

The behaviour of the module is mostly determined by it's configuration, set by
the application. The configuration is set via the methods
MessageQueue::add_interface() and MessageQueue::remove_interface(). These are
used to set one or more named *interfaces*.

Each interface provides one or more named *queues*. Each interface also
specifies an *implementation*, a class that implements a message queue or
interfaces to an external component that is the message queue. The module
includes three implementations:

* SimpleDBMQ - this implements message queueing within SilverStripe, in the
  database. This implementation has no external dependencies.
* SimpleInterSSMQ - this implements sending messages to another SilverStripe
  installation over HTTP.
* StompMQ - this implements an interface to external messaging queueing
  components (such as ApacheMQ) using the Stomp protocol.

There are two primary operations:

* send - the application can send a message to a queue.
* receive - messages can be received from a queue, and delivered to the
  application.


## Message Format

A message is encapsulated in a MessageFrame object, which contains:

* headers - a map of name => value pairs.
* body - the message body.

## Send Behaviour

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

## Receive Behaviour

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
  the user interaction, and for buffered queues, messages to remote systems can
  be delivered outside the page request process)
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


Notes:

* Queue implementation classes guarantee that any set of messages retrieved are
  done atomically, so that multiple processes can process a queue but
  guaranteeing that each message delivery attempt is done once. A message is
  only successfully delivered once.

## Output Buffering

When sending messages to a remote system, it is often beneficial to buffer the
outgoing messages and send them outside of the PHP request that is sending
them. Output buffering is very easy to configure, and involves setting up
another queue to act as the buffer. For example:

`
	MessageQueue::add_interface("default", array(
		"queues" => array("remote"),
		"implementation" => "SimpleInterSSMQ",
		"implementation_options" => array("remoteServer" => "http://myothersite.com/SimpleInterSSMQ_Accept"),
		"encoding" => "php_serialize",
		"send" => array(
				"buffer" => "remote_buffer",
				"onShutdown" => "flush"
		),
		"delivery" => array(
				"onerror" => array("log")
		)
	));

	MessageQueue::add_interface("buffer", array(
		"queues" => array("remote_buffer"),
		"implementation" => "SimpleDBMQ",
		"send" => array(
			"onShutdown" => "none"
		),
		"delivery" => array(
				 "onerror" => array("log")
		)
	));
`

The main points are:
* The queue `remote` specifies a buffer in the send options. This is the name
  of the buffer queue.
* `onShutdown` specifies that the queue should be flushed on shutdown, which
  is done in another process.
* Creating another queue, remote_buffer, using the SimpleDBMQ interface. This
  queue is configured not to process on shutdown (it shouldn't be explicitly
  consumed)

When a message is sent to `remote`, the message is actually sent to the buffer
queue. When the `remote` queue is flushed, it reads back the messages queued on
the buffer queue, and at that point sends them via real configured interface
(in this case, SimpleInterSSMQ).


## Exception Handling

If an exception occurs during delivery (i.e. during the execution of an object's
execute() method or the callback), the 'onerror' section of the interfaces
config determines what gets done. In general, this is an array of commands
which can include:

* log the error via SS_Log::log()
* drop the message
* re-queue the message, on the same queue (for retry), or onto another queue
  (e.g. might have a queue for errors)

## Auto-executing Messages

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

## Configuration Options and Examples

### Default Configuration

The default configuration is:
`
	MessageQueue::add_interface("default", array(
		"queues" => "/.*/",
		"implementation" => "SimpleDBMQ",
		"encoding" => "php_serialize",
		"send" => array(
			"onShutdown" => "all"
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

### Multiple Queues

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
			"onShutdown" => "all"
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


### SimpleInterSSMQ

This interface provides a simple way to send messages from one SilverStripe installation to another without requiring
any additional installed software. It works by the sender initiating an HTTP request to a controller at the destination
whch accepts messages.

An example configuration on the send is:

`
MessageQueue::add_interface("default", array(
        "queues" => array("mydest"),
        "implementation" => "SimpleInterSSMQ",
        "implementation_options" => array("remoteServer" => "http://mydestination.com/SimpleInterSSMQ_Accept"),
        "encoding" => "php_serialize",
        "send" => array(
               "buffer" => "mydest_buffer",
                "onShutdown" => "flush"
        ),
        "delivery" => array(
                "onerror" => array(
                        "log"
                ),
)));

MessageQueue::add_interface("buffer", array(
        "queues" => array("mydest_buffer"),
        "implementation" => "SimpleDBMQ",
        "delivery" => array(
                   "onerror" => array("log")
        )
));
`

It sets up a queue called `mydest`. The `implementation_options` specify the remote accepting controller. This example
also specifies a buffer queue called `mydest_buffer`. When messages are sent to `mydest`, they are buffered into
`mydest_buffer`, and actually in a process initiated by PHP shutdown for better user performance.

On the destination, the following should be present in mysite/_config.php:
`
SimpleInterSSMQ_Accept::setEnabled(true);
`

This is required because SimplerInterSSMQ_Accept controller is disabled by default for security purposes.

To send a message from the source, it is a simple message send:

`
	MessageQueue::send("mydest", $someObject);
`

or even a self-invoking message:
`
	MessageQueue::send("mydest", new MethodInvocationMessage("SomeClass", "someMethod", $parameter));
`



### ApacheMQ

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
			"onShutdown" => "all"
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


### Error Handling Options

The following configuration snippet shows the currently available forms for
processing delivery exceptions.

`
	...
	"delivery" => array(
		"log",
		"requeue",
		"requeue" => "errorQueue",
		"callback" => array("MyClass", "method"),
		"drop"
	),
	...
`

It is a list of commands of these forms, so more than one action can be taken.

* "log" logs the message via SS_Log::log. Note that SS_Log has options for
  where errors are logged, including notification email. This needs to be
  configured separately in the application.
* "requeue" puts the message back in the same queue for later processing.
  (existing queue behaviour will exclude the message being executed again in
  the same queue consumption call)
* "requeue" => "queue" puts the message onto the named queue for later
  processing.
* "callback" => $method  invokes the specified method. $method should be a
  valid callback definition. The callback function is passed two parameters,
  the exception object and the messageframe that failed to be delivered.
* "drop" does nothing. If used alone, the exceptioned message will be dropped.

### Specifying a Callback for Delivery

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

### Custom Shutdown Handling

If you want to process messages on shutdown, but your application requires a shutdown
function which queues messages, there is in an issue because the shutdown functions
are executed by PHP in the order in which they are executed. A way to to handle this
is as follows. In the interface configuration, use the registerShutdown property.

`
	...
	"send" => array(
		"onShutdown" => "all",
		"registerShutdown" => false,
	)
	...
`

Then in your custom shutdown function do the following:

`
	...
	MessageQueue::send("myqueue", new MethodInvocationMessage("MyClass", "my_method"));
	...

    // force MessageQueue to spawn the process that handles the messages as it
    // normally would on shutdown.
    MessageQueue::consume_on_shutdown();
`


### Retriggering Queue Processing

When messages are sent on shutdown, the default behaviour is to initiate a process
that sends all messages in the queue. Sometimes you might want to limit the number
of messages sent in a single process, but do want to send all the messages. For example,
if you have to perform a memory-intensive operation on a large number of objects, attempting
to send all the messages in a single process may cause PHP to run out of memory. Message queue provides
options `retrigger` and `onShutdownMessageLimit` that can be used to work around this.
`onShutdownMessageLimit` sets a limit on the number of items in the queue that are sent
by a single PHP process executed asynchronously. `retrigger` causes the asynchronous process
to initiate a further process if there are still unsent messages in the queue its processing.

To configure this behaviour, do the following:
`
	...
	"send" => array(
		"onShutdown" => "all",
		"retrigger" => "yes",                   // on consume, retrigger if there are more items
		"onShutdownMessageLimit" => "1"         // one message per async process
	)
`

Notes:

- in the general case, the initial shutdown will result in a "chain" of sychronous
  PHP processes that will in time clear all messages from the queue.
- this does not guarantee that there is only one consumer of the queue. If two separate
  HTTP requests both send messages to the queue, it is possible that two processes are both
  sending the messages (however, a given message will only be executed by one of them.) Care
  must be taken if the processes can adversely interact.


### Queue Syntaxes

The "queues" option in an interface configuration can be one of the following
forms:

* `"queues" => "myqueue"` specifies a single named queue.
* `"queues" => array("queue1", "queue2")` specifies a list of named queues.
* `"queues" => "/.*AppQ$/" specifies a regular expression to match against
				queue names. The regular expression must start and finish with
				forward slash. In this example, any queue name ending with
				AppQ, such as MyAppQ, will be matched against the interface.

### Specifying Requeuing for Delivery

`
	...
	"delivery" => array(
		"requeue" => array(
			"queue" => "otherQueue",
			"immediate" => true
		)
	),
	...
`

With this option, you can specify that when delivery is attempted, it is put
into another queue. If the immediate option is set, the delivery of the message
on that queue is attempted in-process. If immediate is false, no further
immediate attempt is made to deliver the message from the other queue - it will
be delivered according to the deliver execution rules of that queue.

## Initiating Message Queue Processing

There are two distinct processes that can be initiated on a queue, as follows:

* `flush` has effect only on a buffered queue, and will cause the buffered
  messages to be sent to the real destination.
* `consume` will cause messages on the queue to be retrieved and delivered.

Typically, one or both of these actions can be executed on a queue.

### On PHP Shutdown

To initiate queue processing on the PHP shutdown of the process that initiated
the send, you need to set the `onShutdown` option on the interface
configuration. `onShutdown` can be a single option as a string, or an array of
option strings. The valid options are:

* `flush` - invokes flush of queue.
* `consume` - invokes consumption of the queue.
* `all` - flush and consume
* `none` - do neither - no process will be invoked.

Flush is invoked before consume.

By default, this calls the MessageQueue_Process controller in a sub-process,
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


### Externally Using Sake

Messages in a queue can be processed using the command:

`
	sake MessageQueue_Process "queue=myqueue&actions=all"
`

This will flush and then consume all messages on myqueue, and delivering them
to the application.

You can limit the number of entries processed:
`
	sake MessageQueue_Process "queue=myqueue&limit=10&actions=consume"
`

The `actions` query field can be a comma-separated list containing `flush`,
`consume`, `all` or `none`.

You can schedule queue consumption using cron.

### Externally Using wget

In environments where there is no external php binary (e.g. only mod_php), you
may need to use wget to initiate the call to the MessageQueue_Consume
controller.

## To Do

* Complete StompMQ and test it. Specific functions not supported at this stage
  includes authentication.
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

## Known Issues

### Message Consumption on PHP Shutdown Issues on MacOS X w/MAMP

If the message queue appears to clearing on shutdown, but the messages are
not being delivered (callback not being executed for example), enable
debugging. If you see this message:

`
	Symbol not found: __cg_jpeg_resync_to_restart
`
You need to ensure that /Applications/MAMP/Library/bin/envvars contains:

`
	DYLD_LIBRARY_PATH="/Applications/MAMP/Library/lib:$DYLD_LIBRARY_PATH"
	export DYLD_FALLBACK_LIBRARY_PATH=/Applications/MAMP/Library/lib
`

### Diagnosing Message Queue Processing on PHP Shutdown

By default when a process is initiated to clear a queue on PHP shutdown, the
process redirects output to /dev/null. To assist in debugging these processes,
call MessageQueue::set_debugging can be called to set a directory to write log
files to, and both stdout and stderr are redirected to real files in that
directory.

### DataObject Sent Remotely

Currently when a DataObject is sent as message body, it is serialised as a
DataObject with a specified class and ID. When sent to a remote system there
cannot be a guarantee that the class exists, or there is an object of that
class and ID. Add an option to the configuration, possibly as a different
serialisation, so that messages are serialised by value, not reference, for
remote sends.

To Do

* Allow messages to be received from a buffered queue, particularly remotely.
* SimpleInterSSMQ to implement pull behaviour, not just push.
* Example in docs of using SimpleInterSSMQ to capture onPublish and
  have the changes re-published on a remote site.
* Provide for a single consumer of a queue, so that it can be guaranteed
  that no two messages are being sent simultaneously by different processes.