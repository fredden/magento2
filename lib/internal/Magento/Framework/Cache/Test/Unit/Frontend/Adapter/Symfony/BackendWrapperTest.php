<?php
/**
 * Copyright 2025 Adobe
 * All Rights Reserved.
 */
declare(strict_types=1);

namespace Magento\Framework\Cache\Test\Unit\Frontend\Adapter\Symfony;

use Magento\Framework\Cache\Backend\BackendInterface;
use Magento\Framework\Cache\CacheConstants;
use Magento\Framework\Cache\Frontend\Adapter\SymfonyAdapters\AdapterInterface;
use Magento\Framework\Cache\Frontend\Adapter\Symfony\BackendWrapper;
use Magento\Framework\Cache\FrontendInterface;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemPoolInterface;

/**
 * Test for BackendWrapper
 */
class BackendWrapperTest extends TestCase
{
    /**
     * @var CacheItemPoolInterface
     */
    private $cache;

    /**
     * @var AdapterInterface
     */
    private $adapter;

    /**
     * @var FrontendInterface
     */
    private $symfony;

    /**
     * @var BackendWrapper
     */
    private $backendWrapper;

    /**
     * Set up test
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->cache = $this->createMock(CacheItemPoolInterface::class);
        $this->adapter = $this->createMock(AdapterInterface::class);
        $this->symfony = $this->createMock(FrontendInterface::class);
        $this->backendWrapper = new BackendWrapper($this->cache, $this->adapter, $this->symfony);
    }

    /**
     * Test constructor
     *
     * @return void
     */
    public function testConstructor(): void
    {
        $cache = $this->createMock(CacheItemPoolInterface::class);
        $adapter = $this->createMock(AdapterInterface::class);
        $symfony = $this->createMock(FrontendInterface::class);
        $wrapper = new BackendWrapper($cache, $adapter, $symfony);

        $this->assertInstanceOf(BackendWrapper::class, $wrapper);
        $this->assertInstanceOf(BackendInterface::class, $wrapper);
    }

    /**
     * Test load method
     *
     * @return void
     */
    public function testLoad(): void
    {
        $this->symfony->expects($this->once())
            ->method('load')
            ->with('test_id')
            ->willReturn('cached_data');

        $result = $this->backendWrapper->load('test_id');

        $this->assertEquals('cached_data', $result);
    }

    /**
     * Test load method returns false when cache miss
     *
     * @return void
     */
    public function testLoadReturnsFalseOnCacheMiss(): void
    {
        $this->symfony->expects($this->once())
            ->method('load')
            ->with('test_id')
            ->willReturn(false);

        $result = $this->backendWrapper->load('test_id');

        $this->assertFalse($result);
    }

    /**
     * Test test method
     *
     * @return void
     */
    public function testTest(): void
    {
        $this->symfony->expects($this->once())
            ->method('test')
            ->with('test_id')
            ->willReturn(time());

        $result = $this->backendWrapper->test('test_id');

        $this->assertIsInt($result);
    }

    /**
     * Test save method
     *
     * @return void
     */
    public function testSave(): void
    {
        $this->symfony->expects($this->once())
            ->method('save')
            ->with('test_data', 'test_id', [], null)
            ->willReturn(true);

        $result = $this->backendWrapper->save('test_data', 'test_id', [], null);

        $this->assertTrue($result);
    }

    /**
     * Test save method with tags
     *
     * @return void
     */
    public function testSaveWithTags(): void
    {
        $this->symfony->expects($this->once())
            ->method('save')
            ->with('test_data', 'test_id', ['tag1', 'tag2'], null)
            ->willReturn(true);

        $result = $this->backendWrapper->save('test_data', 'test_id', ['tag1', 'tag2'], null);

        $this->assertTrue($result);
    }

    /**
     * Test remove method
     *
     * @return void
     */
    public function testRemove(): void
    {
        $this->symfony->expects($this->once())
            ->method('remove')
            ->with('test_id')
            ->willReturn(true);

        $result = $this->backendWrapper->remove('test_id');

        $this->assertTrue($result);
    }

    /**
     * Test clean method with CLEANING_MODE_ALL
     *
     * @return void
     */
    public function testCleanWithModeAll(): void
    {
        $this->adapter->expects($this->once())->method('clearAllIndices');
        $this->cache->expects($this->once())->method('clear')->willReturn(true);

        $result = $this->backendWrapper->clean(CacheConstants::CLEANING_MODE_ALL, []);

        $this->assertTrue($result);
    }

    /**
     * Test clean method with CLEANING_MODE_OLD
     *
     * @return void
     */
    public function testCleanWithModeOld(): void
    {
        $result = $this->backendWrapper->clean(CacheConstants::CLEANING_MODE_OLD, []);

        $this->assertTrue($result);
    }

    /**
     * Test clean method with unsupported mode throws exception
     *
     * @return void
     */
    public function testCleanWithUnsupportedModeThrowsException(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Backend clean only supports ALL and OLD modes');

        $this->backendWrapper->clean(CacheConstants::CLEANING_MODE_MATCHING_TAG, ['tag1']);
    }

    /**
     * Test setOption does nothing (intentional no-op)
     *
     * @return void
     */
    public function testSetOption(): void
    {
        $this->backendWrapper->setOption('test_option', 'test_value');

        // No exception means success (intentional no-op)
        $this->assertTrue(true);
    }

    /**
     * Test clear method
     *
     * @return void
     */
    public function testClear(): void
    {
        $this->adapter->expects($this->once())->method('clearAllIndices');
        $this->cache->expects($this->once())->method('clear')->willReturn(true);

        $result = $this->backendWrapper->clear();

        $this->assertTrue($result);
    }

    /**
     * Test getOption returns null
     *
     * @return void
     */
    public function testGetOptionReturnsNull(): void
    {
        $result = $this->backendWrapper->getOption('any_option');

        $this->assertNull($result);
    }
}
