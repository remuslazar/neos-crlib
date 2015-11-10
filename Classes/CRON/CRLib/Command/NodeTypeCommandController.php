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

	/**
	 * Check for invalid NodeTypes in NodeTypes.*.yaml constraints
	 *
	 * @param string $childNode
	 *
	 */
	public function checkConstraintsCommand($childNode='') {
		$ret = [];
		foreach($this->nodeTypeManager->getNodeTypes() as $nodeType) {
			$constraints = $this->getConstraints($nodeType, $childNode);
			if (!$constraints) continue;

			$invalidNodeNames = [];
			foreach (array_keys($constraints) as $nodeTypeName) {
				if ($nodeTypeName == '*') continue;

				if (!$this->nodeTypeManager->hasNodeType($nodeTypeName)) {
					$invalidNodeNames[] = $nodeTypeName;
				}
			}
			if ($invalidNodeNames) {
				$ret[$nodeType->getName()] = $invalidNodeNames;
			}
		}

		echo json_encode($ret, JSON_PRETTY_PRINT) . PHP_EOL;
	}

	private function getConstraints(NodeType $nodeType, $childNode='', &$superTypes = []) {
		// prepend the childNodes.NAME. path if childNode given
		$configurationPath = ($childNode ? 'childNodes.'.$childNode.'.' : '') . 'constraints.nodeTypes';
		$constraints = $nodeType->getConfiguration($configurationPath);
		$superTypes[] = $nodeType->getName();

		/** @var NodeType $super */
		foreach($nodeType->getDeclaredSuperTypes() as $super) {
			if (in_array($super->getName(), $superTypes)) continue; // prevent loops
			if ($childConstraints = $this->getConstraints($super, $childNode, $superTypes)) {
				$constraints = array_merge($childConstraints, $constraints);
			}
		}

		return $constraints;
	}

	/**
	 * @param $nodeTypeName
	 *
	 * @return NodeType
	 * @throws \TYPO3\Flow\Mvc\Exception\StopActionException
	 * @throws \TYPO3\TYPO3CR\Exception\NodeTypeNotFoundException
	 */
	private function getNodeType($nodeTypeName) {
		if ($nodeTypeName !== null && !$this->nodeTypeManager->hasNodeType($nodeTypeName)) {
			$this->outputLine('NodeType: %s not found.', [$nodeTypeName]);
			$this->quit(1);
		}

		return $this->nodeTypeManager->getNodeType($nodeTypeName);
	}

	/**
	 * Show all the Constraints for the specified NodeType
	 *
	 * @param string $nodeType
	 * @param string $childNode show constraints for this child node, e.g. main
	 *
	 * @throws \TYPO3\Flow\Mvc\Exception\StopActionException
	 * @throws \TYPO3\TYPO3CR\Exception\NodeTypeNotFoundException
	 */
	public function showConstraintsCommand($nodeType=null, $childNode='') {

		$nodeTypes = $nodeType !== null ? [$this->getNodeType($nodeType)] :
			$this->nodeTypeManager->getNodeTypes(true);

		$ret = [];

		/** @var NodeType $nodeType */
		foreach ($nodeTypes as $nodeType) {
			$superTypes = [];
			$constraints = $this->getConstraints($nodeType, $childNode, $superTypes);
			if ($constraints) ksort($constraints);
			$ret[$nodeType->getName()] = $constraints;
			sort($superTypes);
			$ret['inheritedFrom'] = $superTypes;
		}

		ksort($ret);
		echo json_encode($ret, JSON_PRETTY_PRINT) . PHP_EOL;
	}

	/**
	 * Show all inherited nodes from the specific one
	 *
	 * @param string $nodeType
	 */
	public function showInheritanceCommand($nodeType) {

		$nodeType = $this->getNodeType($nodeType);

		$parents = [];
		foreach($this->nodeTypeManager->getNodeTypes() as $possibleParent) {
			/** @var NodeType $possibleParent */
			if (in_array($nodeType,$possibleParent->getDeclaredSuperTypes())) {
				$parents[] = $possibleParent->getName();
			}
		}

		if ($parents) ksort($parents);
		echo json_encode($parents, JSON_PRETTY_PRINT) . PHP_EOL;
	}

	private function printJSON($data) {
		echo json_encode($data, JSON_PRETTY_PRINT) . PHP_EOL;
	}

	private function printYAML($data) {
		// don't inline stuff, use 2 spaces for indenting
		echo \Symfony\Component\Yaml\Yaml::dump($data,8,2);
	}

	/**
	 * Show all allowed childnodes for a specific NodeType
	 *
	 * Basically this will use the same logic as the NEOS Backend in the "Create New" dialog. The output
	 * is YAML formatted, suitable for being copied-and-pasted onto the NodeTypes.yaml file, do disable
	 * specific items.
	 *
	 * @param string $nodeType
	 * @param string $childNode e.g. 'main', leave empty to get the constraints for the document node
	 */
	public function showAllowedChildnodesCommand($nodeType, $childNode='') {
		$ret = [];

		$nodeType = $this->getNodeType($nodeType);

		foreach ($this->nodeTypeManager->getNodeTypes() as $possibleNodeType) {
			/** @var NodeType $possibleNodeType */
			if ( ($childNode && $nodeType->allowsGrandchildNodeType($childNode, $possibleNodeType) ) ||
				(!$childNode && $nodeType->allowsChildNodeType($possibleNodeType)) ) {
				$ret[$possibleNodeType->getName()] = false;
			}
		}

		$this->printYAML([
			$nodeType->getName() => $childNode ? [
				'childNodes' => [
					$childNode => [
						'constraints' => [
							'nodeTypes' => $ret
						]
					]
				]
			] : [
				'constraints' => [
					'nodeTypes' => $ret
				]
			]
		]);
	}

}
