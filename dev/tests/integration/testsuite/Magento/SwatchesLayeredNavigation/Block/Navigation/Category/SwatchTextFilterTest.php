<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Magento\SwatchesLayeredNavigation\Block\Navigation\Category;

use Magento\Catalog\Model\Layer\Filter\AbstractFilter;
use Magento\Catalog\Model\Layer\Resolver;
use Magento\LayeredNavigation\Block\Navigation\AbstractFiltersTest;

/**
 * Provides tests for custom text swatch filter in navigation block on category page.
 *
 * @magentoAppArea frontend
 * @magentoAppIsolation enabled
 * @magentoDbIsolation disabled
 */
class SwatchTextFilterTest extends AbstractFiltersTest
{
    /**
     * @magentoDataFixture Magento/Swatches/_files/product_text_swatch_attribute.php
     * @magentoDataFixture Magento/Catalog/_files/category_with_different_price_products.php
     * @dataProvider getFiltersWithCustomAttributeDataProvider
     * @param array $products
     * @param array $attributeData
     * @param array $expectation
     * @return void
     */
    public function testGetFiltersWithCustomAttribute(array $products, array $attributeData, array $expectation): void
    {
        $this->getCategoryFiltersAndAssert($products, $attributeData, $expectation, 'Category 999');
    }

    /**
     * @return array
     */
    public static function getFiltersWithCustomAttributeDataProvider(): array
    {
        return [
            'not_used_in_navigation' => [
                'products' => [],
                'attributeData' => ['is_filterable' => 0],
                'expectation' => [],
            ],
            'used_in_navigation_with_results' => [
                'products' => [
                    'simple1000' => 'Option 1',
                    'simple1001' => 'Option 2',
                ],
                'attributeData' => ['is_filterable' => AbstractFilter::ATTRIBUTE_OPTIONS_ONLY_WITH_RESULTS],
                'expectation' => [
                    ['label' => 'Option 1', 'count' => 1],
                    ['label' => 'Option 2', 'count' => 1],
                ],
            ],
            'used_in_navigation_without_results' => [
                'products' => [
                    'simple1000' => 'Option 1',
                    'simple1001' => 'Option 2',
                ],
                'attributeData' => ['is_filterable' => 2],
                'expectation' => [
                    ['label' => 'Option 3', 'count' => 0],
                    ['label' => 'Option 1', 'count' => 1],
                    ['label' => 'Option 2', 'count' => 1],
                ],
            ],
        ];
    }

    /**
     * @magentoDataFixture Magento/Swatches/_files/product_text_swatch_attribute.php
     * @magentoDataFixture Magento/Catalog/_files/category_with_different_price_products.php
     * @dataProvider getActiveFiltersWithCustomAttributeDataProvider
     * @param array $products
     * @param array $expectation
     * @param string $filterValue
     * @param int $productsCount
     * @return void
     */
    public function testGetActiveFiltersWithCustomAttribute(
        array $products,
        array $expectation,
        string $filterValue,
        int $productsCount
    ): void {
        $this->getCategoryActiveFiltersAndAssert($products, $expectation, 'Category 999', $filterValue, $productsCount);
    }

    /**
     * @return array
     */
    public static function getActiveFiltersWithCustomAttributeDataProvider(): array
    {
        return [
            'filter_by_first_option_in_products_with_first_option' => [
                'products' => ['simple1000' => 'Option 1', 'simple1001' => 'Option 1'],
                'expectation' => ['label' =>  'Option 1', 'count' => 0],
                'filterValue' =>  'Option 1',
                'productsCount' => 2,
            ],
            'filter_by_first_option_in_products_with_different_options' => [
                'products' => ['simple1000' => 'Option 1', 'simple1001' => 'Option 2'],
                'expectation' => ['label' =>  'Option 1', 'count' => 0],
                'filterValue' =>  'Option 1',
                'productsCount' => 1,
            ],
            'filter_by_second_option_in_products_with_different_options' => [
                'products' => ['simple1000' => 'Option 1', 'simple1001' => 'Option 2'],
                'expectation' => ['label' => 'Option 2', 'count' => 0],
                'filterValue' => 'Option 2',
                'productsCount' => 1,
            ],
        ];
    }

    /**
     * @inheritdoc
     */
    protected function getLayerType(): string
    {
        return Resolver::CATALOG_LAYER_CATEGORY;
    }

    /**
     * @inheritdoc
     */
    protected function getAttributeCode(): string
    {
        return 'text_swatch_attribute';
    }
}
