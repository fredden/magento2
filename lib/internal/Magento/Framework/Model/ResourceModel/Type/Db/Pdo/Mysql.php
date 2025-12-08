<?php
/**
 * Copyright 2015 Adobe
 * All Rights Reserved.
 */
namespace Magento\Framework\Model\ResourceModel\Type\Db\Pdo;

use Magento\Framework\App\ObjectManager;
use Magento\Framework\App\ResourceConnection\ConnectionAdapterInterface;
use Magento\Framework\DB;
use Magento\Framework\DB\Adapter\Pdo\MysqlFactory;
use Magento\Framework\DB\Ddl\Table;
use Magento\Framework\DB\SelectFactory;

// @codingStandardsIgnoreStart

class Mysql extends \Magento\Framework\Model\ResourceModel\Type\Db implements
    ConnectionAdapterInterface
// @codingStandardsIgnoreEnd
{
    /**
     * @var array
     */
    protected $connectionConfig;

    /**
     * @var MysqlFactory
     */
    private $mysqlFactory;

    /**
     * @var Table
     */
    private $ddlTable;

    /**
     * Constructor
     *
     * @param array $config
     * @param MysqlFactory|null $mysqlFactory
     * @param Table|null $ddlTable
     */
    public function __construct(
        array $config,
        ?MysqlFactory $mysqlFactory = null,
        ?Table $ddlTable = null
    ) {
        $this->mysqlFactory = $mysqlFactory ?: ObjectManager::getInstance()->get(MysqlFactory::class);
        $this->ddlTable = $ddlTable ?: ObjectManager::getInstance()->get(Table::class);
        $this->connectionConfig = $this->getValidConfig($config);
        parent::__construct();
    }

    /**
     * @inheritDoc
     */
    public function getConnection(?DB\LoggerInterface $logger = null, ?SelectFactory $selectFactory = null)
    {
        // Set charset based on database version if not already configured
        if (!isset($this->connectionConfig['initStatements'])) {
            $this->connectionConfig['initStatements'] = $this->getDefaultInitStatements();
        }
        
        $connection = $this->getDbConnectionInstance($logger, $selectFactory);

        $profiler = $connection->getProfiler();
        if ($profiler instanceof DB\Profiler) {
            $profiler->setType($this->connectionConfig['type']);
            $profiler->setHost($this->connectionConfig['host']);
        }

        return $connection;
    }

    /**
     * Create and return database connection object instance
     *
     * @param DB\LoggerInterface|null $logger
     * @param SelectFactory|null $selectFactory
     * @return \Magento\Framework\DB\Adapter\Pdo\Mysql
     */
    protected function getDbConnectionInstance(?DB\LoggerInterface $logger = null, ?SelectFactory $selectFactory = null)
    {
        return $this->mysqlFactory->create(
            $this->getDbConnectionClassName(),
            $this->connectionConfig,
            $logger,
            $selectFactory
        );
    }

    /**
     * Retrieve DB connection class name
     *
     * @return string
     */
    protected function getDbConnectionClassName()
    {
        return DB\Adapter\Pdo\Mysql::class;
    }

    /**
     * Validates the config and adds default options, if any is missing
     *
     * @param array $config
     * @return array
     */
    private function getValidConfig(array $config)
    {
        $default = ['type' => 'pdo_mysql', 'active' => false];
        foreach ($default as $key => $value) {
            if (!isset($config[$key])) {
                $config[$key] = $value;
            }
        }
        $required = ['host'];
        foreach ($required as $name) {
            if (!isset($config[$name])) {
                throw new \InvalidArgumentException("MySQL adapter: Missing required configuration option '$name'");
            }
        }

        if (isset($config['port'])) {
            throw new \InvalidArgumentException(
                "Port must be configured within host (like '$config[host]:$config[port]') parameter, not within port"
            );
        }

        $config['active'] = !(
            $config['active'] === 'false'
            || $config['active'] === false
            || $config['active'] === '0'
        );

        return $config;
    }

    /**
     * Get default initStatements based on database charset
     *
     * Uses DB\Ddl\Table::getOption('charset') to get charset
     * based on database version (same as table creation)
     *
     * @return string
     */
    private function getDefaultInitStatements(): string
    {
        try {
            $charset = $this->ddlTable->getOption('charset');
            return "SET NAMES {$charset}";
        } catch (\Exception $e) {
            // Fallback to utf8 if detection fails
            return 'SET NAMES utf8';
        }
    }
}
