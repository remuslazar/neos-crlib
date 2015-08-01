<?php
namespace CRON\CRLib\Command;

/*                                                                        *
 * This script belongs to the TYPO3 Flow package "CRON.DazSite".          *
 *                                                                        *
 *                                                                        */

use CRON\CRLib\Utility\JSONFileReader;
use CRON\CRLib\Utility\NodeQuery;
use Doctrine\DBAL\Query\QueryException;
use Doctrine\ORM\NonUniqueResultException;
use Doctrine\ORM\NoResultException;
use Doctrine\ORM\Query;
use TYPO3\Flow\Annotations as Flow;
use TYPO3\Flow\Exception;
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
	 * @Flow\Inject
	 * @var \CRON\CRLib\Service\NodeImportExportService
	 */
	protected $nodeImportExportService;

	/**
	 * @throws \Exception
	 */
	public function initializeObject() {
		/** @var Site $currentSite */
		/** @noinspection PhpUndefinedMethodInspection */
		$currentSite = $this->siteRepository->findFirstOnline();
		if ($currentSite) {
			$this->sitePath = '/sites/' . $currentSite->getNodeName();
			$this->context = $this->contextFactory->create([
				'currentSite' => $currentSite,
				'invisibleContentShown' => TRUE,
				'inaccessibleContentShown' => TRUE
			]);
		} else {
			$this->context = $this->contextFactory->create([
				'invisibleContentShown' => TRUE,
				'inaccessibleContentShown' => TRUE
			]);
		}
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
	 * Export all nodes to JSON. Linked resources are saved in a relative directory "res".
	 *
	 * @param string $filename
	 * @param string $path limit to this path only
	 * @param string $type NodeType filter (csv list)
	 */
	public function exportCommand($filename=null, $path=null, $type=null) {
		$path = $path ? $this->getPath($path) : null;
		$types = $this->getTypes($type);

		$nodeQuery = new NodeQuery($types, $path);

		$count = $nodeQuery->getCount();
		if (!$count) {
			$this->outputLine('Error: result set is empty.');
			$this->quit(1);
		}

		$fp = null; if ($filename) { $fp = fopen($filename, 'w'); }

		$progress = $fp && ($count > 1000); if ($progress) { $this->output->progressStart($count); $step = $count / 100; }

		$iterable = $nodeQuery->getQuery()->iterate(NULL, Query::HYDRATE_SCALAR);
		$i = 0; foreach ($iterable as $row) {
			$data = $this->nodeImportExportService->convertNodeDataForExport($row[0]);
			$json = json_encode($data) . "\n";
			if ($fp) fwrite($fp, $json); else echo $json;
			$i++; if ($progress && $i % $step === 0) $this->output->progressAdvance($step);
		}
		if ($fp) fclose($fp);

		if ($progress) { $this->output->progressSet($count); $this->output->progressFinish(); }
	}

	/**
	 * Import node data from a JSON file
	 *
	 * @param string $filename JSON file on local filesystem
	 * @param string $path limit the processing on that path only
	 * @param bool $info dont process anything, show just some infos about the input data
	 * @param boolean $dryRun perform a dry run
	 */
	public function importCommand($filename, $path=null, $info=false, $dryRun=false) {
		$iterator = new JSONFileReader($filename);

		if ($info) $dryRun = true;

		// dry run to get the count of the records to import later on
		$count = 0;
		$documentCount=0;
		$missingNodeTypes=[];

		foreach ($iterator as $data) {
			$nodePath = $data['path'];
			if ($path && strpos($nodePath, $path) !== 0) continue;

			if ($info) {
				$sitePath = null;
				$depth = substr_count($nodePath, '/');
				if ($depth == 2) {
					$sitePath = $data['path'];
					$this->outputLine('site: %s', [$sitePath]);
				}
				$nodeType = $data['nodeType'];
				if ($this->nodeTypeManager->hasNodeType($nodeType)) {
					$nodeType = $this->nodeTypeManager->getNodeType($nodeType);
					if ($nodeType->isOfType('TYPO3.Neos:Document')) {
						$documentCount++;
					}
				} else {
					$missingNodeTypes[$nodeType] = true;
				}
			}
			$count++;
		}

		$missingNodeTypes = array_flip($missingNodeTypes);

		if ($info) {
			if ($missingNodeTypes) {
				$this->outputLine('WARN: missing NodeTypes: %s', [implode(',', $missingNodeTypes)]);
			}
			$this->outputLine('%d nodes (%d pages) available for import.', [
				$count, $documentCount]);
			$this->quit(0);
		}

		$progress = $count > 1000; if ($progress) { $this->output->progressStart($count); $step = $count / 100; }

		$i=0;
		$importedCount=0;
		foreach ($iterator as $data) {
			$nodePath = $data['path'];
			if ($path && strpos($nodePath, $path) !== 0) continue;
			if (!$dryRun) {
				$parentPath = $data['parentPath'];
				if ($parentPath && $parentPath != '/' && $parentPath != '/sites') {
					$importedCount++;
					$this->nodeImportExportService->processJSONRecord($data);
				}
			}
			$i++; if ($progress && $i % $step === 0) $this->output->progressAdvance($step);
		}
		if ($progress) {
			$this->output->progressSet($count); $this->output->progressFinish();
		} elseif ($importedCount) {
			$this->outputLine('Import process done, %d node(s) imported.', [$importedCount]);
		}
	}

	/**
	 * Prune all nodes in all workspaces
	 */
	public function pruneCommand() {
		$em = $this->nodeQueryService->getEntityManager();
		$em->getConnection()->setAutoCommit(true);
		$em->getConnection()->executeQuery('delete from typo3_typo3cr_domain_model_nodedata where parentpath not in ("","/sites")');
	}

	/**
	 * Find TYPO3CR nodes
	 *
	 * @param string $path Start path, relative to the site root
	 * @param string $type NodeType filter (csv list)
	 * @param string $search Search string for exact match or regex like e.g. '/^myprefix/i'
	 * @param string $property Limit the matching to this property (if unset search in the full json blob)
	 * @param bool $useSubtypes Include inherited node types
	 * @param int $limit limit the result set
	 * @param bool $count Display only the count and not the record data itself
	 * @param bool $json Output data JSON formatted (one record per line)
	 */
	public function findCommand($path=null, $type=null, $search='', $property='',
	                            $useSubtypes=true, $limit=null, $count=false, $json=false) {
		$path = $path ? $this->getPath($path) : null;
		$type = $this->getTypes($type, $useSubtypes);

		$nodeQuery = new NodeQuery($type, $path);

		if ($count) {
			if ($property) {
				// unfortunately we can't use the getCount() method here
				$count = 0;
				$iterable = $nodeQuery->getQuery()->iterate(null,Query::HYDRATE_SCALAR);
				foreach($iterable as $node) {
					$node = $node[0];
					if ($this->matchTermInProperty($node, $search, $property)) { $count++; }
				}
			} else {
				$nodeQuery->addSearchTermConstraint($search);
				$count = $nodeQuery->getCount();
			}

			$this->outputLine('%d node(s).', [$count]);
		} else {
			if (!$property) $nodeQuery->addSearchTermConstraint($search);
			$query = $nodeQuery->getQuery();

			if ($limit !== null) $query->setMaxResults($limit);

			$iterable = $query->iterate(NULL, Query::HYDRATE_SCALAR);
			foreach ($iterable as $row) {
				$node = $row[0];
				if (!$property || $this->matchTermInProperty($node, $search, $property)) {
					if ($json) {
						echo json_encode($node), "\n";
					} else {
						$this->displayNodes([$node]);
					}
				}
			}
		}
	}

	private function listNodes($path, $type, $level, $indend = '') {
		if ($childNodes = $this->context->getNode($path)->getChildNodes($type)) {
			foreach ($childNodes as $childNode) {
				$this->displayNodes([$childNode], $indend);
				if ($level > 0) {
					$this->listNodes($childNode->getPath(), $type, $level - 1, $indend . '  ');
				}
			}
		}
	}

	/**
	 * List all (document) nodes on a given path for the current site
	 *
	 * To list all available nodes and not apply the NodeType filter, use "null" as the type parameter.
	 *
	 * @param string $path relative to the site root. Defaults to /
	 * @param string $type node type filter, e.g. 'CRON.DazSite:*', defaults to TYPO3.Neos:Document.
	 * @param int $depth recursion depth, defaults to 0
	 * @return void
	 */
	public function listCommand($path = '/', $type = 'TYPO3.Neos:Document', $depth = 0) {
		$path = $this->getPath($path);
		$this->listNodes($path, $type, $depth);
	}

	private function reportMemoryUsage() {
		file_put_contents('php://stderr', sprintf(' > mem: %.1f MB'."\n", memory_get_peak_usage()/1024/1024));
	}

	private function matchTermInProperty($node, $term, $propertyName) {
		if (is_array($node)) {
			return isset($node['n_properties'][$propertyName]) &&
			$this->searchTermMatch($term, $node['n_properties'][$propertyName]);
		} else {
			return $node->hasProperty($propertyName) &&
			$this->searchTermMatch($term, $node->getProperty($propertyName));
		}
	}

	/**
	 * @param string $term string for exact match or regex
	 * @param string $value
	 *
	 * @return bool|int
	 */
	private function searchTermMatch($term, $value) {
		if (strpos($term, '/') === 0) {
			return preg_match($term, $value);
		} else {
			return $term == $value;
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
			$this->outputFormatted('%s%s (%s) "%s"',[
				$indend,
				is_array($node) ? $node['n_path'] : $node->getPath(),
				substr(is_array($node) ? $node['n_identifier'] : $node->getIdentifier(), 0,8),
				is_array($node) ? (isset($node['n_properties'][$propertyName]) ?
					$node['n_properties'][$propertyName] : '' ) : $node->getProperty($propertyName)
			]);
		}
	}

	/**
	 * Dump all data of the node specified by the uuid JSON formatted
	 *
	 * @param string $uuid uuid of the node, e.g. 4b3d2a07-6d1f-5311-3431-cc80d41c3622 OR the node path
	 * @param bool $json
	 * @throws Exception
	 * @throws NoResultException
	 */
	public function dumpCommand($uuid, $json=false) {

		if (strpos($uuid, '/') !== false) {
			// it looks like a path
			$uuid = $this->getPath($uuid);
			if ($node = $this->context->getNode($uuid)) {
				$uuid = $node->getIdentifier();
			}
		}

		$nodeQuery = new NodeQuery();
		$nodeQuery->addIdentifierConstraint($uuid);
		try {
			$result = $nodeQuery->getQuery()->getSingleResult(Query::HYDRATE_ARRAY);
		} catch (NonUniqueResultException $e) {
			throw new Exception('Non unique result');
		}

		echo json_encode($result, JSON_PRETTY_PRINT),"\n";
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
		if (strpos($path, '/sites') === 0) return $path;

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