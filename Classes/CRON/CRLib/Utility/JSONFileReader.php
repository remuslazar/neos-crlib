<?php
/**
 * Created by PhpStorm.
 * User: lazarrs
 * Date: 30.07.15
 * Time: 14:20
 */

namespace CRON\CRLib\Utility;
use TYPO3\Flow\Annotations as Flow;

/**
 * @property resource fp
 * @property null data
 * @property int key
 */
class JSONFileReader implements \Iterator {

	/**
	 * Open a JSON file (one record per line) and returns an Iterable object
	 *
	 * @param string $filename JSON file on the local filesystem
     * @throws \Exception
	 */
	public function __construct($filename) {
		$this->fp = fopen($filename, 'r');
		if (!$this->fp) throw new \Exception(sprintf('Cannot open file %s', $filename));
		$this->key = 0;
		$this->data = null; // current record
	}

	/**
	 * (PHP 5 &gt;= 5.0.0)<br/>
	 * Return the current element
	 *
	 * @link http://php.net/manual/en/iterator.current.php
	 * @return mixed Can return any type.
	 */
	public function current() {
		return json_decode($this->data, true); // array style
	}

	/**
	 * (PHP 5 &gt;= 5.0.0)<br/>
	 * Move forward to next element
	 *
	 * @link http://php.net/manual/en/iterator.next.php
	 * @return void Any returned value is ignored.
	 */
	public function next() {
		$this->data = fgets($this->fp);
		$this->key++;
	}

	/**
	 * (PHP 5 &gt;= 5.0.0)<br/>
	 * Return the key of the current element
	 *
	 * @link http://php.net/manual/en/iterator.key.php
	 * @return mixed scalar on success, or null on failure.
	 */
	public function key() {
		return $this->key;
	}

	/**
	 * (PHP 5 &gt;= 5.0.0)<br/>
	 * Checks if current position is valid
	 *
	 * @link http://php.net/manual/en/iterator.valid.php
	 * @return boolean The return value will be casted to boolean and then evaluated.
	 * Returns true on success or false on failure.
	 */
	public function valid() {
		return $this->data !== false;
	}

	/**
	 * (PHP 5 &gt;= 5.0.0)<br/>
	 * Rewind the Iterator to the first element
	 *
	 * @link http://php.net/manual/en/iterator.rewind.php
	 * @return void Any returned value is ignored.
	 */
	public function rewind() {
		rewind($this->fp);
		$this->key = 0;
		$this->data = fgets($this->fp);
	}

	function __destruct() {
		if ($this->fp) fclose($this->fp);
	}
}
