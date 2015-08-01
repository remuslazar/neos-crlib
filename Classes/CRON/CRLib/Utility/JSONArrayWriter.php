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
 * @property int jsonEncodeOptions
 */
class JSONArrayWriter {

	/**
	 * @param bool $pretty
	 */
	function __construct($pretty=false) {
		echo '[';
		$this->commaIsNeeded = false;
		$this->jsonEncodeOptions = $pretty ? JSON_PRETTY_PRINT : 0;
	}

	public function write($data) {
		if ($this->commaIsNeeded) echo ",\n"; else $this->commaIsNeeded = true;
		echo json_encode($data, $this->jsonEncodeOptions);
	}

	function __destruct() {
		echo ']', "\n";
	}

}