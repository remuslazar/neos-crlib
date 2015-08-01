<?php
/**
 * Created by PhpStorm.
 * User: lazarrs
 * Date: 01.08.15
 * Time: 14:08
 */

namespace CRON\CRLib\Utility;


/**
 * @property bool commaIsNeeded
 */
class JSONArrayWriter {

	function __construct() {
		echo '[', "\n";
		$this->commaIsNeeded = false;
	}

	public function write($data) {
		if ($this->commaIsNeeded) echo ",\n"; else $this->commaIsNeeded = true;
		echo json_encode($data, JSON_PRETTY_PRINT);
	}

	function __destruct() {
		echo "\n", ']', "\n";
	}

}