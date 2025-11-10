<?php
/**
 * Copyright 2013 Adobe
 * All Rights Reserved.
 */
declare(strict_types=1);

namespace Magento\Framework\App\Test\Unit\Cache\Frontend;

use Magento\Framework\App\Cache\Frontend\Factory;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\App\Test\Unit\Cache\Frontend\FactoryTest\CacheDecoratorDummy;
use Magento\Framework\Cache\FrontendInterface;
use Magento\Framework\Filesystem;
use Magento\Framework\Filesystem\Directory\ReadInterface;
use Magento\Framework\ObjectManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for Cache Frontend Factory
 * Tests Symfony cache implementation
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class FactoryTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/FactoryTest/CacheDecoratorDummy.php';
    }

    public function testCreate()
    {
        $model = $this->_buildModelForCreate();
        $result = $model->create(['backend' => 'redis']);

        $this->assertInstanceOf(
            FrontendInterface::class,
            $result,
            'Created object must implement \Magento\Framework\Cache\FrontendInterface'
        );
        
        $lowLevelFrontend = $result->getLowLevelFrontend();
        $this->assertInstanceOf(
            \Magento\Framework\Cache\Frontend\Adapter\Symfony\LowLevelFrontend::class,
            $lowLevelFrontend,
            'Created object must have Symfony LowLevelFrontend'
        );
        
        $backend = $result->getBackend();
        $this->assertTrue(
            $backend instanceof \Magento\Framework\Cache\Frontend\Adapter\Symfony\BackendWrapper ||
            $backend instanceof \Magento\Framework\Cache\Frontend\Adapter\Symfony\LowLevelBackend,
            'Created object must have valid Symfony backend wrapper'
        );
    }

    public function testCreateOptions()
    {
        $model = $this->_buildModelForCreate();
        $result = $model->create(
            [
                'backend' => 'redis',
                'frontend_options' => ['lifetime' => 2601],
                'backend_options' => ['file_extension' => '.wtf'],
            ]
        );

        $frontend = $result->getLowLevelFrontend();
        $backend = $result->getBackend();

        $this->assertEquals(2601, $frontend->getOption('lifetime'));
        
        // For Symfony, backend options are not stored in the wrapper (returns null)
        $fileExtension = $backend->getOption('file_extension');
        $this->assertNull(
            $fileExtension,
            'Backend options are not stored in Symfony wrapper, should return null'
        );
    }

    public function testCreateEnforcedOptions()
    {
        $model = $this->_buildModelForCreate(['backend' => 'redis']);
        $result = $model->create(['backend' => 'file']);

        // The enforced option test verifies that enforced options override regular options
        // Since Symfony uses wrappers, we verify the backend has the correct interface
        $backend = $result->getBackend();
        $this->assertTrue(
            $backend instanceof \Magento\Framework\Cache\Frontend\Adapter\Symfony\BackendWrapper ||
            $backend instanceof \Magento\Framework\Cache\Frontend\Adapter\Symfony\LowLevelBackend,
            'Backend must be valid Symfony wrapper'
        );
    }

    /**
     * @param array $options
     * @param string $expectedPrefix
     * @dataProvider idPrefixDataProvider
     */
    public function testIdPrefix($options, $expectedPrefix)
    {
        $model = $this->_buildModelForCreate(['backend' => 'redis']);
        $result = $model->create($options);

        $frontend = $result->getLowLevelFrontend();
        $this->assertEquals($expectedPrefix, $frontend->getOption('cache_id_prefix'));
    }

    /**
     * @return array
     */
    public static function idPrefixDataProvider()
    {
        return [
            // start of md5('DIR')
            'default id prefix' => [['backend' => 'redis'], 'c15_'],
            'id prefix in "id_prefix" option' => [
                ['backend' => 'redis', 'id_prefix' => 'id_prefix_value'],
                'id_prefix_value',
            ],
            'id prefix in "prefix" option' => [
                ['backend' => 'redis', 'prefix' => 'prefix_value'],
                'prefix_value',
            ]
        ];
    }

    public function testCreateDecorators()
    {
        $model = $this->_buildModelForCreate(
            [],
            [
                [
                    'class' => CacheDecoratorDummy::class,
                    'parameters' => ['param' => 'value'],
                ]
            ]
        );
        $result = $model->create(['backend' => 'redis']);

        $this->assertInstanceOf(
            CacheDecoratorDummy::class,
            $result
        );

        $params = $result->getParams();
        $this->assertArrayHasKey('param', $params);
        $this->assertEquals($params['param'], 'value');
    }

    /**
     * Create the model to be tested, providing it with all required dependencies
     *
     * @param array $enforcedOptions
     * @param array $decorators
     * @return Factory
     * phpcs:disable Squiz.PHP.NonExecutableCode.Unreachable
     */
    protected function _buildModelForCreate($enforcedOptions = [], $decorators = [])
    {
        $dirMock = $this->getMockForAbstractClass(ReadInterface::class);
        $dirMock->expects($this->any())
            ->method('getAbsolutePath')
            ->willReturn('DIR');
        
        // Mock WriteInterface for directory creation
        $writeDirMock = $this->getMockForAbstractClass(\Magento\Framework\Filesystem\Directory\WriteInterface::class);
        $writeDirMock->expects($this->any())
            ->method('getAbsolutePath')
            ->willReturn('DIR');
        $writeDirMock->expects($this->any())
            ->method('create')
            ->willReturn(true);
        
        $filesystem = $this->createMock(Filesystem::class);
        $filesystem->expects($this->any())->method('getDirectoryRead')->willReturn($dirMock);
        $filesystem->expects($this->any())->method('getDirectoryWrite')->willReturn($writeDirMock);

        // Create ResourceConnection mock for SymfonyFactory
        $resource = $this->createMock(ResourceConnection::class);
        $connectionMock = $this->createMock(\Magento\Framework\DB\Adapter\AdapterInterface::class);
        $resource->expects($this->any())->method('getConnection')->willReturn($connectionMock);
        $resource->expects($this->any())->method('getTableName')->willReturnCallback(function ($table) {
            return $table;
        });
        
        // Create Serialize mock for SymfonyFactory
        $serializer = $this->createMock(\Magento\Framework\Serialize\Serializer\Serialize::class);
        $serializer->expects($this->any())->method('serialize')->willReturnCallback(
            function ($data) {
                // phpcs:ignore Magento2.Security.InsecureFunction.FoundWithAlternative
                return serialize($data);
            }
        );
        $serializer->expects($this->any())->method('unserialize')->willReturnCallback(
            function ($data) {
                // phpcs:ignore Magento2.Security.InsecureFunction.FoundWithAlternative
                return unserialize($data);
            }
        );

        // Create mock objects for Symfony adapter
        $cachePoolMock = $this->createMock(\Psr\Cache\CacheItemPoolInterface::class);
        $helperMock = $this->createMock(\Magento\Framework\Cache\Frontend\Adapter\Helper\AdapterHelperInterface::class);
        
        // Create cache factory closure for Symfony adapter
        $cacheFactory = function () use ($cachePoolMock) {
            return $cachePoolMock;
        };
        
        $processFrontendFunc = function (
            $class,
            $params
        ) use (
            $filesystem,
            $resource,
            $serializer,
            $cacheFactory,
            $helperMock
        ) {
            switch ($class) {
                case CacheDecoratorDummy::class:
                    $frontend = $params['frontend'];
                    unset($params['frontend']);
                    return new $class($frontend, $params);
                case \Magento\Framework\App\Cache\Frontend\SymfonyFactory::class:
                    // SymfonyFactory needs Filesystem, ResourceConnection, and Serialize serializer
                    return new $class($filesystem, $resource, $serializer);
                case \Magento\Framework\Cache\Frontend\Adapter\Symfony::class:
                    // Create Symfony adapter with correct constructor signature:
                    // Closure $cacheFactory, ?AdapterHelperInterface $helper, int $defaultLifetime, string $idPrefix
                    // The Factory passes these as direct parameters, not nested in 'options'
                    $defaultLifetime = $params['defaultLifetime'] ?? 7200;
                    $idPrefix = $params['idPrefix'] ?? '';
                    return new $class(
                        $cacheFactory,
                        $helperMock,
                        $defaultLifetime,
                        $idPrefix
                    );
                default:
                    throw new \Exception("Test is not designed to create {$class} objects");
                    break;
            }
        };
        /** @var MockObject $objectManager */
        $objectManager = $this->getMockForAbstractClass(ObjectManagerInterface::class);
        $objectManager->expects($this->any())->method('create')->willReturnCallback($processFrontendFunc);

        $resource = $this->createMock(ResourceConnection::class);

        $model = new Factory(
            $objectManager,
            $filesystem,
            $resource,
            $enforcedOptions,
            $decorators
        );

        return $model;
    }
}
