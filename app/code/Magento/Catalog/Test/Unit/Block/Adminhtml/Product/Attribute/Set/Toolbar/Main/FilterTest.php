<?php
/**
 * Copyright 2025 Adobe
 * All Rights Reserved.
 */
declare(strict_types=1);

namespace Magento\Catalog\Test\Unit\Block\Adminhtml\Product\Attribute\Set\Toolbar\Main;

use Magento\Catalog\Block\Adminhtml\Product\Attribute\Set\Toolbar\Main\Filter;
use Magento\Directory\Helper\Data as DirectoryHelper;
use Magento\Eav\Model\Entity\Attribute\Set as AttributeSet;
use Magento\Eav\Model\Entity\Attribute\SetFactory;
use Magento\Eav\Model\ResourceModel\Entity\Attribute\Set\Collection;
use Magento\Framework\Data\Form;
use Magento\Framework\Data\Form\Element\Select;
use Magento\Framework\Data\FormFactory;
use Magento\Framework\Json\Helper\Data as JsonHelper;
use Magento\Framework\TestFramework\Unit\Helper\ObjectManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Unit test for Filter block
 *
 * @covers \Magento\Catalog\Block\Adminhtml\Product\Attribute\Set\Toolbar\Main\Filter
 */
class FilterTest extends TestCase
{
    /**
     * @var Filter
     */
    private Filter $block;

    /**
     * @var ObjectManager
     */
    private ObjectManager $objectManager;

    /**
     * @var MockObject&FormFactory
     */
    private MockObject $formFactoryMock;

    /**
     * @var MockObject&SetFactory
     */
    private MockObject $setFactoryMock;

    /**
     * @inheritdoc
     */
    protected function setUp(): void
    {
        $this->objectManager = new ObjectManager($this);

        // Prepare ObjectManager for helpers used by parent blocks
        $objects = [
            [JsonHelper::class, $this->createMock(JsonHelper::class)],
            [DirectoryHelper::class, $this->createMock(DirectoryHelper::class)]
        ];
        $this->objectManager->prepareObjectManager($objects);

        $this->formFactoryMock = $this->getMockBuilder(FormFactory::class)
            ->disableOriginalConstructor()
            ->getMock();

        $this->setFactoryMock = $this->getMockBuilder(SetFactory::class)
            ->disableOriginalConstructor()
            ->getMock();

        $this->block = $this->objectManager->getObject(
            Filter::class,
            [
                'formFactory' => $this->formFactoryMock,
                'setFactory' => $this->setFactoryMock
            ]
        );
    }

    /**
     * Data provider for attribute set options scenarios
     *
     * @return array<string, array<string, array<int, array<string, string>>>>
     */
    public static function attributeSetOptionsDataProvider(): array
    {
        return [
            'with attribute set options' => [
                'attributeSetOptions' => [
                    ['value' => '1', 'label' => 'Default'],
                    ['value' => '2', 'label' => 'Custom Set']
                ]
            ],
            'with empty attribute set collection' => [
                'attributeSetOptions' => []
            ]
        ];
    }

    /**
     * Test that _prepareForm creates form with select field for given attribute set options
     *
     * @dataProvider attributeSetOptionsDataProvider
     * @param array<int, array<string, string>> $attributeSetOptions
     * @return void
     */
    public function testPrepareFormCreatesFormWithSelectField(array $attributeSetOptions): void
    {
        $this->setupMocksForPrepareForm($attributeSetOptions);

        $prepareFormMethod = new ReflectionMethod(Filter::class, '_prepareForm');
        $prepareFormMethod->invoke($this->block);

        $this->assertNotNull($this->block->getForm());
    }

    /**
     * Data provider for form configuration tests
     *
     * @return array<string, array<string, string|bool>>
     */
    public static function formConfigurationDataProvider(): array
    {
        return [
            'form uses POST method' => [
                'methodName' => 'setMethod',
                'expectedValue' => 'post'
            ],
            'form enables container usage' => [
                'methodName' => 'setUseContainer',
                'expectedValue' => true
            ]
        ];
    }

    /**
     * Test that form is configured with correct settings
     *
     * @dataProvider formConfigurationDataProvider
     * @param string $methodName
     * @param string|bool $expectedValue
     * @return void
     */
    public function testPrepareFormConfiguresFormCorrectly(string $methodName, string|bool $expectedValue): void
    {
        $formMock = $this->setupMocksForPrepareForm([]);

        $formMock->expects($this->once())
            ->method($methodName)
            ->with($expectedValue);

        $prepareFormMethod = new ReflectionMethod(Filter::class, '_prepareForm');
        $prepareFormMethod->invoke($this->block);
    }

    /**
     * Data provider for select field configuration tests
     *
     * @return array<string, array<string, string|bool>>
     */
    public static function selectFieldConfigurationDataProvider(): array
    {
        return [
            'onchange handler for form submission' => [
                'configKey' => 'onchange',
                'expectedValue' => 'this.form.submit()'
            ],
            'field is required' => [
                'configKey' => 'required',
                'expectedValue' => true
            ],
            'correct CSS class' => [
                'configKey' => 'class',
                'expectedValue' => 'left-col-block'
            ],
            'no_span option enabled' => [
                'configKey' => 'no_span',
                'expectedValue' => true
            ],
            'correct field name' => [
                'configKey' => 'name',
                'expectedValue' => 'set_switcher'
            ]
        ];
    }

    /**
     * Test that select field has correct configuration
     *
     * @dataProvider selectFieldConfigurationDataProvider
     * @param string $configKey
     * @param string|bool $expectedValue
     * @return void
     */
    public function testSelectFieldHasCorrectConfiguration(string $configKey, string|bool $expectedValue): void
    {
        $selectElementMock = $this->createMock(Select::class);
        $formMock = $this->setupMocksForPrepareForm([]);

        $formMock->expects($this->once())
            ->method('addField')
            ->with(
                'set_switcher',
                'select',
                $this->callback(function (array $config) use ($configKey, $expectedValue) {
                    return isset($config[$configKey]) && $config[$configKey] === $expectedValue;
                })
            )
            ->willReturn($selectElementMock);

        $prepareFormMethod = new ReflectionMethod(Filter::class, '_prepareForm');
        $prepareFormMethod->invoke($this->block);
    }

    /**
     * Setup mocks for _prepareForm method testing
     *
     * Creates and configures all necessary mocks for testing the protected _prepareForm method.
     *
     * @param array<int, array<string, string>> $attributeSetOptions Attribute set options to return from collection
     * @return MockObject&Form Configured form mock
     */
    private function setupMocksForPrepareForm(array $attributeSetOptions): MockObject
    {
        $collectionMock = $this->getMockBuilder(Collection::class)
            ->disableOriginalConstructor()
            ->getMock();
        $collectionMock->method('load')->willReturnSelf();
        $collectionMock->method('toOptionArray')->willReturn($attributeSetOptions);

        $attributeSetMock = $this->getMockBuilder(AttributeSet::class)
            ->disableOriginalConstructor()
            ->getMock();
        $attributeSetMock->method('getResourceCollection')
            ->willReturn($collectionMock);

        $this->setFactoryMock->method('create')
            ->willReturn($attributeSetMock);

        $selectElementMock = $this->createMock(Select::class);

        $formMock = $this->getMockBuilder(Form::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['addField'])
            ->addMethods(['setUseContainer', 'setMethod'])
            ->getMock();

        $formMock->method('addField')->willReturn($selectElementMock);
        $formMock->method('setUseContainer')->willReturnSelf();
        $formMock->method('setMethod')->willReturnSelf();

        $this->formFactoryMock->method('create')
            ->willReturn($formMock);

        return $formMock;
    }
}
