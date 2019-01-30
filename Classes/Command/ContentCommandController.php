<?php
/**
 * Created by PhpStorm.
 * User: remuslazar
 * Date: 2019-01-30
 * Time: 16:06
 */

namespace CRON\CRLib\Command;

use /** @noinspection PhpUnusedAliasInspection */
    TYPO3\Flow\Annotations as Flow;

use TYPO3\Flow\Cli\CommandController;

class ContentCommandController extends CommandController
{

    /**
     * @Flow\Inject
     * @var \CRON\CRLib\Service\CRService
     */
    protected $cr;

    /**
     * List the content of a specified page
     * @param string $url URL of the page, e.g. '/news'
     * @param string $collection collection node name, defaults to 'main'
     * @param string $user use this user's workspace (use 0 to use the live workspace)
     */
    public function listCommand($url, $collection = 'main', $user = 'admin') {

        try {
            $this->cr->setup($user);
            $page = $this->cr->getNodeForURL($url);
            $collectionNode = $page->getNode($collection);

            if ($collectionNode === null) {
                throw new \Exception(sprintf('page has no collection node named "%s"', $collection));
            }

            foreach ($collectionNode->getChildNodes() as $childNode) {
                $this->outputLine($childNode);
            }

        } catch (\Exception $e) {
            $this->outputLine('ERROR: %s', [$e->getMessage()]);
        }
    }

    /**
     * Creates a new content element
     *
     * @param string $url page URL of the page where the content element should be inserted
     * @param string $properties node properties, as JSON, e.g. '{"myAttribute":"My Fancy Value"}'
     * @param string $type node type, defaults to TYPO3.Neos.NodeTypes:Text
     * @param string $collection collection name, defaults to 'main'
     * @param string $name name of the node, leave empty to get a random uuid like name
     * @param string $user use this user's workspace (use 0 to use the live workspace)
     */
    public function createCommand(
        $url,
        $properties = null,
        $type = 'TYPO3.Neos.NodeTypes:Text',
        $collection = 'main',
        $name = null,
        $user = 'admin'
    ) {
        try {
            $this->cr->setup($user);

            $nodeType = $this->cr->getNodeType($type);
            $page = $this->cr->getNodeForURL($url);
            $nameName = $this->cr->generateUniqNodeName($page, $name);
            $collectionNode = $page->getNode($collection);
            $newContentNode = $collectionNode->createNode($nameName, $nodeType);

            if ($properties) {
                $this->cr->setNodeProperties($newContentNode, $properties);
            }

            $this->outputLine(sprintf('%s created.', $newContentNode));

        } catch (\Exception $e) {
            $this->outputLine('ERROR: %s', [$e->getMessage()]);
        }

    }
}
