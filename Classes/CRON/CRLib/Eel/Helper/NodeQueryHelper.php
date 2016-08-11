<?php
/**
 * Created by PhpStorm.
 * User: remuslazar
 * Date: 10.02.16
 * Time: 12:08
 */

namespace CRON\CRLib\Eel\Helper;

use TYPO3\Eel\ProtectedContextAwareInterface;
use TYPO3\Flow\Annotations as Flow;
use TYPO3\Flow\Persistence\QueryInterface;
use TYPO3\TYPO3CR\Domain\Model\NodeInterface;

class NodeQueryHelper implements ProtectedContextAwareInterface
{

    /**
     * @Flow\Inject
     * @var \TYPO3\TYPO3CR\Domain\Repository\NodeDataRepository
     */
    protected $nodeDataRepository;

    /**
     * @var \TYPO3\Flow\Persistence\QueryInterface
     */
    protected $query;

    /**
     * @var array query constraints
     */
    private $constraints = [];

    /**
     * Internal helper function
     *
     * @param $constraint
     */
    private function matching($constraint)
    {
        $this->constraints[] = $constraint;
    }

    /**
     * Filter the result set by the specified NodeTypes
     *
     * @param string|array $type
     *
     * @return NodeQueryHelper
     */
    public function type($type)
    {
        if (is_string($type)) {
            $type = [$type];
        }

        $this->matching($this->query->in('nodeType', $type));

        return $this;
    }

    /**
     * Constrain the result set for the supplied site
     *
     * @param NodeInterface $site
     *
     * @return NodeQueryHelper
     */
    public function site($site)
    {
        // for now we just get the workspace from the supplied site node
        // TODO: multi-site compliance

        $this->query = $this->nodeDataRepository->createQuery();
        $this->constraints = [];

        $this->matching($this->query->equals('removed', false));

        $workspace = $site->getContext()->getWorkspace();
        $this->matching($this->query->equals('workspace', $workspace));

        return $this;
    }

    /**
     * Count of matching documents
     *
     * @return int
     */
    public function count()
    {
        $this->query->matching($this->query->logicalAnd($this->constraints));

        return $this->query->count();
    }

    /**
     * Internal helper function to get the maximum lifetime
     *
     * @param string $property hiddenBeforeDateTime or hiddenAfterDateTime
     *
     * @return int
     */
    private function _cacheLifetime($property)
    {
        $constraints = $this->constraints;
        $query = clone $this->query;

        $now = new \DateTime();
        $constraints[] = $query->greaterThan($property, $now);
        $query->matching($query->logicalAnd($constraints));
        $query->setOrderings([$property => QueryInterface::ORDER_ASCENDING]);

        /** @var NodeInterface $res */
        if ($res = $query->execute()->getFirst()) {

            $date = null;

            switch ($property) {
                case 'hiddenBeforeDateTime';
                    $date = $res->getHiddenBeforeDateTime();
                    break;

                case 'hiddenAfterDateTime';
                    $date = $res->getHiddenAfterDateTime();
                    break;
            }

            //if ($date === null) return null;
            // if $date is null, something is really, really wrong, let it fail!

            return $date->getTimestamp() - $now->getTimestamp();
        }

        return null;
    }

    /**
     * Minimum of all allowed cache lifetimes of all matching nodes
     * If none are set or all values are in the past it will evaluate to null.
     *
     * @param int $minimumLifetime minimum lifetime in seconds
     *
     * @return int|null
     */
    public function cacheLifetime($minimumLifetime = null)
    {

        $min = [];

        if ($minimumLifetime) {
            $min[] = $minimumLifetime;
        }
        if ($val = $this->_cacheLifetime('hiddenBeforeDateTime')) {
            $min[] = $val;
        }
        if ($val = $this->_cacheLifetime('hiddenAfterDateTime')) {
            $min[] = $val;
        }

        return count($min) > 0 ? min($min) : null;
    }

    /**
     * All methods are considered safe, i.e. can be executed from within Eel
     *
     * @param string $methodName
     *
     * @return boolean
     */
    public function allowsCallOfMethod($methodName)
    {
        return true;
    }

}
