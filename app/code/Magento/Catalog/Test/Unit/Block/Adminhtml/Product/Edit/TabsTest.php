<?php

/**
 * Copyright 2025 Adobe
 * All Rights Reserved.
 */

declare(strict_types=1);

namespace Magento\Catalog\Test\Unit\Block\Adminhtml\Product\Edit;

use Magento\Backend\Block\Template\Context;
use Magento\Backend\Block\Widget\Tab;
use Magento\Backend\Model\Auth\Session;
use Magento\Catalog\Block\Adminhtml\Product\Edit\Tab\Attributes;
use Magento\Catalog\Block\Adminhtml\Product\Edit\Tabs;
use Magento\Catalog\Helper\Catalog;
use Magento\Catalog\Helper\Data;
use Magento\Catalog\Model\Product;
use Magento\Eav\Model\Entity\Attribute\AbstractAttribute;
use Magento\Eav\Model\Entity\Attribute\Group;
use Magento\Eav\Model\ResourceModel\Entity\Attribute\Group\Collection;
use Magento\Eav\Model\ResourceModel\Entity\Attribute\Group\CollectionFactory;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Json\EncoderInterface;
use Magento\Framework\Module\Manager;
use Magento\Framework\Registry;
use Magento\Framework\TestFramework\Unit\Helper\ObjectManager;
use Magento\Framework\Translate\InlineInterface;
use Magento\Framework\View\Element\AbstractBlock;
use Magento\Framework\View\LayoutInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Unit test for Tabs class
 *
 * @covers \Magento\Catalog\Block\Adminhtml\Product\Edit\Tabs
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class TabsTest extends TestCase
{
    /**
     * @var Tabs
     */
    private Tabs $tabs;

    /**
     * @var Context|MockObject
     */
    private $contextMock;

    /**
     * @var EncoderInterface|MockObject
     */
    private $jsonEncoderMock;

    /**
     * @var Session|MockObject
     */
    private $authSessionMock;

    /**
     * @var Manager|MockObject
     */
    private $moduleManagerMock;

    /**
     * @var CollectionFactory|MockObject
     */
    private $collectionFactoryMock;

    /**
     * @var Catalog|MockObject
     */
    private $helperCatalogMock;

    /**
     * @var Data|MockObject
     */
    private $catalogDataMock;

    /**
     * @var Registry|MockObject
     */
    private $registryMock;

    /**
     * @var InlineInterface|MockObject
     */
    private $translateInlineMock;

    /**
     * @var StoreManagerInterface|MockObject
     */
    private $storeManagerMock;

    /**
     * @var RequestInterface|MockObject
     */
    private $requestMock;

    /**
     * @var LayoutInterface|MockObject
     */
    private $layoutMock;

    /**
     * @var Product|MockObject
     */
    private $productMock;

    /**
     * @inheritdoc
     */
    protected function setUp(): void
    {
        $objectManager = new ObjectManager($this);

        $jsonHelperMock = $this->createMock(\Magento\Framework\Json\Helper\Data::class);
        $directoryHelperMock = $this->createMock(\Magento\Directory\Helper\Data::class);

        $objects = [
            [
                \Magento\Framework\Json\Helper\Data::class,
                $jsonHelperMock
            ],
            [
                \Magento\Directory\Helper\Data::class,
                $directoryHelperMock
            ]
        ];
        $objectManager->prepareObjectManager($objects);

        $this->contextMock = $this->createMock(Context::class);
        $this->jsonEncoderMock = $this->getMockForAbstractClass(EncoderInterface::class);
        $this->authSessionMock = $this->createMock(Session::class);
        $this->moduleManagerMock = $this->createMock(Manager::class);
        $this->collectionFactoryMock = $this->createMock(CollectionFactory::class);
        $this->helperCatalogMock = $this->createMock(Catalog::class);
        $this->catalogDataMock = $this->createMock(Data::class);
        $this->registryMock = $this->createMock(Registry::class);
        $this->translateInlineMock = $this->getMockForAbstractClass(InlineInterface::class);
        $this->storeManagerMock = $this->getMockForAbstractClass(StoreManagerInterface::class);
        $this->requestMock = $this->getMockForAbstractClass(RequestInterface::class);
        $this->layoutMock = $this->getMockForAbstractClass(LayoutInterface::class);
        $this->productMock = $this->createMock(Product::class);

        $this->contextMock->expects($this->any())
            ->method('getStoreManager')
            ->willReturn($this->storeManagerMock);
        $this->contextMock->expects($this->any())
            ->method('getRequest')
            ->willReturn($this->requestMock);

        $this->tabs = $objectManager->getObject(
            Tabs::class,
            [
                'context' => $this->contextMock,
                'jsonEncoder' => $this->jsonEncoderMock,
                'authSession' => $this->authSessionMock,
                'moduleManager' => $this->moduleManagerMock,
                'collectionFactory' => $this->collectionFactoryMock,
                'helperCatalog' => $this->helperCatalogMock,
                'catalogData' => $this->catalogDataMock,
                'registry' => $this->registryMock,
                'translateInline' => $this->translateInlineMock,
                'data' => [
                    'jsonHelper' => $jsonHelperMock,
                    'directoryHelper' => $directoryHelperMock
                ]
            ]
        );
    }

    /**
     * Test getGroupCollection method
     *
     * @covers \Magento\Catalog\Block\Adminhtml\Product\Edit\Tabs::getGroupCollection
     * @return void
     */
    public function testGetGroupCollection(): void
    {
        $attributeSetId = 5;
        $collectionMock = $this->createMock(Collection::class);

        $this->collectionFactoryMock->expects($this->once())
            ->method('create')
            ->willReturn($collectionMock);

        $collectionMock->expects($this->once())
            ->method('setAttributeSetFilter')
            ->with($attributeSetId)
            ->willReturnSelf();

        $collectionMock->expects($this->once())
            ->method('setSortOrder')
            ->willReturnSelf();

        $collectionMock->expects($this->once())
            ->method('load')
            ->willReturnSelf();

        $result = $this->tabs->getGroupCollection($attributeSetId);
        $this->assertEquals($collectionMock, $result);
    }

    /**
     * Test getProduct returns product from data
     *
     * @covers \Magento\Catalog\Block\Adminhtml\Product\Edit\Tabs::getProduct
     * @return void
     */
    public function testGetProductFromData(): void
    {
        $this->tabs->setData('product', $this->productMock);
        $result = $this->tabs->getProduct();
        $this->assertEquals($this->productMock, $result);
    }

    /**
     * Test getProduct returns product from registry
     *
     * @covers \Magento\Catalog\Block\Adminhtml\Product\Edit\Tabs::getProduct
     * @return void
     */
    public function testGetProductFromRegistry(): void
    {
        $this->registryMock->expects($this->once())
            ->method('registry')
            ->with('product')
            ->willReturn($this->productMock);

        $result = $this->tabs->getProduct();
        $this->assertEquals($this->productMock, $result);
    }

    /**
     * Test getAttributeTabBlock method with various scenarios
     *
     * @param string|null $helperReturn
     * @param string $expectedBlock
     * @dataProvider getAttributeTabBlockDataProvider
     * @covers \Magento\Catalog\Block\Adminhtml\Product\Edit\Tabs::getAttributeTabBlock
     * @return void
     */
    public function testGetAttributeTabBlock(?string $helperReturn, string $expectedBlock): void
    {
        $this->helperCatalogMock->expects($this->any())
            ->method('getAttributeTabBlock')
            ->willReturn($helperReturn);

        $result = $this->tabs->getAttributeTabBlock();
        $this->assertEquals($expectedBlock, $result);
    }

    /**
     * Data provider for testGetAttributeTabBlock
     *
     * @return array
     */
    public static function getAttributeTabBlockDataProvider(): array
    {
        return [
            'custom_block_from_helper' => [
                'helperReturn' => 'Custom\Block\Class',
                'expectedBlock' => 'Custom\Block\Class'
            ],
            'default_block_when_helper_returns_null' => [
                'helperReturn' => null,
                'expectedBlock' => Attributes::class
            ]
        ];
    }

    /**
     * Test setAttributeTabBlock method
     *
     * @covers \Magento\Catalog\Block\Adminhtml\Product\Edit\Tabs::setAttributeTabBlock
     * @covers \Magento\Catalog\Block\Adminhtml\Product\Edit\Tabs::getAttributeTabBlock
     * @return void
     */
    public function testSetAttributeTabBlock(): void
    {
        $customBlock = 'Custom\Block\Class';
        $this->helperCatalogMock->expects($this->any())
            ->method('getAttributeTabBlock')
            ->willReturn(null);

        $result = $this->tabs->setAttributeTabBlock($customBlock);

        $this->assertSame($this->tabs, $result);
        $this->assertEquals($customBlock, $this->tabs->getAttributeTabBlock());
    }

    /**
     * Test isAdvancedTabGroupActive returns true for advanced tab
     *
     * @covers \Magento\Catalog\Block\Adminhtml\Product\Edit\Tabs::isAdvancedTabGroupActive
     * @return void
     */
    public function testIsAdvancedTabGroupActive(): void
    {
        $reflection = new \ReflectionClass($this->tabs);

        $tabDataObjectMock = $this->getMockBuilder(\Magento\Framework\DataObject::class)
            ->disableOriginalConstructor()
            ->addMethods(['getGroupCode'])
            ->getMock();
        $tabDataObjectMock->expects($this->once())
            ->method('getGroupCode')
            ->willReturn(Tabs::ADVANCED_TAB_GROUP_CODE);

        $tabsProperty = $reflection->getProperty('_tabs');
        $tabsProperty->setAccessible(true);
        $tabsProperty->setValue($this->tabs, ['advanced-pricing' => $tabDataObjectMock]);

        $activeTabProperty = $reflection->getProperty('_activeTab');
        $activeTabProperty->setAccessible(true);
        $activeTabProperty->setValue($this->tabs, 'advanced-pricing');

        $result = $this->tabs->isAdvancedTabGroupActive();
        $this->assertTrue($result);
    }

    /**
     * Test isAdvancedTabGroupActive returns false for non-advanced tab
     *
     * @covers \Magento\Catalog\Block\Adminhtml\Product\Edit\Tabs::isAdvancedTabGroupActive
     * @return void
     */
    public function testIsAdvancedTabGroupActiveFalse(): void
    {
        $reflection = new \ReflectionClass($this->tabs);

        $tabDataObjectMock = $this->getMockBuilder(\Magento\Framework\DataObject::class)
            ->disableOriginalConstructor()
            ->addMethods(['getGroupCode'])
            ->getMock();
        $tabDataObjectMock->expects($this->once())
            ->method('getGroupCode')
            ->willReturn(Tabs::BASIC_TAB_GROUP_CODE);

        $tabsProperty = $reflection->getProperty('_tabs');
        $tabsProperty->setAccessible(true);
        $tabsProperty->setValue($this->tabs, ['basic-tab' => $tabDataObjectMock]);

        $activeTabProperty = $reflection->getProperty('_activeTab');
        $activeTabProperty->setAccessible(true);
        $activeTabProperty->setValue($this->tabs, 'basic-tab');

        $result = $this->tabs->isAdvancedTabGroupActive();
        $this->assertFalse($result);
    }

    /**
     * Test getAccordion method
     *
     * @covers \Magento\Catalog\Block\Adminhtml\Product\Edit\Tabs::getAccordion
     * @return void
     */
    public function testGetAccordion(): void
    {
        $parentTabMock = $this->getMockBuilder(Tab::class)
            ->disableOriginalConstructor()
            ->addMethods(['getId'])
            ->getMock();
        $parentTabMock->expects($this->any())
            ->method('getId')
            ->willReturn('parent-tab');

        $childTabMock = $this->getMockBuilder(Tab::class)
            ->disableOriginalConstructor()
            ->addMethods(['getParentTab'])
            ->getMock();
        $childTabMock->expects($this->any())
            ->method('getParentTab')
            ->willReturn('parent-tab');

        $childBlockMock = $this->getMockBuilder(AbstractBlock::class)
            ->disableOriginalConstructor()
            ->addMethods(['setTab'])
            ->onlyMethods(['toHtml'])
            ->getMock();
        $childBlockMock->expects($this->once())
            ->method('setTab')
            ->with($childTabMock)
            ->willReturnSelf();
        $childBlockMock->expects($this->once())
            ->method('toHtml')
            ->willReturn('<div>child tab html</div>');

        $reflection = new \ReflectionClass($this->tabs);
        $tabsProperty = $reflection->getProperty('_tabs');
        $tabsProperty->setAccessible(true);
        $tabsProperty->setValue($this->tabs, [$childTabMock]);

        $tabsMock = $this->getMockBuilder(Tabs::class)
            ->setConstructorArgs([
                $this->contextMock,
                $this->jsonEncoderMock,
                $this->authSessionMock,
                $this->moduleManagerMock,
                $this->collectionFactoryMock,
                $this->helperCatalogMock,
                $this->catalogDataMock,
                $this->registryMock,
                $this->translateInlineMock,
                ['jsonHelper' => $this->createMock(\Magento\Framework\Json\Helper\Data::class),
                'directoryHelper' => $this->createMock(\Magento\Directory\Helper\Data::class)]
            ])
            ->onlyMethods(['getChildBlock'])
            ->getMock();

        $tabsMock->expects($this->once())
            ->method('getChildBlock')
            ->with('child-tab')
            ->willReturn($childBlockMock);

        $tabsProperty->setValue($tabsMock, [$childTabMock]);

        $result = $tabsMock->getAccordion($parentTabMock);
        $this->assertEquals('<div>child tab html</div>', $result);
    }
}
