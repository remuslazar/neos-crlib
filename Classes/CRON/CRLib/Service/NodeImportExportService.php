<?php
/**
 * Created by PhpStorm.
 * User: lazarrs
 * Date: 30.07.15
 * Time: 19:42
 */

namespace CRON\CRLib\Service;
use Doctrine\DBAL\Types\DateType;
use TYPO3\Flow\Persistence\Doctrine\DataTypes\JsonArrayType;
use TYPO3\Flow\Annotations as Flow;
use TYPO3\TYPO3CR\Domain\Service\ImportExport\ImportExportPropertyMappingConfiguration;

/**
 *
 * @property ImportExportPropertyMappingConfiguration propertyMappingConfiguration
 * @Flow\Scope("singleton")
 */
class NodeImportExportService {

	const RESOURCES_DIR = 'res';

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

	public function initializeObject() {
		$this->propertyMappingConfiguration = new ImportExportPropertyMappingConfiguration(self::RESOURCES_DIR);
		if (!is_dir(self::RESOURCES_DIR)) mkdir(self::RESOURCES_DIR);
	}

	public function processJSONRecord($json) {
		$connection = $this->entityManager->getConnection();

		$jsonPropertiesDataTypeHandler = JsonArrayType::getType(JsonArrayType::FLOW_JSON_ARRAY);
		$dateDataTypeHandler = DateType::getType(DateType::DATETIME);
		$data = $this->convertJSONRecord($json);

		foreach ($data as $key => $value) {
			if (is_array($value)) {
				$data[$key] = $jsonPropertiesDataTypeHandler->convertToDatabaseValue($data[$key],
					$connection->getDatabasePlatform());
			} elseif ($value instanceof \DateTime) {
				$data[$key] = $dateDataTypeHandler->convertToDatabaseValue($data[$key],
					$connection->getDatabasePlatform());
			}
		}

		\TYPO3\Flow\var_dump($data);

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
				if (isset($value['date'])) {
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
	 * @param array $nodeData input data
	 * @throws \Exception
	 * @throws \TYPO3\Flow\Property\Exception
	 * @throws \TYPO3\Flow\Security\Exception
	 *
	 * @return array processed node data
	 */
	public function processNodeData(array $nodeData) {
		$data = [];
		foreach ($nodeData as $key => $value) {
			$newKey = substr($key, 2); // strip the n_ prefix
			switch ($key) {

			case 'n_properties':
				$properties = [];
				foreach ($value as $propertyName => $propertyValue) {
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
				$data[$newKey] = $properties;
				break;

			// ignore fields:
			case 'n_Persistence_Object_Identifier':
			case 'n_pathHash':
			case 'n_parentPathHash':
			case 'n_dimensionsHash':
				break;

			default:
				$data[$newKey] = $value;
			}
		}

		return $data;
	}

}
