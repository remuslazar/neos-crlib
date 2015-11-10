<?php
/**
 * Created by PhpStorm.
 * User: remuslazar
 * Date: 10.11.15
 * Time: 10:40
 */

namespace CRON\CRLib\Command;

use TYPO3\Flow\Annotations as Flow;
use TYPO3\TYPO3CR\Domain\Model\NodeType;

/**
 * @Flow\Scope("singleton")
 */
class NodeTypeCommandController extends \TYPO3\Flow\Cli\CommandController {

	/**
	 * @var \TYPO3\TYPO3CR\Domain\Service\NodeTypeManager
	 * @Flow\Inject
	 */
	protected $nodeTypeManager;

	private function getConstraints(NodeType $nodeType, $inherited=false, $childNode) {
		// prepend the childNodes.NAME. path if childNode given
		$configurationPath = ($childNode ? 'childNodes.'.$childNode.'.' : '') . 'constraints.nodeTypes';
		$constraints = $nodeType->getConfiguration($configurationPath);
		if ($inherited) {
			/** @var NodeType $super */
			foreach($nodeType->getDeclaredSuperTypes() as $super) {
				if ($superConstraints = $super->getConfiguration($configurationPath)) {
					$constraints = array_merge($constraints, $superConstraints);
				}
			}
		}
		if (is_array($constraints)) ksort($constraints);

		return $constraints;
	}

	/**
	 * Show all the Constraints for the specified NodeType
	 *
	 * @param string $nodeType
	 * @param boolean $detail show detailed information about the inheritance
	 * @param string $childNode show constraints for this child node, e.g. main
	 *
	 * @throws \TYPO3\Flow\Mvc\Exception\StopActionException
	 * @throws \TYPO3\TYPO3CR\Exception\NodeTypeNotFoundException
	 */
	public function showConstraintsCommand($nodeType=null, $detail=false, $childNode='') {

		if ($nodeType !== null && !$this->nodeTypeManager->hasNodeType($nodeType)) {
			$this->outputLine('NodeType: %s not found.', [$nodeType]);
			$this->quit(1);
		}

		$nodeTypes = $nodeType !== null ? [$this->nodeTypeManager->getNodeType($nodeType)] :
			$this->nodeTypeManager->getNodeTypes(true);

		$ret = [];

		/** @var NodeType $nodeType */
		foreach ($nodeTypes as $nodeType) {
			if ($detail) {
				$ret[$nodeType->getName()] = $this->getConstraints($nodeType, false, $childNode);
				foreach($nodeType->getDeclaredSuperTypes() as $super) {
					if ($superConstraints =  $this->getConstraints($super, false, $childNode)) {
						/** @var NodeType $super */
						$ret['superTypes'][$super->getName()] = $superConstraints;
					}
				}
			} else {
				$ret[$nodeType->getName()] = $this->getConstraints($nodeType, true, $childNode);
			}
		}

		ksort($ret);
		echo json_encode($ret, JSON_PRETTY_PRINT) . PHP_EOL;
	}

}
