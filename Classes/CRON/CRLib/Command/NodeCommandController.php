<?php
namespace CRON\CRLib\Command;

/*                                                                        *
 * This script belongs to the TYPO3 Flow package "CRON.DazSite".          *
 *                                                                        *
 *                                                                        */

use CRON\CRLib\Utility\NodeIterator;
use Doctrine\ORM\Query;
use TYPO3\Flow\Annotations as Flow;
use TYPO3\Neos\Domain\Model\Site;
use TYPO3\TYPO3CR\Domain\Model\NodeInterface;
use TYPO3\TYPO3CR\Domain\Service\Context;
use TYPO3\TYPO3CR\Domain\Service\NodeTypeManager;

/**
 * @property Context context
 * @property string sitePath
 * @Flow\Scope("singleton")
 */
class NodeCommandController extends \TYPO3\Flow\Cli\CommandController {

	/**
	 * @Flow\Inject
	 * @var NodeTypeManager
	 */
	protected $nodeTypeManager;

	/**
	 * @Flow\Inject
	 * @var \TYPO3\Neos\Service\NodeNameGenerator
	 */
	protected $nodeNameGenerator;

	/**
	 * @Flow\Inject
	 * @var \TYPO3\TYPO3CR\Domain\Repository\NodeDataRepository
	 */
	protected $nodeDataRepository;

	/**
	 * @Flow\Inject
	 * @var \TYPO3\TYPO3CR\Domain\Service\ContextFactoryInterface
	 */
	protected $contextFactory;

	/**
	 * @Flow\Inject
	 * @var \TYPO3\Neos\Domain\Repository\SiteRepository
	 */
	protected $siteRepository;

	/**
	 * @Flow\Inject
	 * @var \CRON\CRLib\Service\NodeQueryService
	 */
	protected $nodeQueryService;

	/**
	 * @throws \Exception
	 */
	public function initializeObject() {
		/** @var Site $currentSite */
		/** @noinspection PhpUndefinedMethodInspection */
		$currentSite = $this->siteRepository->findFirstOnline();
		if (!$currentSite) throw new \Exception('No site found');
		$this->sitePath = '/sites/' . $currentSite->getNodeName();
		$this->context = $this->contextFactory->create([
			'currentSite' => $currentSite,
			'invisibleContentShown' => TRUE,
			'inaccessibleContentShown' => TRUE
		]);
	}

	/**
	 * @param string|null $typeFilter
	 * @param bool $subtypes
	 *
	 * @return string[]|null node type names
	 */
	private function getTypes($typeFilter, $subtypes=true) {
		if ($typeFilter) {
			$types = explode(',', $typeFilter);
			$tmp = [];
			foreach ($types as $type) {
				$tmp[$type] = 1;
				if ($subtypes) {
					$tmp = array_merge($tmp, $this->nodeTypeManager->getSubNodeTypes($type));
				}
			}
			return array_keys($tmp);
		}
		return $typeFilter;
	}

	/**
	 * Find TYPO3CR nodes
	 *
	 * @param string $path Start path, relative to the site root
	 * @param string $type NodeType filter (csv list)
	 * @param string $search Search term (regex when used with $property, else string)
	 * @param string $property Limit the matching to this property (if unset search in the full json blob)
	 * @param bool $useSubtypes Include inherited node types
	 * @param bool $count Display only the count and not the record data itself
	 */
	public function findCommand($path=null, $type=null, $search='', $property='',
	                            $useSubtypes=true, $count=false) {
		$path = $path ? $this->getPath($path) : null;
		$type = $this->getTypes($type, $useSubtypes);

		if ($count) {
			if ($property) {
				// unfortunately we can't use the getCount() method here
				$count = 0;
				$nodes = $this->nodeQueryService->findQuery($type, $path)->iterate(null, Query::HYDRATE_ARRAY);
				foreach($nodes as $node) {
					$node = $node[0];
					if ($this->matchTermInProperty($node, $search, $property)) { $count++; }
				}
			} else {
				$count = $this->nodeQueryService->getCount($type, $path, $search);
			}

			$this->outputLine('%d node(s).', [$count]);
		} else {
			$query = $this->nodeQueryService->findQuery($type, $path, $property ? null : $search);
			$nodes = $query->iterate(null, Query::HYDRATE_ARRAY);
			foreach($nodes as $node) {
				$node = $node[0];
				if (!$property || $this->matchTermInProperty($node, $search, $property)) {
					$this->displayNodes([$node]);
				}
			}
		}
	}

	private function matchTermInProperty($node, $term, $propertyName) {
		if (is_array($node)) {
			return isset($node['properties'][$propertyName]) &&
			preg_match('/'.$term.'/i', $node['properties'][$propertyName]);
		} else {
			return $node->hasProperty($propertyName) &&
			preg_match('/'.$term.'/i', $node->getProperty($propertyName));
		}
	}

	/**
	 * Displays node data for an array of nodes (one line per node)
	 *
	 * @param NodeInterface[]|array[] $nodes
	 * @param string $indend
	 * @param string $propertyName which property to display, defaults to title
	 */
	protected function displayNodes($nodes, $indend = '', $propertyName='title') {
		/** @var NodeInterface $node */
		foreach($nodes as $node) {
			$this->outputFormatted('%s%s [%s] "%s" (%s)',[
				$indend,
				is_array($node) ? $node['path'] : $node->getPath(),
				is_array($node) ? $node['nodeType'] : (string)$node->getNodeType(),
				is_array($node) ? (isset($node['properties'][$propertyName]) ?
					$node['properties'][$propertyName] : '' ) : $node->getProperty($propertyName),
				is_array($node) ? $node['identifier']  : $node->getIdentifier()
			]);
		}
	}

	/**
	 * Dump all data of the node specified by the uuid
	 *
	 * @param string $uuid uuid of the node, e.g. 4b3d2a07-6d1f-5311-3431-cc80d41c3622
	 */
	public function dumpCommand($uuid) {
		$node = $this->context->getNodeByIdentifier($uuid);

		if (!$node) {
			$this->outputLine('Not found.');
			$this->quit(1);
		}

		$this->outputLine();
		$this->outputLine('%s', [$node->getNodeType()]);
		$this->outputLine();

		foreach($node->getProperties() as $propertyName => $value) {
			try {
				printf('%-25s: "%s"', $propertyName, $value === null ? 'NULL' : $value);
				$this->outputLine();
			} catch (\Exception $e) {
			}
		}
		$this->outputLine();
	}

	/**
	 * @param NodeInterface[] $nodes nodes to delete
	 * @throws \TYPO3\Flow\Mvc\Exception\StopActionException
	 */
	private function removeAll($nodes) {
		foreach ($nodes as $node) {
			$this->nodeDataRepository->removeAllInPath($node->getPath());
			$node->remove();
		}
	}

	/**
	 * Remove a single node and its child nodes
	 *
	 * This command will remove the node found in the given path and all its child nodes
	 *
	 * @param string $path relative to site root, e.g. /my/path/to/folder
	 * @return void
	 */
	public function removeCommand($path) {
		$path = $this->getPath($path);
		$node = $this->context->getNode($path);
		$this->removeAll([$node]);
	}

	/**
	 * Remove all nodes below a given path
	 *
	 * This command will remove all nodes below a given path, dont care about workspaces etc.
	 * For example using the path /sites/dazsite/news/my-folder will delete the pages BELOW
	 * this path, e.g.
	 *
	 * /sites/dazsite/news/my-folder/my-page1
	 * /sites/dazsite/news/my-folder/my-page2
	 * ..
	 *
	 * @param string $path relative to site root, e.g. /my/path/to/folder
	 * @param string $nodeType filter by NodeType, e.g. Vendor.Name:MyNodeType or Document
	 * @param bool $force force processing, don't list the nodes to be deleted
	 * @return void
	 */
	public function removeAllCommand($path=NULL, $nodeType=NULL, $force=false) {
		if ($path) { $path = $this->getPath($path); }

		$nodesToDelete = null;
		if ($path && !$nodeType) {
			$node = $this->context->getNode($path);
			$nodesToDelete = $node->getChildNodes('TYPO3.Neos:Document');
		} elseif ($nodeType) {
			$nodesToDelete = $this->nodeDataRepository->findByParentAndNodeTypeInContext(
				$path ? $path : $this->sitePath,
				$nodeType,
				$this->context,
				TRUE
			);
		}

		if ($nodesToDelete) {
			if ($force) {
				$this->removeAll($nodesToDelete);
			} else {
				$this->outputLine();
				$this->displayNodes($nodesToDelete);
				$this->outputLine();
				if ($this->output->askConfirmation(sprintf('Delete %d node(s) AND all child nodes? (y/n)', count($nodesToDelete)))) {
					$this->removeAll($nodesToDelete);
				}
			}
		} else {
			$this->outputLine('No nodes found.');
		}
	}

	/**
	 * @param string $path relative or absolute path
	 * @return string $path absolute path
	 */
	private function getPath($path) {
		// strip the leading / from $path, if present
		$path = preg_replace('/^\//','', $path);
		$path = $path ? join('/', [$this->sitePath, $path]) : $this->sitePath;
		if (!$this->context->getNode($path)) {
			$this->outputLine('Path: %s not valid', [$path]);
			$this->sendAndExit(1);
		}
		return $path;
	}

	/**
	 * Creates a new Node on the specified path
	 *
	 * @param string $title The node title (the node name will be derived from it)
	 * @param string $path The (folder) path, relative to the current site root
	 * @param null $type Node Type, defaults to TYPO3.Neos.NodeTypes:Page
	 * @param null $uuid UUID of the new node, e.g. 22f90a17-9adc-2462-2971-bdb5eaf170b7
	 */
	public function createCommand($title, $path, $type=null, $uuid=null) {
		$path = $this->getPath($path);
		$nodeType = $this->nodeTypeManager->getNodeType($type ? $type : 'TYPO3.Neos.NodeTypes:Page');

		$folderNode = $this->context->getNode($path);

		$newNode = $folderNode->createNode(
			$this->nodeNameGenerator->generateUniqueNodeName($folderNode, $title),
			$nodeType,
			$uuid
		);
		$newNode->setProperty('title', $title);

		$this->outputLine('%s created using the node Identifier %s', [$newNode, $newNode->getIdentifier()]);
	}

}