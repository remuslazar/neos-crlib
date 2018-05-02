<?php
/**
 * Created by PhpStorm.
 * User: remuslazar
 * Date: 02.05.18
 * Time: 18:58
 */

namespace CRON\CRLib\Command;

use CRON\CRLib\Utility\NeosDocumentTreePrinter;
use /** @noinspection PhpUnusedAliasInspection */
    TYPO3\Flow\Annotations as Flow;

use TYPO3\Flow\Cli\CommandController;
use TYPO3\Neos\Domain\Model\Site;
use TYPO3\TYPO3CR\Domain\Service\Context;

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
 * @property Context context
 * @property string sitePath
 * @property string workspaceName
 * @property Site currentSite
 *
 * @Flow\Scope("singleton")
 */
class PageCommandController extends CommandController
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

    /**
     * Setup and configure the context to use, take care of the arguments like user name etc.
     *
     * @param string $user
     *
     * @throws \Exception
     */
    protected function setup($user)
    {
        // validate user name
        $this->workspaceName = 'user-'.$user;
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
    }

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
        $this->setup($user);

        $this->output->outputTable(
            [
              ['Current Site Name', $this->currentSite->getName()],
              ['Workspace Name', $this->workspaceName],
              ['Site node name', $this->currentSite->getNodeName()],
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
            $this->setup($user);
            $rootNode = $this->context->getNode($this->sitePath . $path);
            if (!$rootNode) {
                throw new \Exception(sprintf('Could not find any node on path "%s"', $path));
            }
            $printer = new NeosDocumentTreePrinter($rootNode, $depth);
            $printer->printTree($this->output);
        } catch (\Exception $e) {
            $this->outputLine('ERROR: %s', [$e->getMessage()]);
        }
    }

}
