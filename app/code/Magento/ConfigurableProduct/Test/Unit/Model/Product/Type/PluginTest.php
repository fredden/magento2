<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Magento\ConfigurableProduct\Test\Unit\Model\Product\Type;

use Magento\Catalog\Model\Product\Type;
use Magento\ConfigurableProduct\Model\Product\Type\Plugin;
use Magento\Framework\Module\Manager;
use PHPUnit\Framework\TestCase;

class PluginTest extends TestCase
{
    /**
     * @param array $expected
     * @param array $data
     * @dataProvider afterGetOptionArrayDataProvider
     */
    public function testAfterGetOptionArray(array $expected, array $data)
    {
        if (!empty($data['subject']) && is_callable($data['subject']))
        {
            $data['subject'] = $data['subject']($this);
        }
        $moduleManagerMock = $this->createPartialMock(Manager::class, ['isOutputEnabled']);
        $moduleManagerMock->expects($this->once())
            ->method('isOutputEnabled')
            ->with('Magento_ConfigurableProduct')
            ->willReturn($data['is_module_output_enabled']);

        $model = new Plugin($moduleManagerMock);
        $this->assertEquals(
            $expected,
            $model->afterGetOptionArray($data['subject'], $data['result'])
        );
    }

    protected function getMockForTypeClass()
    {
        $productTypeMock = $this->createMock(Type::class);
        return $productTypeMock;
    }

    /**
     * @return array
     */
    public static function afterGetOptionArrayDataProvider()
    {
        $productTypeMock = static fn (self $testCase) => $testCase->getMockForTypeClass();
        return [
            [
                [
                    'configurable' => true,
                    'not_configurable' => true,
                ],
                [
                    'is_module_output_enabled' => true,
                    'subject' => $productTypeMock,
                    'result' => [
                        'configurable' => true,
                        'not_configurable' => true,
                    ]
                ],
            ],
            [
                [
                    'not_configurable' => true,
                ],
                [
                    'is_module_output_enabled' => false,
                    'subject' => $productTypeMock,
                    'result' => [
                        'configurable' => true,
                        'not_configurable' => true,
                    ]
                ]
            ]
        ];
    }
}
