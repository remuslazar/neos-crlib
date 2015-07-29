<?php
/**
 * Created by PhpStorm.
 * User: lazarrs
 * Date: 29.07.15
 * Time: 09:46
 */

namespace CRON\CRLib\Service;

use Doctrine\ORM\Query;
use Doctrine\ORM\QueryBuilder;
use TYPO3\Flow\Annotations as Flow;
use TYPO3\TYPO3CR\Domain\Service\Context;

/**
*
* @Flow\Scope("singleton")
*/

class NodeQueryService {

	/**
	 * Doctrine's Entity Manager. Note that "ObjectManager" is the name of the related
	 * interface ...
	 *
	 * @Flow\Inject
	 * @var \Doctrine\Common\Persistence\ObjectManager
	 */
	protected $entityManager;

	/**
	 * @param Context $context
	 * @return Query
	 */
	public function getAllNodesDataQuery($context) {

		$queryBuilder = $this->entityManager->createQueryBuilder();
		/** @var QueryBuilder $queryBuilder */
		$queryBuilder->select('n')
		             ->from('TYPO3\TYPO3CR\Domain\Model\NodeData', 'n')
		             ->where('n.workspace IN (:workspaces)')
		             ->setParameter('workspaces', [$context->getWorkspace()->getName()]);

		return $queryBuilder->getQuery();
	}

	private function getQueryBuilder($types, $path, $searchTerm, $workspace) {
		$queryBuilder = $this->entityManager->createQueryBuilder();
		/** @var QueryBuilder $queryBuilder */
		$queryBuilder->select('n')
		             ->from('TYPO3\TYPO3CR\Domain\Model\NodeData', 'n')
		             ->where('n.workspace IN (:workspaces)')
		             ->setParameter('workspaces', $workspace);

		if ($types) {
			$queryBuilder->andWhere('n.nodeType IN (:includeNodeTypes)')
			             ->setParameter('includeNodeTypes', $types);
		}

		if ($path) {
			$queryBuilder->andWhere('n.path LIKE :path')
			             ->setParameter('path', $path.'%');
		}

		if ($searchTerm) {
			$queryBuilder->andWhere('n.properties LIKE :term')
			             ->setParameter('term', '%'.$searchTerm.'%');
		}

		$queryBuilder->orderBy('n.path', 'ASC');

		return $queryBuilder;
	}

	/**
	 * Gets the count of all records matching the criteria
	 *
	 * @param null $nodeTypeFilter csv list of NodeTypes to filter
	 * @param null $path filter by path
	 * @param string $searchTerm search term
	 * @param string $workspace workspace, defaults to the live workspace
	 *
	 * @return int
	 */
	public function getCount($nodeTypeFilter=null, $path=null, $searchTerm='', $workspace='live') {
		$queryBuilder = $this->getQueryBuilder($nodeTypeFilter, $path, $searchTerm, $workspace);
		$queryBuilder->select('COUNT(n)');
		return (int)$queryBuilder->getQuery()->getSingleScalarResult();
	}

	/**
	 * Gets a NodeData query obj.
	 *
	 * @param null $nodeTypeFilter csv list of NodeTypes to filter
	 * @param null $path filter by path
	 * @param string $searchTerm search term
	 * @param string $workspace workspace, defaults to the live workspace
	 *
	 * @return Query
	 */
	public function findQuery($nodeTypeFilter=null, $path=null, $searchTerm='', $workspace='live') {
		$queryBuilder = $this->getQueryBuilder($nodeTypeFilter, $path, $searchTerm, $workspace);
		return $queryBuilder->getQuery();
	}

}