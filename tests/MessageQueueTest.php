<?php

class MessageQueueTest extends SapphireTest {
	static $fixture_file = 'messagequeue/tests/MessageQueueTest.yml';

	/**
	 * @TODO
	 * *** Test rule matching:
	 *   - test multiple interfaces
	 *   - test lists of queue names in an interface
	 *   - test patterned queue names in an interface
	 *   - test passing over a patterned rule for another
	 * *** Test message sending:
	 *   - test sending plain message over non SQL interface
	 *   - test different encodings
	 *   - test for send error
	 * *** Test message receipt:
	 *   - test drop in rule execution
	 *   - test requeue in rule execution - how to attach errors
	 *   - test delivery via callback
	 *   - test delivery via MethodInvocationMessage and non-SQL interface
	 *   - test delivery of non-MethodInvocationMessage
	 *   - test send and delivery of plain text message
	 */

	/**
	 * Test MethodInvocationMessage class, independent from queueing.
	 */
	function testMethodInvocation() {
		// test the initial values
		$inv = new MethodInvocationMessage("MessageQueueTest", "doStaticMethod", "p1", 2);
		$inv->execute(null);
		$this->assertTrue(self::$testP1 == "p1", "Static method test executed, P1 test");
		$this->assertTrue(self::$testP2 == 2, "Static method test executed, P2 test");
	}

	function testMessageSend() {
		MessageQueue::send("testqueue", new MethodInvocationMessage("MessageQueueTest", "doStaticMethod", "p1", 2));
	}
/*
	function testQueueItem() {
		Queue::set_producer("QueueItem");

		// verify that queue is empty
		$consumer = singleton("QueueItem");
		$this->assertTrue(!$consumer->hasEvent(), "QueueItem consumer has no events queued to start");

		// create static event, and ensure that the event didn't make the state change immediately
		Queue::event("QueueTest", "doStaticMethod", "p1", 2);
		$this->assertTrue(self::$testP1 === null, "Static method test not executed immediately");

		// create object event, and check it hasn't been done in creation
		$obj = new QueueTestSample("A");
		Queue::event($obj, "doNonDOMethod", "B");
		$this->assertTrue(QueueTestSample::$testP1 === null, "Object method test not executed immediately");

		// create data object event
		$obj = new QueueTestSampleDO();
		$obj->prop1 = "p1";
		$obj->prop2 = 2;
		$obj->write();
		Queue::event($obj, "doDataObjectMethod", 3);
		$obj2 = DataObject::get_one("QueueTestSampleDO");
		$this->assertTrue($obj2 != null, "data object test wrote object");
		$this->assertTrue(!$obj2->result, "Data object method not executed immediately");

		// consume all events
		$processor = new Queue_Consume();
		$processor->all();

		// check static executed
		$this->assertTrue(self::$testP1 == "p1", "Static method test executed, P1 test");
		$this->assertTrue(self::$testP2 == 2, "Static method test executed, P2 test");

		// check object event executed
		$this->assertTrue(QueueTestSample::$testP1 == "AB", "Object method test executed ");

		// check dataobject event executed
		$obj = DataObject::get_one("QueueTestSampleDO", null, false);
		$this->assertTrue($obj != null, "Object method test object present");
		$this->assertTrue($obj->result == "p123", "Data object method test executed");
	}
*/
	private static $testP1 = null;
	private static $testP2 = null;
	static function doStaticMethod($p1 = null, $p2 = null) {
		//user_error("barf");
		self::$testP1 = $p1;
		self::$testP2 = $p2;
	}

}

/**
 * Test class for object method test. We will create an instance with variables.
 * The method when executed will set testP1 which is derived from the initial value
 * of the first parameter. This will test the object got serialised, and the static
 * gives us a way independent of the instance to see the event was called.
 */
/*class QueueTestSample extends Object {
	var $prop1 = null;
	static $testP1 = null;

	function __construct($p1 = null) {
		$this->prop1 = $p1;
	}

	function doNonDOMethod($p1 = null) {
		self::$testP1 = $this->prop1 . $p1;
	}
}

class QueueTestSampleDO extends DataObject {
	static $db = array(
		"prop1" => "Varchar",
		"prop2" => "Int",
		"result" => "Varchar"
	);

	function doDataObjectMethod($p1 = null) {
		$this->result = $this->prop1 . $this->prop2 . $p1;
		$this->write();
	}
}*/