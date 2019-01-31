<?php
/**
 * Created by PhpStorm.
 * User: remuslazar
 * Date: 2019-01-30
 * Time: 16:13
 */

namespace CRON\CRLib\Service;


use /** @noinspection PhpUnusedAliasInspection */
    TYPO3\Flow\Annotations as Flow;
use TYPO3\Neos\Domain\Model\Site;
use TYPO3\Neos\Domain\Service\ContentContext;
use TYPO3\TYPO3CR\Domain\Model\NodeInterface;
use TYPO3\TYPO3CR\Domain\Model\Workspace;

/**
 * Content Repository related logic
 *
 * @property string sitePath
 * @property string workspaceName
 * @property Site currentSite
 *
 * @Flow\Scope("singleton")
 */
class CRService
{

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
     * @var \TYPO3\TYPO3CR\Domain\Repository\WorkspaceRepository
     */
    protected $workspaceRepository;

    /**
     * @Flow\Inject
     * @var \TYPO3\TYPO3CR\Domain\Service\NodeTypeManager
     */
    protected $nodeTypeManager;

    /**
     * @Flow\Inject
     * @var \TYPO3\Neos\Service\NodeNameGenerator
     */
    protected $nodeNameGenerator;

    /**
     * @var ContentContext
     */
    public $context;

    /** @var NodeInterface */
    public $rootNode;

    /**
     * Setup and configure the context to use, take care of the arguments like user name etc.
     *
     * @param string $workspace workspace name, defaults to the live workspace
     *
     * @throws \Exception
     */
    public function setup($workspace = 'live')
    {
        // validate user name, use the live workspace if null
        $this->workspaceName = $workspace;

        /** @noinspection PhpUndefinedMethodInspection */
        if (!$this->workspaceRepository->findByName($this->workspaceName)->count()) {
            throw new \Exception(sprintf('Workspace "%s" is invalid', $this->workspaceName));
        }

        $this->context = $this->contextFactory->create([
            'workspaceName' => $this->workspaceName,
            'currentSite' => $this->currentSite,
            'invisibleContentShown' => true,
            'inaccessibleContentShown' => true
        ]);

        $this->rootNode = $this->context->getNode($this->sitePath);
    }

    /**
     * @param NodeInterface $document
     * @param $url
     *
     * @return string
     *
     * @throws \Exception
     */
    public function getNodePathForURL(NodeInterface $document, $url) {
        $parts = explode('/', $url);
        foreach ($parts as $segment) {
            if (!$segment) { continue; }
            $document = $this->getChildDocumentByURIPathSegment($document, $segment);
        }

        return $document->getPath();
    }

    /**
     * @param NodeInterface $document
     * @param $pathSegment
     *
     * @return NodeInterface
     * @throws \Exception
     */
    private function getChildDocumentByURIPathSegment(NodeInterface $document, $pathSegment) {
        $found = array_filter($document->getChildNodes('TYPO3.Neos:Document'),
            function (NodeInterface $document) use ($pathSegment ){
                return $document->getProperty('uriPathSegment') === $pathSegment;
            }
        );

        if (count($found) === 0) {
            throw new \Exception(sprintf('Could not find any child document for URL path segment: "%s" on "%s',
                $pathSegment,
                $document->getPath()
            ));
        }
        return array_pop($found);
    }

    /**
     * Fetches the associated NoteType object for the specified node type
     *
     * @param string $type NodeType name, e.g. 'YPO3.Neos.NodeTypes:Page'
     *
     * @return \TYPO3\TYPO3CR\Domain\Model\NodeType
     *
     * @throws \Exception
     */
    public function getNodeType($type) {
        if (!$this->nodeTypeManager->hasNodeType($type)) {
            throw new \Exception('specified node type is not valid');
        }

        return $this->nodeTypeManager->getNodeType($type);
    }

    /**
     * Sets the node properties
     *
     * @param NodeInterface $node
     * @param string $propertiesJSON JSON string of node properties
     *
     * @throws \Exception
     */
    public function setNodeProperties($node, $propertiesJSON)
    {
        $data = json_decode($propertiesJSON, true);

        if ($data === null) {
            throw new \Exception('could not decode JSON data');
        }

        foreach ($data as $name => $value) {
            if (preg_match('/^path:\/\//', $value)) {
                $path = str_replace('path://', '', $value);
                $value = $this->rootNode->getNode($path);
                if (!$value) {
                    throw new \Exception('could not find path reference');
                }
            } else if (preg_match('/^\d{4}-\d{2}-\d{2}/', $value)) {
                $value = new \DateTime($value);
            }
            $node->setProperty($name, $value);
        }
    }

    /**
     * Fetches an existing node by URL
     *
     * @param string $url URL of the node, e.g. '/news/my-news'
     *
     * @return NodeInterface
     * @throws \Exception
     */
    public function getNodeForURL($url)
    {
        return $this->context->getNode($this->getNodePathForURL($this->rootNode, $url));
    }

    /**
     * Fetches an existing node by relative path
     *
     * @param string $path relative path of the page
     *
     * @return NodeInterface
     * @throws \Exception
     */
    public function getNodeForPath($path)
    {
        return $this->context->getNode($this->sitePath . $path);
    }

    /**
     * Publishes the configured workspace
     *
     * @throws \Exception
     */
    public function publish()
    {
        $liveWorkspace = $this->workspaceRepository->findByIdentifier('live');
        if (!$liveWorkspace) {
            throw new \Exception('Could not find the live workspace.');
        }
        /** @var Workspace $liveWorkspace */
        $this->context->getWorkspace()->publish($liveWorkspace);
    }

    /**
     * @param NodeInterface $parentNode
     * @param string $idealNodeName
     *
     * @return string
     */
    public function generateUniqNodeName($parentNode, $idealNodeName = null) {
        return $this->nodeNameGenerator->generateUniqueNodeName($parentNode, $idealNodeName);
    }

    /**
     * @throws \Exception
     */
    public function initializeObject()
    {
        /** @var Site $currentSite */
        $currentSite = $this->siteRepository->findFirstOnline();
        if (!$currentSite) {
            throw new \Exception('No site found');
        }
        $this->sitePath = '/sites/' . $currentSite->getNodeName();
        $this->currentSite = $currentSite;
    }

}
