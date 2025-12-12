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
use Magento\Framework\TestFramework\Unit\Helper\MockCreationTrait;

class PropertiesTest extends TestCase
{
    use MockCreationTrait;
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

        // Use mock instead of ObjectManager::getObject to avoid ObjectManager initialization issues
        $this->propertiesBlock = $this->createPartialMockWithReflection(
            Properties::class,
            ['isHidden', 'getWidgetInstance']
        );
        
        // Override isHidden to use the actual implementation by returning the widget from registry
        $this->propertiesBlock = $this->getMockBuilder(Properties::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['_construct'])
            ->getMock();
        
        // Inject registry via reflection
        $reflection = new \ReflectionClass($this->propertiesBlock);
        $parent = $reflection->getParentClass();
        while ($parent && !$parent->hasProperty('_coreRegistry')) {
            $parent = $parent->getParentClass();
        }
        if ($parent) {
            $property = $parent->getProperty('_coreRegistry');
            $property->setAccessible(true);
            $property->setValue($this->propertiesBlock, $this->registry);
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
