<?php
/**
 * Copyright 2015 Adobe
 * All Rights Reserved.
 */
declare(strict_types=1);

namespace Magento\Widget\Test\Unit\Block\Adminhtml\Widget\Instance\Edit\Tab;

use Magento\Framework\Registry;
use Magento\Framework\TestFramework\Unit\Helper\ObjectManager;
use Magento\Widget\Block\Adminhtml\Widget\Instance\Edit\Tab\Properties;
use Magento\Widget\Model\Widget\Instance;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class PropertiesTest extends TestCase
{
    /**
     * @var MockObject
     */
    protected $widget;

    /**
     * @var MockObject
     */
    protected $registry;

    /**
     * @var Properties
     */
    protected $propertiesBlock;

    protected function setUp(): void
    {
        $this->widget = $this->createMock(Instance::class);
        $this->registry = $this->createMock(Registry::class);

        $this->propertiesBlock = $this->getMockBuilder(Properties::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['_construct'])
            ->getMock();
        
        $this->setPropertyValue($this->propertiesBlock, '_coreRegistry', $this->registry);
    }
    
    /**
     * Set property value searching through class hierarchy
     */
    private function setPropertyValue(object $object, string $propertyName, mixed $value): void
    {
        $class = new \ReflectionClass($object);
        while ($class) {
            if ($class->hasProperty($propertyName)) {
                $class->getProperty($propertyName)->setValue($object, $value);
                return;
            }
            $class = $class->getParentClass();
        }
    }

    /**
     * @param array $widgetConfig
     * @param boolean $isHidden
     */
    #[DataProvider('isHiddenDataProvider')]
    public function testIsHidden($widgetConfig, $isHidden)
    {
        $this->widget->expects($this->atLeastOnce())->method('getWidgetConfigAsArray')->willReturn($widgetConfig);

        $this->registry->expects($this->atLeastOnce())
            ->method('registry')
            ->with('current_widget_instance')
            ->willReturn($this->widget);

        $this->assertEquals($isHidden, $this->propertiesBlock->isHidden());
    }

    /**
     * @return array
     */
    public static function isHiddenDataProvider()
    {
        return [
            [
                'widgetConfig' => [
                    'parameters' => [
                        'title' => [
                            'type' => 'text',
                            'visible' => '0',
                        ],
                        'template' => [
                            'type' => 'select',
                            'visible' => '1',
                        ],
                    ]
                ],
                'isHidden' => true
            ],
            [
                'widgetConfig' => [
                    'parameters' => [
                        'types' => [
                            'type' => 'multiselect',
                            'visible' => '1',
                        ],
                        'template' => [
                            'type' => 'select',
                            'visible' => '1',
                        ],
                    ]
                ],
                'isHidden' => false
            ],
            [
                'widgetConfig' => [],
                'isHidden' => true
            ],
            [
                'widgetConfig' => [
                    'parameters' => [
                        'template' => [
                            'type' => 'select',
                            'visible' => '0',
                        ],
                    ]
                ],
                'isHidden' => true
            ]
        ];
    }
}
