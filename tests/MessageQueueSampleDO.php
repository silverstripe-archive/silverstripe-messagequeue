<?php

class MessageQueueSampleDO extends DataObject /*implements TestOnly*/ {
	static $db = array(
		"prop1" => "Varchar",
		"prop2" => "Int",
		"result" => "Varchar"
	);

	function doDataObjectMethod($p1 = null) {
		$this->result = $this->prop1 . $this->prop2 . $p1;
		$this->write();
	}
}

?>
