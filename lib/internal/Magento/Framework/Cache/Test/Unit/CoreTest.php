<?php
/**
 * Copyright 2013 Adobe
 * All Rights Reserved.
 */
declare(strict_types=1);

/**
 * \Magento\Framework\Cache\Core test case
 *
 * @deprecated No longer used in production. All cache operations now use Symfony cache adapter.
 * @see \Magento\Framework\Cache\Frontend\Adapter\Symfony
 */
namespace Magento\Framework\Cache\Test\Unit;

use Magento\Framework\Cache\CacheConstants;
use Magento\Framework\Cache\Core;
use PHPUnit\Framework\TestCase;

class CoreTest extends TestCase
{
    /**
     * @var Core
     */
    protected Core $_core;

    /**
     * @var \Zend_Cache_Backend|MockObject
     */
    protected $backendMock;

    protected function setUp(): void
    {
        // Core extends Zend_Cache_Core, which expects options array
        // and a backend object. We'll set up a minimal configuration.
        $this->backendMock = $this->getMockBuilder(\Zend_Cache_Backend::class)
            ->disableOriginalConstructor()
            ->addMethods([
                'save',
                'clean',
                'load',
                'test',
                'remove',
                'getCapabilities',
                'getTags',
                'getIdsMatchingTags',
                'getIdsMatchingAnyTags',
                'getIdsNotMatchingTags',
                'getFillingPercentage',
                'getMetadatas'
            ])
            ->getMock();
        
        $this->_core = new Core(['disable_save' => false]);
        $this->_core->setBackend($this->backendMock);
    }

    protected function tearDown(): void
    {
        unset($this->backendMock);
        unset($this->_core);
    }

    public function testSaveDisabled()
    {
        // Test with disable_save option
        $backendMock = $this->getMockBuilder(\Zend_Cache_Backend::class)
            ->disableOriginalConstructor()
            ->addMethods(['save', 'clean', 'load', 'test', 'remove'])
            ->getMock();
        
        $coreDisabled = new Core(['disable_save' => true]);
        $coreDisabled->setBackend($backendMock);
        
        $backendMock->expects($this->never())->method('save');
        $result = $coreDisabled->save('data', 'id');
        $this->assertTrue($result);
    }

    public function testSave()
    {
        $data = 'data';
        $id = 'id';
        $tags = ['tag1', 'tag2'];

        // Verify that backend save is called when Core save is called
        $this->backendMock->expects($this->once())
            ->method('save')
            ->willReturn(true);

        $this->_core->save($data, $id, $tags);
    }

    public function testClean()
    {
        // Test CLEANING_MODE_ALL
        $this->backendMock->expects($this->once())
            ->method('clean')
            ->with(CacheConstants::CLEANING_MODE_ALL, [])
            ->willReturn(true);
        $result = $this->_core->clean(CacheConstants::CLEANING_MODE_ALL);
        $this->assertTrue($result);
    }
}
