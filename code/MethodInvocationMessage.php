<?php

/**
 * Class to encapsulate a static, member or DataObject member method invocation.
 * Can be serialized and executed later.
 *
 * @author Mark Stephens <mark@silverstripe.com>
 */

class MethodInvocationMessage implements MessageExecutable {
	var $invokeType;
	var $objectOrClass;
	var $id;
	var $method;
	var $args = null;

	/**
	 * Constructor for method call.
	 * @param * $objOrClass		If this is a string, it denotes the name of a class, and $method is a static method.
	 *							If this is a DataObject, the method will execute on the same DataObject. The DataObject
	 *								instance passed to the constructor must have been persisted (will have an ID that can be re-fetched).
	 *								$method will be an instance method called on the re-fetched DataObject.
	 *							If this is any other object, $method is an instance method called on the object.
	 * @param String $method
	 * @param * varargs			Any addition arguments are to be passed to the method when called.
	 */
	function __construct($objOrClass, $method) {
		if (!is_object($objOrClass) && !is_string($objOrClass)) throw new Exception("MessageMethodInvocation expects object or class");

		if (is_string($objOrClass)) {
			$this->invokeType = "static";
			$this->objectOrClass = $objOrClass;
		}
		elseif ($objOrClass instanceof DataObject) {
			$this->invokeType = "dataobject";
			$this->objectOrClass = get_class($objOrClass);
			$this->id = $objOrClass->ID;
		}
		else {
			$this->invokeType = "object";
			$this->objectOrClass = $objOrClass;
		}
		$this->method = $method;
		$this->args = array_slice(func_get_args(), 2);
	}

	/**
	 * user_errors of these types will be ignored during execution. If an error is not of one
	 * of these types, it will be thrown as an exception.
	 */
	static private $ignored_error_types = array(
		E_WARNING,
		E_NOTICE,
		E_CORE_WARNING,
		E_COMPILE_WARNING,
		E_USER_WARNING,
		E_USER_NOTICE,
		E_STRICT
	);

	/**
	 * Execute this method invocation object. If there are problems, throws an exception,
	 * including if user_error is called during the call (suppressed user_error, but detects
	 * if a user_error was ignored).
	 *
	 * @param MessageFrame $msgframe		The message received
	 * @param Map $config					Interface configuration if called
	 *										from MessageQueue.
	 * @return whatever the underlying method returns
	 */
	function execute(&$msgFrame, &$config) {
		$lastError = error_get_last();
		switch ($this->invokeType) {
			case "static":
				$res = @call_user_func_array(array($this->objectOrClass, $this->method), $this->args);
				break;
			case "dataobject":
				$obj = DataObject::get_by_id($this->objectOrClass, $this->id);
				if (!$obj) throw new Exception("Can not execute non-existent Data object {$this->objectOrClass}->{$this->id}");
				$res = @call_user_func_array(array($obj, $this->method), $this->args);
				break;
			case "object":
				$res = @call_user_func_array(array($this->objectOrClass, $this->method), $this->args);
				break;
			default:
				throw new Exception("Invalid method invocation type '{$this->invokeType}'");
		}

		// OK, see if there has been an error, because we have suppressed the calls with @.
		// We need to compare it with $lastError, which was the status before we made the call,
		// and only barf if the error is new. Sigh. Oh, ignore warnings and notices.
		$err = error_get_last();

		if ($err &&
			!in_array($err['type'], self::$ignored_error_types) &&
			(!$lastError ||
			 $err["type"] != $lastError["type"] ||
			 $err["message"] != $lastError["message"] ||
			 $err["file"] != $lastError["file"] ||
			 $err["line"] != $lastError["line"])) {
			throw new Exception("Error detected in method invocation:" . print_r($err, true));
		}

		return $res;
	}
}

?>
