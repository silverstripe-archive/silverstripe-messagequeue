<?php

class MessageQueueSample extends Object implements TestOnly {
	var $prop1 = null;
	static $testP1 = null;

	function __construct($p1 = null) {
		$this->prop1 = $p1;
	}

	function doNonDOMethod($p1 = null) {
		self::$testP1 = $this->prop1 . $p1;
	}
}

?>
