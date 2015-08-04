<?php
/**
 * Created by PhpStorm.
 * User: lazarrs
 * Date: 30.07.15
 * Time: 19:42
 */

namespace CRON\CRLib\Service;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Type;
use TYPO3\Flow\Persistence\Doctrine\DataTypes\JsonArrayType;
use TYPO3\Flow\Annotations as Flow;
use TYPO3\TYPO3CR\Domain\Model\NodeData;
use TYPO3\TYPO3CR\Domain\Service\ImportExport\ImportExportPropertyMappingConfiguration;
use TYPO3\Flow\Utility\Algorithms;

/**
 *
 * @property ImportExportPropertyMappingConfiguration propertyMappingConfiguration
 * @property int convertCount
 * @Flow\Scope("singleton")
 */
class NodeImportExportService {

	const RESOURCES_DIR = 'res';
	const EXPORT_BATCH_SIZE = 200;

	/**
	 * @Flow\Inject
	 * @var \TYPO3\Flow\Object\ObjectManagerInterface
	 */
	protected $objectManager;

	/**
	 * @Flow\Inject
	 * @var \TYPO3\Flow\Persistence\PersistenceManagerInterface
	 */
	protected $persistenceManager;

	/**
	 * @Flow\Inject
	 * @var \TYPO3\Flow\Property\PropertyMapper
	 */
	protected $propertyMapper;

	/**
	 * @Flow\Inject
	 * @var \Doctrine\Common\Persistence\ObjectManager
	 */
	protected $entityManager;

	/**
	 * @Flow\Inject
	 * @var \TYPO3\TYPO3CR\Domain\Service\NodeTypeManager
	 */
	protected $nodeTypeManager;

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

	public function initializeObject() {
		$this->propertyMappingConfiguration = new ImportExportPropertyMappingConfiguration(self::RESOURCES_DIR);
		if (!is_dir(self::RESOURCES_DIR)) mkdir(self::RESOURCES_DIR);
		$this->convertCount = 0;
	}

	protected function clearState() {
		$this->persistenceManager->persistAll();
		$this->persistenceManager->clearState();
	}

	/**
	 * @param Connection $connection
	 * @param array $data
	 * @param array $type
	 */
	private function executeUpdate($connection, $data, $type) {

		// TODO: use the mapping configuration from the ORM layer
		$data['sortingindex'] = $data['index']; unset($data['index']);

		$fields = array_keys($data);
		$values = array_map(function($field) { return ':' . $field;  }, $fields);

		$statement = 'INSERT INTO typo3_typo3cr_domain_model_nodedata (' . implode(',', $fields) . ') ' .
			'VALUES (' . implode(',', $values) . ')';

		$connection->executeUpdate($statement, $data, $type);
	}

	public function processJSONRecord($json) {
		$connection = $this->entityManager->getConnection();

		$jsonPropertiesDataTypeHandler = JsonArrayType::getType(JsonArrayType::FLOW_JSON_ARRAY);
		$data = $this->convertJSONRecord($json);
		$type = [];

		$data['dimensionsHash'] = NodeData::sortDimensionValueArrayAndReturnDimensionsHash($data['dimensionValues']);

		// calculate the parentPath
		$data['parentPath'] = substr($data['path'], 0, strrpos($data['path'], '/'));

		foreach ($data as $key => $value) {
			// generate the path hash values
			if (in_array($key, ['path','parentPath'])) {
				$data[$key.'Hash'] = md5($value);
			}
			if (is_array($value)) {
				$data[$key] = $jsonPropertiesDataTypeHandler->convertToDatabaseValue($data[$key],
					$connection->getDatabasePlatform());
			} elseif ($value instanceof \DateTime) {
				$type[$key] = Type::DATETIME;
			} elseif (is_bool($value)) {
				$type[$key] = \PDO::PARAM_BOOL;
			}
		}
		$data['Persistence_Object_Identifier'] = Algorithms::generateUUID();
		$data['workspace'] = 'live'; // importing data in other workspaces than the live workspace not supported

		$this->executeUpdate($connection, $data, $type);
	}

	/**
	 * @param array $data
	 * @return array
	 * @throws \Exception
	 * @throws \TYPO3\Flow\Property\Exception
	 * @throws \TYPO3\Flow\Security\Exception
	 *
	 * @return array processed data
	 */
	private function convertJSONRecord(array $data) {
		$nodeData = [];
		foreach ($data as $key => $value) {
			if (is_array($value)) {
				if (array_key_exists('date', $value) &&
					array_key_exists('timezone', $value) && is_string($value['date'])) {
					$nodeData[$key] = new \DateTime($value['date'], new \DateTimeZone($value['timezone']));
				} elseif (isset($value['__flow_object_type'])) {
					$nodeData[$key] = $this->propertyMapper->convert(
						$value['__value'],
						$value['__flow_object_type'],
						$this->propertyMappingConfiguration
					);
				} else {
					// recurse
					$nodeData[$key] = $this->convertJSONRecord($value);
				}
			} else {
				// primitive type like string/null/boolean
				$nodeData[$key] = $value;
			}
		}

		return $nodeData;
	}

	/**
	 * Convert the node data array from Doctrine HYDRATE_SCALAR to an array representation suited for exporting
	 * to JSON. Process properties using propertyMapper->convert() and save assets to a relative directory on
	 * the local filesystem.
	 *
	 * @param array $nodeData input data
	 * @throws \Exception
	 * @throws \TYPO3\Flow\Property\Exception
	 * @throws \TYPO3\Flow\Security\Exception
	 *
	 * @return array processed node data
	 */
	public function convertNodeDataForExport(array $nodeData) {
		if ($this->convertCount++ % self::EXPORT_BATCH_SIZE === 0) $this->clearState();
		$data = [];
		foreach ($nodeData as $key => $value) {
			$newKey = substr($key, 2); // strip the n_ prefix
			switch ($key) {

			case 'n_properties':
				$data[$newKey] = $this->convertPropertiesToArray($value);
				break;

			// ignore fields:
			case 'n_Persistence_Object_Identifier':
			case 'n_pathHash':
			case 'n_parentPathHash':
			case 'n_dimensionsHash':
			case 'n_parentPath':
				break;

			default:
				$data[$newKey] = $value;
			}
		}

		return $data;
	}

	/**
	 * Sets the Resource Load/Save Path for the propertyMapper
	 *
	 * @param string|null $path
	 */
	public function setResourcePath($path=null) {
		$this->propertyMappingConfiguration = new ImportExportPropertyMappingConfiguration($path);
	}

	/**
	 * @param array $originalProperties
	 * @return array
	 *
	 * @throws \Exception
	 * @throws \TYPO3\Flow\Property\Exception
	 * @throws \TYPO3\Flow\Security\Exception
	 */
	public function convertPropertiesToArray($originalProperties) {
		$properties = [];
		foreach ($originalProperties as $propertyName => $propertyValue) {
			if (is_object($propertyValue) && !$propertyValue instanceof \DateTime) {
				$newValue = [];
				$objectIdentifier = $this->persistenceManager->getIdentifierByObject($propertyValue);
				if ($objectIdentifier !== NULL) {
					$newValue['__identifier'] = $objectIdentifier;
				}
				if ($propertyValue instanceof \Doctrine\ORM\Proxy\Proxy) {
					$className = get_parent_class($propertyValue);
				} else {
					$className = get_class($propertyValue);
				}
				$newValue['__flow_object_type'] = $className;
				$newValue['__value'] = $this->propertyMapper->convert($propertyValue, 'array',
					$this->propertyMappingConfiguration);

				$properties[$propertyName] = $newValue;
			} else {
				$properties[$propertyName] = $propertyValue;
			}
		}
		return $properties;
	}

	/**
	 * Filter the records for the import process.
	 *
	 * @param array $data node data from the JSON
	 * @param string $sourcePath source path constraint
	 * @param bool $matchChildDocuments match only child documents (trailing /)
	 *
	 * @return bool record should be imported
	 */
	public function shouldImportRecord($data, $sourcePath, $matchChildDocuments) {
		$path = $data['path'];
		if ($matchChildDocuments) {
			if (strpos($path . '/', $sourcePath) !== 0) return false;
			$relativePath = substr($path, strlen($sourcePath)+1);
			if (strpos($relativePath, '/') !== false) return true;
			return !empty($data['properties']['uriPathSegment']);
		} else {
			return strpos($path, $sourcePath) === 0;
		}
	}

}
