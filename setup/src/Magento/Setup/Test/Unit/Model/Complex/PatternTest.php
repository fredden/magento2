<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Magento\Setup\Test\Unit\Model\Complex;

use Magento\Setup\Model\Complex\Pattern;
use PHPUnit\Framework\TestCase;

class PatternTest extends TestCase
{
    /**
     * Get pattern object
     *
     * @param array $patternData
     *
     * @return Pattern
     */
    protected function getPattern($patternData)
    {
        $pattern = new Pattern();
        $pattern->setHeaders(array_keys($patternData[0]));
        $pattern->setRowsSet($patternData);
        return $pattern;
    }

    /**
     * Data source for pattern
     *
     * @return array
     */
    public static function patternDataProvider()
    {
        $result = [
            0 => [
                [
                    [
                        'id' => '%s',
                        'name' => 'Static',
                        'calculated' => function ($index, $generatedKey) {
                            return $index * 10 + $generatedKey;
                        },
                    ],
                    [
                        'name' => 'xxx %s'
                    ],
                    [
                        'name' => 'yyy %s'
                    ],
                ],
                'expectedRowsCount'      => 3,
                'expectedRowsResult' => [
                    ['id' => '1', 'name' => 'Static', 'calculated' => 10],
                    ['id' => '',  'name' => 'xxx 1',  'calculated' => ''],
                    ['id' => '',  'name' => 'yyy 1',  'calculated' => ''],
                ],
            ],
            1 => [
                [
                    [
                        'id' => '%s',
                        'name' => 'Dynamic %s',
                        'calculated' => 'calc %s',
                    ],
                ],
                'expectedRowsCount' => 1,
                'expectedRowsResult' => [
                    ['id' => '1', 'name' => 'Dynamic 1', 'calculated' => 'calc 1'],
                ],
            ],
        ];
        return $result;
    }

    /**
     * Test pattern object
     *
     * @param array $patternData
     * @param int $expectedRowsCount
     * @param array $expectedRowsResult
     *
     * @dataProvider patternDataProvider
     * @test
     *
     * @return void
     */
    public function testPattern($patternData, $expectedRowsCount, $expectedRowsResult)
    {
        $pattern = $this->getPattern($patternData);
        $this->assertEquals($pattern->getRowsCount(), $expectedRowsCount);
        foreach ($expectedRowsResult as $key => $expectedRow) {
            $this->assertEquals($expectedRow, $pattern->getRow(floor($key / $pattern->getRowsCount()) + 1, $key));
        }
    }
}
