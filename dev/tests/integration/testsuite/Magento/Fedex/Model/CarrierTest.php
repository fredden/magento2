<?php
/**
 * Copyright 2014 Adobe
 * All Rights Reserved.
 */

namespace Magento\Fedex\Model;

class CarrierTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var \Magento\Fedex\Model\Carrier
     */
    protected $_model;

    protected function setUp(): void
    {
        $this->_model = \Magento\TestFramework\Helper\Bootstrap::getObjectManager()->create(
            \Magento\Fedex\Model\Carrier::class
        );
    }

    /**
     * @dataProvider getCodeDataProvider
     * @param string $type
     * @param int $expectedCount
     */
    public function testGetCode($type, $expectedCount)
    {
        $result = $this->_model->getCode($type);
        $this->assertCount($expectedCount, $result);
    }

    /**
     * Data Provider for testGetCode
     * @return array
     */
    public static function getCodeDataProvider()
    {
        return [
            ['method', 28],
            ['dropoff', 5],
            ['packaging', 7],
            ['containers_filter', 4],
            ['delivery_confirmation_types', 4],
            ['unit_of_measure', 2],
        ];
    }

    /**
     * @dataProvider getCodeUnitOfMeasureDataProvider
     * @param string $code
     */
    public function testGetCodeUnitOfMeasure($code)
    {
        $result = $this->_model->getCode('unit_of_measure', $code);
        $this->assertNotEmpty($result);
    }

    /**
     * Data Provider for testGetCodeUnitOfMeasure
     * @return array
     */
    public static function getCodeUnitOfMeasureDataProvider()
    {
        return [
            ['LB'],
            ['KG'],
        ];
    }
}
