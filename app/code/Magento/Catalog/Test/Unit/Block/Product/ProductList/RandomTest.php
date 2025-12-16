<?php
/**
 * Copyright 2025 Adobe
 * All Rights Reserved.
 */
declare(strict_types=1);

namespace Magento\Catalog\Test\Unit\Block\Product\ProductList;

use Magento\Catalog\Api\CategoryRepositoryInterface;
use Magento\Catalog\Block\Product\Context;
use Magento\Catalog\Block\Product\ProductList\Random;
use Magento\Catalog\Helper\Output as OutputHelper;
use Magento\Catalog\Model\Layer;
use Magento\Catalog\Model\Layer\Resolver;
use Magento\Catalog\Model\ResourceModel\Product\Collection;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory;
use Magento\Catalog\Pricing\Price\SpecialPriceBulkResolverInterface;
use Magento\Framework\Data\Helper\PostHelper;
use Magento\Framework\DB\Select;
use Magento\Framework\TestFramework\Unit\Helper\ObjectManager;
use Magento\Framework\Url\Helper\Data as UrlHelper;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Unit test for Random product list block
 *
 * @covers \Magento\Catalog\Block\Product\ProductList\Random
 */
class RandomTest extends TestCase
{
    /**
     * @var ObjectManager
     */
    private ObjectManager $objectManager;

    /**
     * @var Random
     */
    private Random $block;

    /**
     * @var Context|MockObject
     */
    private MockObject $contextMock;

    /**
     * @var Layer|MockObject
     */
    private MockObject $layerMock;

    /**
     * @var CollectionFactory|MockObject
     */
    private MockObject $productCollectionFactoryMock;

    /**
     * @var Collection|MockObject
     */
    private MockObject $productCollectionMock;

    /**
     * @var Select|MockObject
     */
    private MockObject $selectMock;

    /**
     * @inheritdoc
     */
    protected function setUp(): void
    {
        $this->objectManager = new ObjectManager($this);

        $this->productCollectionFactoryMock = $this->createMock(CollectionFactory::class);
        $this->productCollectionMock = $this->createMock(Collection::class);
        $this->selectMock = $this->createMock(Select::class);

        $this->productCollectionMock->method('getSelect')
            ->willReturn($this->selectMock);

        $outputHelperMock = $this->createMock(OutputHelper::class);
        $specialPriceBulkResolverMock = $this->createMock(SpecialPriceBulkResolverInterface::class);

        $this->objectManager->prepareObjectManager([
            [SpecialPriceBulkResolverInterface::class, $specialPriceBulkResolverMock],
            [OutputHelper::class, $outputHelperMock],
            [CollectionFactory::class, $this->productCollectionFactoryMock],
        ]);

        $this->contextMock = $this->createMock(Context::class);
        $this->layerMock = $this->createMock(Layer::class);

        $layerResolverMock = $this->createMock(Resolver::class);
        $layerResolverMock->method('get')
            ->willReturn($this->layerMock);

        $postDataHelperMock = $this->createMock(PostHelper::class);
        $categoryRepositoryMock = $this->createMock(CategoryRepositoryInterface::class);
        $urlHelperMock = $this->createMock(UrlHelper::class);

        $this->block = $this->objectManager->getObject(
            Random::class,
            [
                'context' => $this->contextMock,
                'postDataHelper' => $postDataHelperMock,
                'layerResolver' => $layerResolverMock,
                'categoryRepository' => $categoryRepositoryMock,
                'urlHelper' => $urlHelperMock,
                'productCollectionFactory' => $this->productCollectionFactoryMock,
            ]
        );
    }

    /**
     * @inheritdoc
     */
    protected function tearDown(): void
    {
        unset($this->block);
    }

    /**
     * Test that getLoadedProductCollection creates and configures product collection
     *
     * @covers \Magento\Catalog\Block\Product\ProductList\Random::_getProductCollection
     * @return void
     */
    public function testGetLoadedProductCollectionCreatesAndConfiguresCollection(): void
    {
        $numProducts = 5;
        $this->block->setData('num_products', $numProducts);

        $this->productCollectionFactoryMock->expects($this->once())
            ->method('create')
            ->willReturn($this->productCollectionMock);

        $this->layerMock->expects($this->once())
            ->method('prepareProductCollection')
            ->with($this->productCollectionMock);

        $this->selectMock->expects($this->once())
            ->method('order')
            ->with('rand()');

        $this->productCollectionMock->expects($this->once())
            ->method('addStoreFilter');

        $this->productCollectionMock->expects($this->once())
            ->method('setPage')
            ->with(1, $numProducts);

        $result = $this->block->getLoadedProductCollection();

        $this->assertSame($this->productCollectionMock, $result);
    }

    /**
     * Test that getLoadedProductCollection caches collection on subsequent calls
     *
     * @covers \Magento\Catalog\Block\Product\ProductList\Random::_getProductCollection
     * @return void
     */
    public function testGetLoadedProductCollectionCachesCollection(): void
    {
        $this->productCollectionFactoryMock->expects($this->once())
            ->method('create')
            ->willReturn($this->productCollectionMock);

        $firstResult = $this->block->getLoadedProductCollection();
        $secondResult = $this->block->getLoadedProductCollection();

        $this->assertSame($firstResult, $secondResult);
    }

    /**
     * Test getLoadedProductCollection with different numProducts values
     *
     * @dataProvider numProductsDataProvider
     * @covers \Magento\Catalog\Block\Product\ProductList\Random::_getProductCollection
     * @param int|null $numProducts
     * @param int $expectedPageSize
     * @return void
     */
    public function testGetLoadedProductCollectionWithNumProducts(
        ?int $numProducts,
        int $expectedPageSize
    ): void {
        if ($numProducts !== null) {
            $this->block->setData('num_products', $numProducts);
        }

        $this->productCollectionFactoryMock->expects($this->once())
            ->method('create')
            ->willReturn($this->productCollectionMock);

        $this->productCollectionMock->expects($this->once())
            ->method('setPage')
            ->with(1, $expectedPageSize);

        $this->block->getLoadedProductCollection();
    }

    /**
     * Data provider for numProducts test scenarios
     *
     * @return array
     */
    public static function numProductsDataProvider(): array
    {
        return [
            'with positive numProducts' => [
                'numProducts' => 10,
                'expectedPageSize' => 10
            ],
            'with zero numProducts' => [
                'numProducts' => 0,
                'expectedPageSize' => 0
            ],
            'with null numProducts defaults to zero' => [
                'numProducts' => null,
                'expectedPageSize' => 0
            ]
        ];
    }
}
