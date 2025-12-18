<?php
/**
 * Copyright 2025 Adobe
 * All Rights Reserved.
 */
declare(strict_types=1);

namespace Magento\Catalog\Test\Unit\Block\Adminhtml\Product;

use Magento\Catalog\Block\Adminhtml\Product\Price;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Store\Model\Website;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Unit test for Product Price adminhtml block
 *
 * @covers \Magento\Catalog\Block\Adminhtml\Product\Price
 */
class PriceTest extends TestCase
{
    /**
     * @var Price|MockObject
     */
    private MockObject $block;

    /**
     * @var StoreManagerInterface|MockObject
     */
    private MockObject $storeManagerMock;

    /**
     * @inheritdoc
     */
    protected function setUp(): void
    {
        $this->storeManagerMock = $this->getMockForAbstractClass(StoreManagerInterface::class);

        $this->block = $this->getMockBuilder(Price::class)
            ->disableOriginalConstructor()
            ->onlyMethods([])
            ->getMock();

        $reflection = new \ReflectionClass($this->block);
        $storeManagerProperty = $reflection->getProperty('_storeManager');
        $storeManagerProperty->setAccessible(true);
        $storeManagerProperty->setValue($this->block, $this->storeManagerMock);
    }

    /**
     * Data provider for testGetWebsiteReturnsWebsiteForStoreId
     *
     * @return array
     */
    public static function storeIdDataProvider(): array
    {
        return [
            'integer store id' => [
                'storeId' => 1
            ],
            'string store id' => [
                'storeId' => '2'
            ],
            'null store id' => [
                'storeId' => null
            ],
            'boolean false store id' => [
                'storeId' => false
            ]
        ];
    }

    /**
     * Test getWebsite returns website for given store ID
     *
     * @dataProvider storeIdDataProvider
     * @covers \Magento\Catalog\Block\Adminhtml\Product\Price::getWebsite
     * @param int|string|bool|null $storeId
     * @return void
     */
    public function testGetWebsiteReturnsWebsiteForStoreId(
        int|string|bool|null $storeId
    ): void {
        $storeMock = $this->createMock(Store::class);
        $websiteMock = $this->createMock(Website::class);

        $storeMock->expects($this->once())
            ->method('getWebsite')
            ->willReturn($websiteMock);

        $this->storeManagerMock->expects($this->once())
            ->method('getStore')
            ->with($storeId)
            ->willReturn($storeMock);

        $result = $this->block->getWebsite($storeId);

        $this->assertSame($websiteMock, $result);
    }
}
