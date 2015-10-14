<?php
namespace CRON\CRLib\Command;

/*                                                                        *
 * This script belongs to the TYPO3 Flow package "CRON.DazSite".          *
 *                                                                        *
 *                                                                        */

use CRON\CRLib\Utility\JSONArrayWriter;
use CRON\CRLib\Utility\JSONFileReader;
use CRON\CRLib\Utility\NodeIterator;
use CRON\CRLib\Utility\NodeQuery;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Query;
use TYPO3\Flow\Annotations as Flow;
use TYPO3\Flow\Object\ObjectManagerInterface;
use TYPO3\Media\Domain\Model\Asset;
use TYPO3\Neos\Domain\Model\Site;
use TYPO3\TYPO3CR\Command\NodeCommandControllerPlugin;
use TYPO3\TYPO3CR\Domain\Model\NodeData;
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
	 * @var ObjectManagerInterface
	 */
	protected $objectManager;

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

		$fp = null; if ($filename) { $fp = fopen($filename, 'w'); chdir(dirname($filename)); }

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
	 * Import node data from a JSON file.
	 *
	 * The default source path is the root path of the current site. Use a trailing slash to select only
	 * the document child nodes of the path.
	 *
	 * @param string $filename JSON file on local filesystem
	 * @param string $path limit the processing on that path only (use only absolute paths, /sites/..).
	 * @param string $destinationPath import the data on this path (defaults to --path, if set, else /)
	 * @param bool $dir do list all the documents available in the backup
	 * @param int $maxDepth the max. depth for the --dir command
	 * @param boolean $dryRun perform a dry run
	 * @param bool $yes Skip the confirmation step prior to the import process
	 *
	 * @throws \Exception
	 * @throws \TYPO3\Flow\Mvc\Exception\StopActionException
	 * @throws \TYPO3\TYPO3CR\Exception\NodeTypeNotFoundException
	 */
	public function importCommand($filename, $path='', $destinationPath='', $dir=false,
	                              $maxDepth=0, $dryRun=false, $yes=false) {

		if ($dryRun) {
			$this->outputLine('!!! DRY RUN MODE');
		}

		// we match only child documents if the source path has a trailing /
		$matchChildDocuments = false;
		if (preg_match('/\/$/', $path)) {
			$path = preg_replace('/\/$/','',$path);
			$matchChildDocuments = true;
		}

		if (!$path) $path = $this->sitePath;
		if (!$destinationPath) $destinationPath = $path;

		$iterator = new JSONFileReader($filename);
		chdir(dirname($filename));

		if ($dir) {
			foreach ($iterator as $data) {
				if (!$this->nodeImportExportService->shouldImportRecord($data, $path, $matchChildDocuments)) continue;
				$depth = substr_count($data['path'], '/');
				if (!$maxDepth || $depth <= $maxDepth) {
					if (isset($data['properties']['uriPathSegment'])) {
						$this->outputLine('%s "%s"', [$data['path'], $data['properties']['title']]);
					}
				}
			}
			$this->quit(0);
		}

		$this->outputLine('Source path: %s', [$path . ($matchChildDocuments ? '/*' : '')]);
		$this->outputLine('Destination path: %s', [$destinationPath]);

		// dry run to get the count of the records to import later on
		$count = 0;
		$documentCount=0;
		$missingNodeTypes=[];

		foreach ($iterator as $data) {
			if (!$this->nodeImportExportService->shouldImportRecord($data, $path, $matchChildDocuments)) continue;
			$sitePath = null;
			$depth = substr_count($data['path'], '/');
			if ($depth == 2) {
				$sitePath = $data['path'];
				$this->outputLine('Site: %s', [$sitePath]);
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
			$count++;
		}

		$missingNodeTypes = array_keys($missingNodeTypes);

		if ($missingNodeTypes) {
			$this->outputLine('WARNING: missing NodeTypes:');
			foreach ($missingNodeTypes as $missingNodeType) {
				$this->outputLine(' * %s', [$missingNodeType]);
			}
		}

		// check if the import path exists
		if (!$this->context->getNode($destinationPath)) {
			$this->outputLine('ERROR: Destination path "%s" does not exist.', [$destinationPath]);
			$this->quit(1);
		}
		$this->outputLine('%d node(s) / %d document(s) selected for the import process.', [$count, $documentCount]);

		if (!$yes && !$this->output->askConfirmation('Proceed with the import process?')) {
			$this->quit(0);
		}

		$progress = $count > 100; if ($progress) { $this->output->progressStart($count); $step = $count / 100; }

		$importedCount=0;
		$sourcePathLastPathSegmentWithLeadingSlash = $matchChildDocuments ? '' : substr($path, strrpos($path, '/'));
		foreach ($iterator as $data) {
			if (!$this->nodeImportExportService->shouldImportRecord($data, $path, $matchChildDocuments)) continue;

			// map the path to dest path if needed
			$data['path'] = str_replace($path,
				$destinationPath . $sourcePathLastPathSegmentWithLeadingSlash, $data['path']);

			try {
				// do the import
				if (!$dryRun) $this->nodeImportExportService->processJSONRecord($data);
			} catch (\Exception $e) {
				$this->outputLine();
				$this->outputLine('ERROR: Record on path %s could not be imported',
					[$data['path']]);
				$this->outputLine();
				throw $e;
			}

			$importedCount++;
			if ($progress && $importedCount % $step === 0) $this->output->progressAdvance($step);
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
		/** @var EntityManager $em */
		$em = $this->objectManager->get('Doctrine\Common\Persistence\ObjectManager');
		$em->getConnection()->setAutoCommit(true);
		$em->getConnection()->executeQuery(
			'delete from typo3_typo3cr_domain_model_nodedata where parentpath not in ("","/sites")');
	}

	/**
	 * Find TYPO3CR nodes
	 *
	 * @param string $uuid Search by UUID (can be an UUID prefix)
	 * @param string $path Match by path prefix (can be abs. or relative to the site root)
	 * @param string $type NodeType filter (csv list, e.g. TYPO3.Neos:Document)
	 * @param bool $useSubtypes Also include inherited NodeTypes (default)
	 * @param string $search Search string for exact match or regex like e.g. '/^myprefix/i'
	 * @param string $property Limit the matching to this property (if unset search in the full JSON blob with LIKE %term%)
	 * @param int $limit Limit the result set
	 * @param bool $count Display only the count and not the record data itself
	 * @param bool $json Output data JSON formatted (one record per line)
	 * @param bool $map Perform properties mapping and export resources in the res folder
	 * @param string $workspace workspace, defaults to live
	 */
	public function findCommand($uuid='', $path=null, $type=null, $useSubtypes=true, $search='', $property='',
	                            $limit = NULL, $count = FALSE, $json = FALSE, $map = FALSE, $workspace = 'live') {
		$path = $path ? $this->getPath($path) : null;
		$type = $this->getTypes($type, $useSubtypes);

		$nodeQuery = new NodeQuery($type, $path, NULL, $workspace);

		if ($uuid) $nodeQuery->addIdentifierConstraint($uuid);

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
			//$this->nodeImportExportService->setResourcePath();
			$jsonWriter = $json ? new JSONArrayWriter(true) : null;
			foreach ($iterable as $row) {
				$node = $row[0];
				if (!$property || $this->matchTermInProperty($node, $search, $property)) {
					if ($json) {
						if ($map) {
							$node['n_properties'] = $this->nodeImportExportService->convertPropertiesToArray(
								$node['n_properties']);
						}
						$jsonWriter->write($node);
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
	 * Remove all nodes
	 *
	 * @param string $path Match by path prefix (can be abs. or relative to the site root)
	 * @param string $uuid Search by UUID (can be an UUID prefix)
	 * @param bool $force force processing, don't list the nodes to be deleted
	 * @param bool $childsOnly delete only the document child nodes and not the node itself
	 * @return void
	 */
	public function removeCommand($path='', $uuid='', $force=false, $childsOnly=false) {
		if ($path) $path = $this->getPath($path);

		if (!$path && !$uuid) {
			$this->outputLine('Either the --uuid OR --path argument is required.');
			$this->quit(1);
		}

		$nodeQuery = new NodeQuery();

		if ($uuid) {
			$nodeQuery->addIdentifierConstraint($uuid);
			if ($nodeQuery->getCount() !== 1) {
				$this->outputLine('No (unique) node with UUID %s found',[$uuid]);
				$this->quit(1);
			} else {
				/** @var NodeData $nodeData */
				$nodeData = $nodeQuery->getQuery()->getSingleResult();
				$path = $nodeData->getPath();
			}
		} else {
			$nodeQuery->addPathConstraint($path);
			if ($nodeQuery->getCount() === 0) {
				$this->outputLine('No node on path %s found', [$path]);
				$this->quit(1);
			} elseif ($childsOnly && $nodeQuery->getCount() === 1) {
				$this->outputLine('No document childnodes on path %s found', [$path]);
				$this->quit(1);
			}
		}

		if ($childsOnly) $path .= '/'; // hack

		$documentNodesToDelete = new NodeQuery($this->getTypes('TYPO3.Neos:Document'), $path);

		if ((($count=$documentNodesToDelete->getCount()) > 0) && !$force) {
			foreach($documentNodesToDelete->getQuery()->iterate(null, Query::HYDRATE_SCALAR) as $result) {
				$this->displayNodes([$result[0]]);
			}
			if (!$this->output->askConfirmation(sprintf('Delete %d documents(s) AND all child nodes? (y/n)', $count))) {
				return;
			}
		}

		$deleteQuery = new NodeQuery(null, $path);
		$count = $deleteQuery->deleteAll();
		$this->outputLine('%d node(s) bulk deleted.', [$count]);
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
	 * @param string $type Node Type, defaults to TYPO3.Neos.NodeTypes:Page
	 * @param string $uuid UUID of the new node, e.g. 22f90a17-9adc-2462-2971-bdb5eaf170b7
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

	/**
	 * Perform a TYPO3CR node repair operation in the live workspace
	 *
	 * The difference between calling this command and the typo3cr:node:repair is that this command
	 * will skip the URI Path generation, which runs prior to the repair tasks and is problematic while
	 * having too much nodes, because it doesn't scale well nor use the NodeType filter.
	 *
	 * @param string $nodeType Only handle this node type
	 * @param boolean $dryRun Don't do any changes
	 * @param boolean $cleanup Perform cleanup tasks (the cleanup tasks will NOT respect the nodetype filter..)
	 */
	public function repairCommand($nodeType='', $dryRun=false, $cleanup=false) {
		/** @var NodeCommandControllerPlugin $plugin */
		$plugin = $this->objectManager->get('TYPO3\TYPO3CR\Command\NodeCommandControllerPlugin');
		$plugin->invokeSubCommand('repair', $this->output,
			$nodeType ? $this->nodeTypeManager->getNodeType($nodeType) : null,
			'live', $dryRun, $cleanup);
	}

	/**
	 * Cleanup for TYPO3CR nodes
	 *
	 * Currently this command checks only if all the assets are available and delete all orphaned assets.
	 *
	 * @param string $path limit to this path only
	 * @param string $type NodeType filter (csv list)
	 * @param boolean $dryRun
	 */
	public function cleanupCommand($path=null, $type=null, $dryRun=false) {
		$path = $path ? $this->getPath($path) : null;
		$type = $this->getTypes($type);

		$nodeQuery = new NodeQuery($type, $path);
		$count = $nodeQuery->getCount();

		$this->output->progressStart($count);

		foreach (new NodeIterator($nodeQuery->getQuery()) as $node) {
			foreach($node->getProperties() as $name => $property) {
				try {
					if ($property instanceof Asset) {
						// this will fail if the asset is orphaned
						$property->getIdentifier();
					}
				} catch (\Exception $e) {
					if ($dryRun) {
						$this->outputLine('Property %s in %s references a missing %s record.',
							[$name, $node, \Doctrine\Common\Util\ClassUtils::getRealClass(get_class($property))]);
					} else {
						$node->removeProperty($name); // nullify the property to fix
					}
				}
			}
			$this->output->progressAdvance();
		}

		$this->output->progressFinish();
	}

	/**
	 * Move the node tree from src to dst
	 *
	 * @param string $src source path
	 * @param string $dst destination path
	 */
	public function moveCommand($src, $dst) {
		$sourcePath = $this->getPath($src);
		$destinationPath = $this->getPath($dst);

		$sourceNode = $this->context->getNode($sourcePath);
		if (!$sourceNode) {
			$this->outputLine('ERROR: Invalid source path %s', [$sourcePath]);
			$this->quit(1);
		}
		$destinationNode = $this->context->getNode($destinationPath);
		if (!$destinationNode) {
			$this->outputLine('ERROR: Invalid destination path %s', [$destinationPath]);
			$this->quit(1);
		}
		$sourceNode->moveInto($destinationNode);
	}

	/**
	 * Perform a (simple) migration
	 *
	 * This is an alternative to the typo3cr:node:migrate command, designed for scalability and performance
	 * when dealing with big sites.
	 *
	 * @param string $type NodeType, e.g. VENDOR.Site:MyNodeType
	 * @param string $property PropertyName, e.g. date
	 * @param string $class Transformation class name, e.g. VENDOR\Site\Migration\Transformations\MyTransformation
	 */
	public function simplemigrateCommand($type, $property, $class) {
		$migration = $this->objectManager->get($class);

		if (!$migration instanceof \TYPO3\TYPO3CR\Migration\Transformations\AbstractTransformation) {
			$this->outputLine('ERROR: PHP-Class %s must be an instance of \TYPO3\TYPO3CR\Migration\Transformations\AbstractTransformation.', [$class]);
			$this->quit(1);
		}

		$migration->property = $property;

		$nodeQuery = new NodeQuery($type);
		$this->output->progressStart($nodeQuery->getCount());
		foreach ( (new NodeIterator($nodeQuery->getQuery(), [], false)) as $node) {
			$nodeData = $node->getNodeData();
			if ($migration->isTransformable($nodeData)) {
				$migration->execute($nodeData);
				if (!$this->nodeDataRepository->isInRemovedNodes($nodeData)) {
					$this->nodeDataRepository->update($nodeData);
				}
			}
			$this->output->progressAdvance();
		}
		$this->output->progressFinish();
	}

}
