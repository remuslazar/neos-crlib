<?php
/**
 * Created by PhpStorm.
 * User: remuslazar
 * Date: 10.11.15
 * Time: 10:40
 */

namespace CRON\CRLib\Command;

use TYPO3\Flow\Annotations as Flow;

/**
 * @Flow\Scope("singleton")
 */
class NodeTypeCommandController extends \TYPO3\Flow\Cli\CommandController {

	/**
	 * @var \TYPO3\TYPO3CR\Domain\Service\NodeTypeManager
	 * @Flow\Inject
	 */
	protected $nodeTypeManager;

	/**
	 * Show all the Constraints for the specified NodeType
	 *
	 * @param string $nodeType
	 *
	 * @throws \TYPO3\Flow\Mvc\Exception\StopActionException
	 * @throws \TYPO3\TYPO3CR\Exception\NodeTypeNotFoundException
	 */
	public function showConstraintsCommand($nodeType) {

		if (!$this->nodeTypeManager->hasNodeType($nodeType)) {
			$this->outputLine('NodeType: %s not found.', [$nodeType]);
			$this->quit(1);
		}

		$nodeType = $this->nodeTypeManager->getNodeType($nodeType);

		$constraints = $nodeType->getConfiguration('constraints.nodeTypes');
		/** @var \TYPO3\TYPO3CR\Domain\Model\NodeType $super */
		foreach($nodeType->getDeclaredSuperTypes() as $super) {
			if ($superConstraints = $super->getConfiguration('constraints.nodeTypes')) {
				$constraints = array_merge($constraints, $superConstraints);
			}
		}

		echo json_encode($constraints, JSON_PRETTY_PRINT) . PHP_EOL;
	}

}