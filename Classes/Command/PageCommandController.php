<?php
/**
 * Created by PhpStorm.
 * User: remuslazar
 * Date: 02.05.18
 * Time: 18:58
 */

namespace CRON\CRLib\Command;

use /** @noinspection PhpUnusedAliasInspection */
    TYPO3\Flow\Annotations as Flow;

use CRON\CRLib\Utility\NeosDocumentTreePrinter;
use CRON\CRLib\Utility\NeosDocumentWalker;
use TYPO3\Flow\Cli\CommandController;
use TYPO3\TYPO3CR\Domain\Model\NodeInterface;

/**
 * Class PageCommandController
 *
 * This command controller offers some utilities like remove or batch change of existing Neos pages.
 *
 * The main difference to the existing NodeCommandController is that this one uses high level APIs to manage the underlying
 * nodes and will not use the (low level) doctrine methods to do so. By default it will also use the current workspace
 * of the "admin" user so all changes can be reviewed in the e.g. backend.
 *
 * @package CRON\CRLib\Command
 *
 * @Flow\Scope("singleton")
 */
class PageCommandController extends CommandController
{

    /**
     * @Flow\Inject
     * @var \CRON\CRLib\Service\CRService
     */
    protected $cr;

    /**
     * Shows the current configuration of the working environment
     *
     * @param string $user username to use, defaults to the admin user. This will also use the user's workspace by
     *     default for all operations.
     *
     * @throws \Exception
     */
    public function infoCommand($user = 'admin')
    {
        $this->cr->setup($user);

        $this->output->outputTable(
            [
              ['Current Site Name', $this->cr->currentSite->getName()],
              ['Workspace Name', $this->cr->workspaceName],
              ['Site node name', $this->cr->currentSite->getNodeName()],
            ],
            [ 'Key', 'Value']);
    }

    /**
     * Lists all documents, optionally filtered by a prefix
     *
     * @param string $user use this user's workspace
     * @param int $depth depth, defaults to 1
     * @param string $path , e.g. /news (don't use the /sites/dazsite prefix!)
     *
     */
    public function listCommand($user = 'admin', $depth=1, $path = '')
    {
        try {
            $this->cr->setup($user);
            $rootNode = $this->cr->getNodeForPath($path);
            if (!$rootNode) {
                throw new \Exception(sprintf('Could not find any node on path "%s"', $path));
            }
            $printer = new NeosDocumentTreePrinter($rootNode, $depth);
            $printer->printTree($this->output);
        } catch (\Exception $e) {
            $this->outputLine('ERROR: %s', [$e->getMessage()]);
        }
    }

    /**
     * Remove documents, optionally filtered by a prefix. The unix return code will be 0 (successful) only if at least
     * one document was removed, else it will return 1. Useful for bash while loops.
     *
     * @param string $user use this user's workspace
     * @param string $path , e.g. /news (don't use the /sites/dazsite prefix!)
     * @param string $url use the URL instead of o path
     * @param int $limit limit
     *
     * @throws \TYPO3\Flow\Mvc\Exception\StopActionException
     */
    public function removeCommand($user = 'admin', $path = '', $url = '', $limit = 0)
    {
        try {
            $this->cr->setup($user);

            if ($url) {
                $rootNode = $this->cr->getNodeForURL($url);
            } else {
                $rootNode = $this->cr->getNodeForPath($path);
            }

            if (!$rootNode) {
                throw new \Exception(sprintf('Could not find any node on path "%s"', $path));
            }
            $walker = new NeosDocumentWalker($rootNode);

            /** @var NodeInterface[] $nodesToDelete */
            $nodesToDelete = $walker->getNodes($limit);

            foreach ($nodesToDelete as $nodeToDelete) {
                $nodeToDelete->remove();
            }

            $this->output->outputTable(array_map( function(NodeInterface $node) { return [$node]; }, $nodesToDelete),
                ['Deleted Pages']);
            $this->quit(count($nodesToDelete) > 0 ? 0 : 1);

        } catch (\Exception $e) {
            if ($e instanceof \TYPO3\Flow\Mvc\Exception\StopActionException) { return; }
            $this->outputLine('ERROR: %s', [$e->getMessage()]);
            $this->quit(1);
        }
    }

    /**
     * Publish all pending changes in the workspace
     *
     * @param string $user use this user's workspace
     *
     * @throws \TYPO3\Flow\Mvc\Exception\StopActionException
     */
    public function publishCommand($user = 'admin')
    {
        try {
            $this->cr->setup($user);
            $this->cr->publish();
        } catch (\Exception $e) {
            $this->outputLine('ERROR: %s', [$e->getMessage()]);
            $this->quit(1);
        }
    }

    /**
     * Resolves a given URL to the current Neos node path
     *
     * @param string $url URL to resolve
     * @param string $user use this user's workspace
     */
    public function resolveURLCommand($url, $user = 'admin')
    {
        try {
            $this->cr->setup($user);
            /** @var NodeInterface $document */
            $document = $this->cr->getNodeForPath('');
            $this->outputLine('%s', [$this->cr->getNodePathForURL($document, $url)]);
        } catch (\Exception $e) {
            $this->outputLine('ERROR: %s', [$e->getMessage()]);
        }
    }

    /**
     * Creates a new page
     *
     * @param string $parentUrl parent of the new page to be created (must exist), e.g. /news
     * @param string $name name of the node, will also be used for the URL segment
     * @param string $type node type, defaults to TYPO3.Neos.NodeTypes:Page
     * @param string $properties node properties, as JSON, e.g. '{"title":"My Fancy Title"}'
     * @param string $user use this user's workspace (use 0 to use the live workspace)
     */
    public function createCommand($parentUrl, $name, $type = 'TYPO3.Neos.NodeTypes:Page', $properties = null, $user = 'admin')
    {
        try {
            $this->cr->setup($user);
            $nodeType = $this->cr->getNodeType($type);
            $parentNode = $this->cr->getNodeForURL($parentUrl);
            $nodeName = $this->cr->generateUniqNodeName($parentNode, $name);
            $newNode = $parentNode->createNode($nodeName, $nodeType);

            if ($properties) {
                $this->cr->setNodeProperties($newNode, $properties);
            }
            $this->outputLine(sprintf('%s created.', $newNode));

        } catch (\Exception $e) {
            $this->outputLine('ERROR: %s', [$e->getMessage()]);
        }

    }

}
