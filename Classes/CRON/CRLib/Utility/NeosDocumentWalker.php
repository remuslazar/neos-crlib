<?php
/**
 * Created by PhpStorm.
 * User: remuslazar
 * Date: 02.05.18
 * Time: 23:45
 */

namespace CRON\CRLib\Utility;

use TYPO3\TYPO3CR\Domain\Model\NodeInterface;

/**
 * @property int limit
 * @property NodeInterface rootNode
 * @property NodeInterface[] nodes
 */
class NeosDocumentWalker
{
    public function __construct(NodeInterface $rootNode)
    {
        $this->rootNode = $rootNode;
    }

    private function walk(NodeInterface $node) {

        foreach ($node->getChildNodes('TYPO3.Neos:Document') as $childNode) {
            if ($this->limit && count($this->nodes) >= $this->limit) {
                return;
            }
            $this->walk($childNode);
        }
        if ($this->limit && count($this->nodes) >= $this->limit) { return; }

        $this->nodes[] = $node;
    }

    /**
     * Walk all nodes recursively and returns the leaves first
     *
     * @param int $limit
     *
     * @return array|NodeInterface[]
     */
    public function getNodes($limit=0) {
        $this->limit = $limit;
        $this->nodes = [];
        $this->walk($this->rootNode);

        return $this->nodes;
    }
}