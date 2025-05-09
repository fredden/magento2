<?php
/**
 * Copyright 2012 Adobe
 * All Rights Reserved.
 */

namespace Magento\TestFramework\Event;

/**
 * Database transaction events manager
 */
class Transaction
{
    /**
     * @var \Magento\TestFramework\EventManager
     */
    protected $_eventManager;

    /**
     * @var \Magento\TestFramework\Event\Param\Transaction
     */
    protected $_eventParam;

    /**
     * @var bool
     */
    protected $_isTransactionActive = false;

    /**
     * Constructor
     *
     * @param \Magento\TestFramework\EventManager $eventManager
     */
    public function __construct(\Magento\TestFramework\EventManager $eventManager)
    {
        $this->_eventManager = $eventManager;
    }

    /**
     * Handler for 'startTest' event
     *
     * @param \PHPUnit\Framework\TestCase $test
     */
    public function startTest(\PHPUnit\Framework\TestCase $test)
    {
        $this->_processTransactionRequests('startTest', $test);
    }

    /**
     * Handler for 'endTest' event
     *
     * @param \PHPUnit\Framework\TestCase $test
     */
    public function endTest(\PHPUnit\Framework\TestCase $test)
    {
        $this->_processTransactionRequests('endTest', $test);
    }

    /**
     * Handler for 'endTestSuite' event
     */
    public function endTestSuite()
    {
        $this->_rollbackTransaction();
    }

    /**
     * Query whether there are any requests for transaction operations and performs them
     *
     * @param string $eventName
     * @param \PHPUnit\Framework\TestCase $test
     */
    protected function _processTransactionRequests($eventName, \PHPUnit\Framework\TestCase $test)
    {
        $param = $this->_getEventParam();
        $this->_eventManager->fireEvent($eventName . 'TransactionRequest', [$test, $param]);
        if ($param->isTransactionRollbackRequested()) {
            $this->_rollbackTransaction();
        }
        if ($param->isTransactionStartRequested()) {
            $this->_startTransaction($test);
        }
    }

    /**
     * Start transaction and fire 'startTransaction' event
     *
     * @param \PHPUnit\Framework\TestCase $test
     * @SuppressWarnings(PHPMD.UnusedLocalVariable)
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    protected function _startTransaction(\PHPUnit\Framework\TestCase $test)
    {
        if (!$this->_isTransactionActive) {
            $this->_getConnection()->beginTransparentTransaction();
            $this->_isTransactionActive = true;
            $this->_eventManager->fireEvent('startTransaction', [$test]);
        }
    }

    /**
     * Rollback transaction and fire 'rollbackTransaction' event
     */
    protected function _rollbackTransaction()
    {
        if ($this->_isTransactionActive) {
            $this->_isTransactionActive = false;
            $this->_getConnection()->rollbackTransparentTransaction();
            $this->_eventManager->fireEvent('rollbackTransaction');
            $this->_getConnection()->closeConnection();
        }
    }

    /**
     * Retrieve database adapter instance
     *
     * @param string $connectionName
     * @return \Magento\Framework\DB\Adapter\AdapterInterface|\Magento\TestFramework\Db\Adapter\TransactionInterface
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    protected function _getConnection($connectionName = \Magento\Framework\App\ResourceConnection::DEFAULT_CONNECTION)
    {
        /** @var $resource \Magento\Framework\App\ResourceConnection */
        $resource = \Magento\TestFramework\Helper\Bootstrap::getObjectManager()
            ->get(\Magento\Framework\App\ResourceConnection::class);
        return $resource->getConnection($connectionName);
    }

    /**
     * Retrieve clean instance of transaction event parameter
     *
     * @return \Magento\TestFramework\Event\Param\Transaction
     */
    protected function _getEventParam()
    {
        /* reset object state instead of instantiating new object over and over again */
        if (!$this->_eventParam) {
            $this->_eventParam = new \Magento\TestFramework\Event\Param\Transaction();
        } else {
            $this->_eventParam->__construct();
        }
        return $this->_eventParam;
    }
}
