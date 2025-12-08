<?php
/**
 * Copyright 2015 Adobe
 * All Rights Reserved.
 */
declare(strict_types=1);

namespace Magento\Framework\Model\Test\Unit\ResourceModel\Type\Db\Pdo;

use Magento\Framework\DB\Adapter\Pdo\MysqlFactory;
use Magento\Framework\DB\Ddl\Table;
use Magento\Framework\DB\LoggerInterface;
use Magento\Framework\DB\SelectFactory;
use Magento\Framework\Model\ResourceModel\Type\Db\Pdo\Mysql;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class MysqlTest extends TestCase
{
    /**
     * @var SelectFactory
     */
    private $selectFactoryMock;

    /**
     * @var MysqlFactory|MockObject
     */
    private $mysqlFactoryMock;

    /**
     * @var Table|MockObject
     */
    private $ddlTableMock;

    protected function setUp(): void
    {
        $this->selectFactoryMock = $this->createMock(SelectFactory::class);
        $this->mysqlFactoryMock = $this->createMock(MysqlFactory::class);
        $this->ddlTableMock = $this->createMock(Table::class);
    }

    /**
     * @param array $inputConfig
     * @param array $expectedConfig
     * @dataProvider constructorDataProvider
     */
    public function testConstructor(array $inputConfig, array $expectedConfig)
    {
        $this->markTestSkipped('Skipped in #27500 due to testing protected/private methods and properties');

        $object = new Mysql(
            $inputConfig,
            $this->mysqlFactoryMock,
            $this->ddlTableMock
        );
        $this->assertAttributeEquals($expectedConfig, 'connectionConfig', $object);
    }

    /**
     * @return array
     */
    public static function constructorDataProvider()
    {
        return [
            'default values' => [
                ['host' => 'localhost'],
                ['host' => 'localhost', 'type' => 'pdo_mysql', 'active' => false],
            ],
            'custom values' => [
                ['host' => 'localhost', 'initStatements' => 'init statement', 'type' => 'type', 'active' => true],
                ['host' => 'localhost', 'initStatements' => 'init statement', 'type' => 'type', 'active' => true],
            ],
            'active string true' => [
                ['host' => 'localhost', 'active' => 'true'],
                ['host' => 'localhost', 'type' => 'pdo_mysql', 'active' => true],
            ],
            'non-active string false' => [
                ['host' => 'localhost', 'active' => 'false'],
                ['host' => 'localhost', 'type' => 'pdo_mysql', 'active' => false],
            ],
            'non-active string 0' => [
                ['host' => 'localhost', 'active' => '0'],
                ['host' => 'localhost', 'type' => 'pdo_mysql', 'active' => false],
            ],
            'non-active bool false' => [
                ['host' => 'localhost', 'active' => false],
                ['host' => 'localhost', 'type' => 'pdo_mysql', 'active' => false],
            ],
        ];
    }

    public function testConstructorException()
    {
        $this->expectException('InvalidArgumentException');
        $this->expectExceptionMessage('MySQL adapter: Missing required configuration option \'host\'');
        new Mysql(
            [],
            $this->mysqlFactoryMock,
            $this->ddlTableMock
        );
    }

    public function testGetConnectionInactive()
    {
        $this->expectException('InvalidArgumentException');
        $this->expectExceptionMessage(
            'Configuration array must have a key for \'dbname\' that names the database instance'
        );
        $config = ['host' => 'localhost', 'active' => false];
        
        // Mock DB\Ddl\Table to return utf8mb4 charset
        $this->ddlTableMock->expects($this->once())
            ->method('getOption')
            ->with('charset')
            ->willReturn('utf8mb4');
        
        $this->mysqlFactoryMock->expects($this->once())
            ->method('create')
            ->willThrowException(
                new \InvalidArgumentException(
                    'Configuration array must have a key for \'dbname\' that names the database instance'
                )
            );
        $object = new Mysql(
            $config,
            $this->mysqlFactoryMock,
            $this->ddlTableMock
        );
        $loggerMock = $this->getMockForAbstractClass(LoggerInterface::class);
        $this->assertNull($object->getConnection($loggerMock, $this->selectFactoryMock));
    }
}
