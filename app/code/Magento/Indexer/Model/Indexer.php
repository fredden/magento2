<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Magento\Indexer\Model;

use Magento\Framework\App\ObjectManager;
use Magento\Framework\DataObject;
use Magento\Framework\Indexer\ActionFactory;
use Magento\Framework\Indexer\ActionInterface;
use Magento\Framework\Indexer\Config\DependencyInfoProviderInterface;
use Magento\Framework\Indexer\ConfigInterface;
use Magento\Framework\Indexer\IndexerInterface;
use Magento\Framework\Indexer\IndexStructureInterface;
use Magento\Framework\Indexer\StateInterface;
use Magento\Framework\Indexer\StructureFactory;
use Magento\Framework\Indexer\IndexerInterfaceFactory;
use Magento\Framework\Indexer\SuspendableIndexerInterface;
use Magento\Framework\Mview\View\ChangelogTableNotExistsException;
use Magento\Framework\Mview\ViewInterface;
use Magento\Indexer\Model\Indexer\CollectionFactory;
use Magento\Indexer\Model\Indexer\StateFactory;

/**
 * Indexer model.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class Indexer extends DataObject implements IndexerInterface, SuspendableIndexerInterface
{
    /**
     * @var string
     */
    protected $_idFieldName = 'indexer_id';

    /**
     * @var ConfigInterface
     */
    protected $config;

    /**
     * @var ActionFactory
     */
    protected $actionFactory;

    /**
     * @var StructureFactory
     */
    protected $structureFactory;

    /**
     * @var \Magento\Framework\Mview\ViewInterface
     */
    protected $view;

    /**
     * @var \Magento\Indexer\Model\Indexer\StateFactory
     */
    protected $stateFactory;

    /**
     * @var \Magento\Indexer\Model\Indexer\State
     */
    protected $state;

    /**
     * @var Indexer\CollectionFactory
     */
    protected $indexersFactory;

    /**
     * @var WorkingStateProvider
     */
    private $workingStateProvider;

    /**
     * @var IndexerInterfaceFactory
     */
    private $indexerFactory;

    /**
     * @var DependencyInfoProviderInterface
     */
    private $dependencyInfoProvider;

    /**
     * @param ConfigInterface $config
     * @param ActionFactory $actionFactory
     * @param StructureFactory $structureFactory
     * @param ViewInterface $view
     * @param StateFactory $stateFactory
     * @param CollectionFactory $indexersFactory
     * @param WorkingStateProvider $workingStateProvider
     * @param IndexerInterfaceFactory $indexerFactory
     * @param array $data
     * @param DependencyInfoProviderInterface|null $dependencyInfoProvider
     * @SuppressWarnings(PHPMD.ExcessiveParameterList)
     */
    public function __construct(
        ConfigInterface $config,
        ActionFactory $actionFactory,
        StructureFactory $structureFactory,
        \Magento\Framework\Mview\ViewInterface $view,
        Indexer\StateFactory $stateFactory,
        Indexer\CollectionFactory $indexersFactory,
        WorkingStateProvider $workingStateProvider,
        IndexerInterfaceFactory $indexerFactory,
        array $data = [],
        ?DependencyInfoProviderInterface $dependencyInfoProvider = null
    ) {
        $this->config = $config;
        $this->actionFactory = $actionFactory;
        $this->structureFactory = $structureFactory;
        $this->view = $view;
        $this->stateFactory = $stateFactory;
        $this->indexersFactory = $indexersFactory;
        $this->workingStateProvider = $workingStateProvider;
        $this->indexerFactory = $indexerFactory;
        $this->dependencyInfoProvider = $dependencyInfoProvider
            ?? ObjectManager::getInstance()->get(DependencyInfoProviderInterface::class);
        parent::__construct($data);
    }

    /**
     * Return ID
     *
     * @codeCoverageIgnore
     *
     * @return string
     */
    public function getId()
    {
        return $this->getData($this->_idFieldName);
    }

    /**
     * Set ID
     *
     * @codeCoverageIgnore
     *
     * @param string $id
     * @return $this
     */
    public function setId($id)
    {
        $this->setData($this->_idFieldName, $id);
        return $this;
    }

    /**
     * Id field name setter
     *
     * @codeCoverageIgnore
     *
     * @param  string $name
     * @return $this
     */
    public function setIdFieldName($name)
    {
        $this->_idFieldName = $name;
        return $this;
    }

    /**
     * Id field name getter
     *
     * @codeCoverageIgnore
     *
     * @return string
     */
    public function getIdFieldName()
    {
        return $this->_idFieldName;
    }

    /**
     * Return indexer's view ID
     *
     * @return string
     */
    public function getViewId()
    {
        return $this->getData('view_id');
    }

    /**
     * Return indexer action class
     *
     * @return string
     */
    public function getActionClass()
    {
        return $this->getData('action_class');
    }

    /**
     * Return indexer title
     *
     * @return string
     */
    public function getTitle()
    {
        return $this->getData('title');
    }

    /**
     * Return indexer description
     *
     * @return string
     */
    public function getDescription()
    {
        return $this->getData('description');
    }

    /**
     * Return indexer fields
     *
     * @return array
     */
    public function getFields()
    {
        return $this->getData('fields');
    }

    /**
     * Return indexer sources
     *
     * @return array
     */
    public function getSources()
    {
        return $this->getData('sources');
    }

    /**
     * Return indexer handlers
     *
     * @return array
     */
    public function getHandlers()
    {
        return $this->getData('handlers');
    }

    /**
     * Fill indexer data from config
     *
     * @param string $indexerId
     * @return IndexerInterface
     * @throws \InvalidArgumentException
     */
    public function load($indexerId)
    {
        $indexer = $this->config->getIndexer($indexerId);
        if (empty($indexer) || empty($indexer['indexer_id']) || $indexer['indexer_id'] != $indexerId) {
            throw new \InvalidArgumentException("{$indexerId} indexer does not exist.");
        }

        $this->setId($indexerId);
        $this->setData($indexer);

        return $this;
    }

    /**
     * Return related view object
     *
     * @return \Magento\Framework\Mview\ViewInterface
     */
    public function getView()
    {
        if (!$this->view->getId()) {
            $this->view->load($this->getViewId());
        }
        return $this->view;
    }

    /**
     * Return related state object
     *
     * @return StateInterface
     */
    public function getState()
    {
        if (!$this->state) {
            $this->state = $this->stateFactory->create();
            $this->state->loadByIndexer($this->getId());
        }
        return $this->state;
    }

    /**
     * Set indexer state object
     *
     * @param StateInterface $state
     * @return IndexerInterface
     */
    public function setState(StateInterface $state)
    {
        $this->state = $state;
        return $this;
    }

    /**
     * Check whether indexer is run by schedule
     *
     * @return bool
     */
    public function isScheduled()
    {
        return $this->getView()->isEnabled();
    }

    /**
     * Turn scheduled mode on/off
     *
     * @param bool $scheduled
     * @return void
     */
    public function setScheduled($scheduled)
    {
        if ($scheduled) {
            $this->getView()->subscribe();
        } else {
            $this->getView()->unsubscribe();
            $this->invalidate();
        }
        $this->getState()->save();
    }

    /**
     * Check whether indexer is valid
     *
     * @return bool
     */
    public function isValid()
    {
        return $this->getState()->getStatus() == StateInterface::STATUS_VALID;
    }

    /**
     * Check whether indexer is invalid
     *
     * @return bool
     */
    public function isInvalid()
    {
        return $this->getState()->getStatus() == StateInterface::STATUS_INVALID;
    }

    /**
     * Checks whether indexer is suspended.
     *
     * @return bool
     */
    public function isSuspended(): bool
    {
        return $this->getState()->getStatus() === StateInterface::STATUS_SUSPENDED;
    }

    /**
     * Check whether indexer is working
     *
     * @return bool
     */
    public function isWorking()
    {
        return $this->getState()->getStatus() == StateInterface::STATUS_WORKING;
    }

    /**
     * Set indexer invalid
     *
     * @return void
     */
    public function invalidate()
    {
        $state = $this->getState();
        $state->setStatus(StateInterface::STATUS_INVALID);
        $state->save();
    }

    /**
     * Return indexer status
     *
     * @return string
     */
    public function getStatus()
    {
        return $this->getState()->getStatus();
    }

    /**
     * Return indexer or mview latest updated time
     *
     * @return string
     */
    public function getLatestUpdated()
    {
        if ($this->getView()->isEnabled() && $this->getView()->getUpdated()) {
            if (!$this->getState()->getUpdated()) {
                return $this->getView()->getUpdated();
            }
            $indexerUpdatedDate = new \DateTime($this->getState()->getUpdated());
            $viewUpdatedDate = new \DateTime($this->getView()->getUpdated());
            if ($viewUpdatedDate > $indexerUpdatedDate) {
                return $this->getView()->getUpdated();
            }
        }
        return $this->getState()->getUpdated() ?: '';
    }

    /**
     * Return indexer action instance
     *
     * @return ActionInterface
     * @throws \InvalidArgumentException
     */
    protected function getActionInstance()
    {
        return $this->actionFactory->create(
            $this->getActionClass(),
            [
                'indexStructure' => $this->getStructureInstance(),
                'data' => $this->getData(),
            ]
        );
    }

    /**
     * Return indexer structure instance
     *
     * @return IndexStructureInterface
     */
    protected function getStructureInstance()
    {
        if (!$this->getData('structure')) {
            return null;
        }
        return $this->structureFactory->create($this->getData('structure'));
    }

    /**
     * Regenerate full index
     *
     * @return void
     * @throws \Throwable
     */
    public function reindexAll()
    {
        if (!$this->workingStateProvider->isWorking($this->getId())) {
            $state = $this->getState();
            $state->setStatus(StateInterface::STATUS_WORKING);
            $state->save();

            $resetViewVersion = $this->shouldResetViewVersion();

            $sharedIndexers = [];
            $indexerConfig = $this->config->getIndexer($this->getId());
            if ($indexerConfig['shared_index'] !== null) {
                $sharedIndexers = $this->getSharedIndexers($indexerConfig['shared_index']);
            }

            $this->suspendViews(array_merge($sharedIndexers, [$this]), $resetViewVersion);

            try {
                $this->getActionInstance()->executeFull();
                if ($this->workingStateProvider->isWorking($this->getId())) {
                    $state->setStatus(StateInterface::STATUS_VALID);
                    $state->save();
                }
                if (!empty($sharedIndexers)) {
                    $this->resumeSharedViews($sharedIndexers);
                }
                $this->getView()->resume();
            } catch (\Throwable $exception) {
                $state->setStatus(StateInterface::STATUS_INVALID);
                $state->save();
                if (!empty($sharedIndexers)) {
                    $this->resumeSharedViews($sharedIndexers);
                }
                $this->getView()->resume();
                throw $exception;
            }
        }
    }

    /**
     * Get indexer ids that uses same index
     *
     * @param string $sharedIndex
     * @return array
     */
    private function getSharedIndexers(string $sharedIndex) : array
    {
        $result = [];
        foreach (array_keys($this->config->getIndexers()) as $indexerId) {
            if ($indexerId === $this->getId()) {
                continue;
            }
            $indexerConfig = $this->config->getIndexer($indexerId);
            if ($indexerConfig['shared_index'] === $sharedIndex) {
                $indexer = $this->indexerFactory->create();
                $indexer->load($indexerId);
                $result[] = $indexer;
            }
        }
        return $result;
    }

    /**
     * Suspend views
     *
     * @param IndexerInterface[] $indexers
     * @param bool $reset
     * @return void
     * @throws \Exception
     */
    private function suspendViews(array $indexers, bool $reset = true) : void
    {
        foreach ($indexers as $indexer) {
            if ($indexer->getView()->isEnabled()) {
                if ($reset) {
                    // this method also resets the mview version to the current one
                    $indexer->getView()->suspend();
                } else {
                    $state = $indexer->getView()->getState();
                    $state->setStatus(\Magento\Framework\Mview\View\StateInterface::STATUS_SUSPENDED);
                    $state->save();
                }
            }
        }
    }

    /**
     * Suspend views of shared indexers
     *
     * @param array $sharedIndexers
     * @return void
     */
    private function resumeSharedViews(array $sharedIndexers) : void
    {
        foreach ($sharedIndexers as $indexer) {
            $indexer->getView()->resume();
        }
    }

    /**
     * Regenerate one row in index by ID
     *
     * @param int $id
     * @return void
     */
    public function reindexRow($id)
    {
        $this->getActionInstance()->executeRow($id);
        $this->getState()->save();
    }

    /**
     * Regenerate rows in index by ID list
     *
     * @param int[] $ids
     * @return void
     */
    public function reindexList($ids)
    {
        $this->getActionInstance()->executeList($ids);
        $this->getState()->save();
    }

    /**
     * Return all indexer Ids on which the current indexer depends (directly or indirectly).
     *
     * @param string $indexerId
     * @return array
     */
    private function getIndexerIdsToRunBefore(string $indexerId): array
    {
        $relatedIndexerIds = [];
        foreach ($this->dependencyInfoProvider->getIndexerIdsToRunBefore($indexerId) as $relatedIndexerId) {
            if ($relatedIndexerId !== $indexerId) {
                $relatedIndexerIds[] = [$relatedIndexerId];
                $relatedIndexerIds[] = $this->getIndexerIdsToRunBefore($relatedIndexerId);
            }
        }

        return array_unique(array_merge([], ...$relatedIndexerIds));
    }

    /**
     * Check whether view is up to date
     *
     * @param ViewInterface $view
     * @return bool
     */
    private function isViewUpToDate(\Magento\Framework\Mview\ViewInterface $view): bool
    {
        if (!$view->isEnabled()) {
            return true;
        }

        try {
            $currentVersionId = $view->getChangelog()->getVersion();
        } catch (ChangelogTableNotExistsException $e) {
            return true;
        }

        $lastVersionId = (int)$view->getState()->getVersionId();
        if ($lastVersionId >= $currentVersionId) {
            return true;
        }

        return false;
    }

    /**
     * Check whether indexer view version should be reset
     *
     * @return bool
     */
    private function shouldResetViewVersion(): bool
    {
        $resetViewVersion = true;
        foreach ($this->getIndexerIdsToRunBefore($this->getId()) as $indexerId) {
            if ($indexerId === $this->getId()) {
                continue;
            }
            $indexer = $this->indexerFactory->create();
            $indexer->load($indexerId);
            if ($indexer->isValid() && !$this->isViewUpToDate($indexer->getView())) {
                $resetViewVersion = false;
                break;
            }
        }
        return $resetViewVersion;
    }
}
