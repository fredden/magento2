<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Magento\Swatches\Model;

use Magento\Catalog\Model\Product\Attribute\Save\AbstractAttributeTest;

/**
 * @magentoDbIsolation enabled
 * @magentoDataFixture Magento/Swatches/_files/product_text_swatch_attribute.php
 * @magentoDataFixture Magento/Catalog/_files/second_product_simple.php
 */
class AttributeTextSwatchTest extends AbstractAttributeTest
{
    /**
     * @inheritdoc
     */
    protected function getAttributeCode(): string
    {
        return 'text_swatch_attribute';
    }

    /**
     * @inheritdoc
     */
    protected function getDefaultAttributeValue(): string
    {
        return $this->getAttribute()->getSource()->getOptionId('Option 2');
    }

    /**
     * @magentoDataFixture Magento/Swatches/_files/product_text_swatch_attribute.php
     * @magentoDataFixture Magento/Catalog/_files/second_product_simple.php
     * @magentoDataFixture Magento/Catalog/_files/product_simple_out_of_stock.php
     * @dataProvider uniqueAttributeValueProvider
     * phpcs:disable Generic.CodeAnalysis.UselessOverridingMethod
     * @inheritdoc
     */
    public function testUniqueAttribute(string $firstSku, string $secondSku): void
    {
        parent::testUniqueAttribute($firstSku, $secondSku);
    }

    /**
     * @inheritdoc
     */
    public static function productProvider(): array
    {
        return [
            [
                'productSku' => 'simple2',
            ],
        ];
    }

    /**
     * @inheritdoc
     */
    public static function uniqueAttributeValueProvider(): array
    {
        return [
            [
                'firstSku' => 'simple2',
                'secondSku' => 'simple-out-of-stock',
            ],
        ];
    }
}
