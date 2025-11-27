<?php
/**
 * Copyright 2025 Adobe
 * All Rights Reserved.
 */

declare(strict_types=1);

namespace Magento\Catalog\Model\ResourceModel\Product\Compare\Item;

use Magento\TestFramework\Helper\Bootstrap;

/**
 * Test for Magento\Catalog\Model\ResourceModel\Product\Compare\Item\Collection
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class CollectionTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var \Magento\Catalog\Model\ResourceModel\Product\Compare\Item\Collection
     */
    protected $collection;

    /**
     * Sets up the fixture, for example, opens a network connection.
     * This method is called before a test is executed.
     */
    protected function setUp(): void
    {
        $this->collection = Bootstrap::getObjectManager()->create(
            \Magento\Catalog\Model\ResourceModel\Product\Compare\Item\Collection::class
        );
    }

    /**
     * Checks if join set compare list id to null if visitor id is empty/null.
     *
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function testJoinTable()
    {
        $this->collection->setVisitorId(0);
        $sql = (string) $this->collection->getSelect();
        $productTable = $this->collection->getTable('catalog_product_entity');
        $compareTable = $this->collection->getTable('catalog_compare_item');

        // phpcs:ignore Magento2.SQL.RawQuery
        $expected = 'SELECT `e`.*, `t_compare`.`product_id`, `t_compare`.`customer_id`, `t_compare`.`visitor_id`, '
        . '`t_compare`.`store_id` AS `item_store_id`, `t_compare`.`catalog_compare_item_id` FROM `' . $productTable
        . '` AS `e` INNER JOIN `' . $compareTable . '` AS `t_compare` '
        . 'ON (t_compare.product_id=e.entity_id) AND (t_compare.customer_id IS NULL) '
        . 'AND (t_compare.visitor_id = \'0\') AND (t_compare.list_id IS NULL)';

        self::assertStringContainsString($expected, str_replace(PHP_EOL, '', $sql));
    }
}
