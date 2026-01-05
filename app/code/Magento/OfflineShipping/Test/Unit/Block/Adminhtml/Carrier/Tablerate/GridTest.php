<?php
/**
 * Copyright 2015 Adobe
 * All Rights Reserved.
 */
declare(strict_types=1);

namespace Magento\OfflineShipping\Test\Unit\Block\Adminhtml\Carrier\Tablerate;

use Magento\Backend\Block\Template\Context;
use Magento\Backend\Helper\Data;
use Magento\OfflineShipping\Block\Adminhtml\Carrier\Tablerate\Grid;
use Magento\OfflineShipping\Model\Carrier\Tablerate;
use Magento\OfflineShipping\Model\ResourceModel\Carrier\Tablerate\CollectionFactory;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Store\Model\Website;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class GridTest extends TestCase
{
    /**
     * @var Grid
     */
    protected $model;

    /**
     * @var StoreManagerInterface|MockObject
     */
    protected $storeManagerMock;

    /**
     * @var Data|MockObject
     */
    protected $backendHelperMock;

    /**
     * @var MockObject
     */
    protected $tablerateMock;

    /**
     * @var MockObject
     */
    protected $context;

    /**
     * @var MockObject
     */
    protected $collectionFactoryMock;

    protected function setUp(): void
    {
        $this->storeManagerMock = $this->createMock(StoreManagerInterface::class);

        $this->context = $this->createMock(Context::class);

        $this->backendHelperMock = $this->createMock(Data::class);

        $this->collectionFactoryMock = $this->createMock(CollectionFactory::class);

        $this->tablerateMock = $this->createMock(Tablerate::class);

        $this->model = $this->getMockBuilder(Grid::class)
            ->disableOriginalConstructor()
            ->onlyMethods([])
            ->getMock();

        $reflection = new \ReflectionClass(Grid::class);

        $storeManagerProperty = $reflection->getProperty('_storeManager');
        $storeManagerProperty->setValue($this->model, $this->storeManagerMock);

        $collectionFactoryProperty = $reflection->getProperty('_collectionFactory');
        $collectionFactoryProperty->setValue($this->model, $this->collectionFactoryMock);

        $tablerateProperty = $reflection->getProperty('_tablerate');
        $tablerateProperty->setValue($this->model, $this->tablerateMock);
    }

    public function testSetWebsiteId()
    {
        $websiteId = 1;

        $websiteMock = $this->getMockBuilder(Website::class)
            ->onlyMethods(['getId'])
            ->disableOriginalConstructor()
            ->getMock();

        $this->storeManagerMock->expects($this->once())
            ->method('getWebsite')
            ->with($websiteId)
            ->willReturn($websiteMock);

        $websiteMock->expects($this->once())
            ->method('getId')
            ->willReturn($websiteId);

        $this->assertSame($this->model, $this->model->setWebsiteId($websiteId));
        $this->assertEquals($websiteId, $this->model->getWebsiteId());
    }

    public function testGetWebsiteId()
    {
        $websiteId = 10;

        $websiteMock = $this->getMockBuilder(Website::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getId'])
            ->getMock();

        $websiteMock->expects($this->once())
            ->method('getId')
            ->willReturn($websiteId);

        $this->storeManagerMock->expects($this->once())
            ->method('getWebsite')
            ->willReturn($websiteMock);

        $this->assertEquals($websiteId, $this->model->getWebsiteId());

        $this->storeManagerMock->expects($this->never())
            ->method('getWebsite')
            ->willReturn($websiteMock);

        $this->assertEquals($websiteId, $this->model->getWebsiteId());
    }

    public function testSetAndGetConditionName()
    {
        $conditionName = 'someName';
        $this->assertEquals($this->model, $this->model->setConditionName($conditionName));
        $this->assertEquals($conditionName, $this->model->getConditionName());
    }
}
