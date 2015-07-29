<?php
/**
 * Created by PhpStorm.
 * User: lazarrs
 * Date: 29.07.15
 * Time: 10:48
 */
namespace CRON\CRLib\Utility;

use Doctrine\ORM\Query;
use TYPO3\Flow\Annotations as Flow;
use TYPO3\TYPO3CR\Domain\Model\NodeData;
use TYPO3\TYPO3CR\Domain\Model\NodeInterface;
use TYPO3\TYPO3CR\Domain\Service\Context;

/**
 * @property Context context
 * @property \Doctrine\ORM\Query query
 * @property \Doctrine\ORM\Internal\Hydration\IterableResult iterator
 */
class NodeIterator implements \Iterator {

	/**
	 * @Flow\Inject
	 * @var \TYPO3\TYPO3CR\Domain\Factory\NodeFactory
	 */
	protected $nodeFactory;

	/**
	 * @param Context $context
	 * @param Query $query
	 */
	function __construct(Context $context, Query $query) {
		$this->context = $context;
		$query->useResultCache(false);
		$query->useQueryCache(false);
		$this->query = $query;
	}

	public function initializeObject() {
		$this->iterator = $this->query->iterate();
	}

	/**
	 * @param NodeData $nodeData
	 * @return \TYPO3\TYPO3CR\Domain\Model\NodeInterface
	 * @throws \TYPO3\TYPO3CR\Exception\NodeConfigurationException
	 */
	private function getNode(NodeData $nodeData) {
		$node = $this->nodeFactory->createFromNodeData($nodeData, $this->context);
		$this->nodeFactory->reset();
		return $node;
	}

	/**
	 * (PHP 5 &gt;= 5.0.0)<br/>
	 * Return the current element
	 *
	 * @link http://php.net/manual/en/iterator.current.php
	 * @return NodeInterface
	 */
	public function current() {
		$nodeData = $this->iterator->current();
		$node = $this->getNode($nodeData[0]);
		// detach the entity from ORM to save memory
		$this->query->getEntityManager()->detach($nodeData[0]);
		return $node;
	}

	/**
	 * (PHP 5 &gt;= 5.0.0)<br/>
	 * Move forward to next element
	 *
	 * @link http://php.net/manual/en/iterator.next.php
	 * @return void Any returned value is ignored.
	 */
	public function next() {
		$this->iterator->next();
	}

	/**
	 * (PHP 5 &gt;= 5.0.0)<br/>
	 * Return the key of the current element
	 *
	 * @link http://php.net/manual/en/iterator.key.php
	 * @return mixed scalar on success, or null on failure.
	 */
	public function key() {
		return $this->iterator->key();
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
		return $this->iterator->valid();
	}

	/**
	 * (PHP 5 &gt;= 5.0.0)<br/>
	 * Rewind the Iterator to the first element
	 *
	 * @link http://php.net/manual/en/iterator.rewind.php
	 * @return void Any returned value is ignored.
	 */
	public function rewind() {
		$this->iterator->rewind();
	}

}