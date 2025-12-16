<?php

/**
 * Copyright 2025 Adobe
 * All Rights Reserved.
 */

declare(strict_types=1);

namespace Magento\Catalog\Test\Unit\Block\Adminhtml\Product\Frontend\Product;

use Magento\Backend\Block\Context;
use Magento\Catalog\Block\Adminhtml\Product\Frontend\Product\Watermark;
use Magento\Catalog\Model\Config\Source\Watermark\Position;
use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Data\Form;
use Magento\Framework\Data\Form\Element\AbstractElement;
use Magento\Framework\Data\Form\Element\Factory;
use Magento\Framework\Data\Form\Element\Imagefile;
use Magento\Framework\Data\Form\Element\Select;
use Magento\Framework\Data\Form\Element\Text;
use Magento\Framework\TestFramework\Unit\Helper\ObjectManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Unit test for Watermark class
 *
 * @covers \Magento\Catalog\Block\Adminhtml\Product\Frontend\Product\Watermark
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class WatermarkTest extends TestCase
{
    /**
     * @var Watermark
     */
    private Watermark $watermark;

    /**
     * @var Context|MockObject
     */
    private $contextMock;

    /**
     * @var Position|MockObject
     */
    private $watermarkPositionMock;

    /**
     * @var Field|MockObject
     */
    private $formFieldMock;

    /**
     * @var Factory|MockObject
     */
    private $elementFactoryMock;

    /**
     * @var RequestInterface|MockObject
     */
    private $requestMock;

    /**
     * @inheritdoc
     *
     * @return void
     */
    protected function setUp(): void
    {
        $objectManager = new ObjectManager($this);

        $this->contextMock = $this->createMock(Context::class);
        $this->watermarkPositionMock = $this->createMock(Position::class);
        $this->formFieldMock = $this->createMock(Field::class);
        $this->elementFactoryMock = $this->createMock(Factory::class);
        $this->requestMock = $this->getMockForAbstractClass(RequestInterface::class);

        $this->contextMock->expects($this->any())
            ->method('getRequest')
            ->willReturn($this->requestMock);

        $imageTypes = [
            'thumbnail' => ['title' => 'Thumbnail'],
            'small_image' => ['title' => 'Small Image'],
            'image' => ['title' => 'Base Image']
        ];

        $this->watermark = $objectManager->getObject(
            Watermark::class,
            [
                'context' => $this->contextMock,
                'watermarkPosition' => $this->watermarkPositionMock,
                'formField' => $this->formFieldMock,
                'elementFactory' => $this->elementFactoryMock,
                'imageTypes' => $imageTypes
            ]
        );
    }

    /**
     * Create element mock
     *
     * @return MockObject
     */
    private function createElementMock(): MockObject
    {
        $elementMock = $this->getMockBuilder(AbstractElement::class)
            ->disableOriginalConstructor()
            ->addMethods(['getLegend'])
            ->onlyMethods(['getHtmlId'])
            ->getMock();
        $elementMock->expects($this->any())->method('getLegend')->willReturn('Watermark Settings');
        $elementMock->expects($this->any())->method('getHtmlId')->willReturn('watermark_fieldset');
        return $elementMock;
    }

    /**
     * Create text field mock
     *
     * @return MockObject
     */
    private function createTextFieldMock(): MockObject
    {
        $mock = $this->getMockBuilder(Text::class)
            ->disableOriginalConstructor()
            ->addMethods(['setName', 'setLabel'])
            ->onlyMethods(['setForm', 'setRenderer', 'toHtml'])
            ->getMock();
        $mock->expects($this->any())->method('setName')->willReturnSelf();
        $mock->expects($this->any())->method('setForm')->willReturnSelf();
        $mock->expects($this->any())->method('setLabel')->willReturnSelf();
        $mock->expects($this->any())->method('setRenderer')->willReturnSelf();
        $mock->expects($this->any())->method('toHtml')->willReturn('<div>text field</div>');
        return $mock;
    }

    /**
     * Create image field mock
     *
     * @return MockObject
     */
    private function createImageFieldMock(): MockObject
    {
        $mock = $this->getMockBuilder(Imagefile::class)
            ->disableOriginalConstructor()
            ->addMethods(['setName', 'setLabel'])
            ->onlyMethods(['setForm', 'setRenderer', 'toHtml'])
            ->getMock();
        $mock->expects($this->any())->method('setName')->willReturnSelf();
        $mock->expects($this->any())->method('setForm')->willReturnSelf();
        $mock->expects($this->any())->method('setLabel')->willReturnSelf();
        $mock->expects($this->any())->method('setRenderer')->willReturnSelf();
        $mock->expects($this->any())->method('toHtml')->willReturn('<div>image field</div>');
        return $mock;
    }

    /**
     * Create select field mock
     *
     * @return MockObject
     */
    private function createSelectFieldMock(): MockObject
    {
        $mock = $this->getMockBuilder(Select::class)
            ->disableOriginalConstructor()
            ->addMethods(['setName', 'setLabel', 'setValues'])
            ->onlyMethods(['setForm', 'setRenderer', 'toHtml'])
            ->getMock();
        $mock->expects($this->any())->method('setName')->willReturnSelf();
        $mock->expects($this->any())->method('setForm')->willReturnSelf();
        $mock->expects($this->any())->method('setLabel')->willReturnSelf();
        $mock->expects($this->any())->method('setRenderer')->willReturnSelf();
        $mock->expects($this->any())->method('setValues')->willReturnSelf();
        $mock->expects($this->any())->method('toHtml')->willReturn('<div>select field</div>');
        return $mock;
    }

    /**
     * Setup element factory mock
     *
     * @return void
     */
    private function setupElementFactoryMock(): void
    {
        $textFieldMock = $this->createTextFieldMock();
        $imageFieldMock = $this->createImageFieldMock();
        $selectFieldMock = $this->createSelectFieldMock();

        $this->elementFactoryMock->expects($this->exactly(9))
            ->method('create')
            ->willReturnCallback(function ($type) use ($textFieldMock, $imageFieldMock, $selectFieldMock) {
                if ($type === 'text') {
                    return $textFieldMock;
                } elseif ($type === 'imagefile') {
                    return $imageFieldMock;
                } elseif ($type === 'select') {
                    return $selectFieldMock;
                }
                return null;
            });
    }

    /**
     * Test render method with various scopes
     *
     * @param string|null $websiteParam
     * @param string|null $storeParam
     * @param bool $expectUseDefault
     * @param array $expectedContains
     * @dataProvider renderDataProvider
     * @covers \Magento\Catalog\Block\Adminhtml\Product\Frontend\Product\Watermark::render
     * @return void
     */
    public function testRender(
        ?string $websiteParam,
        ?string $storeParam,
        bool $expectUseDefault,
        array $expectedContains
    ): void {
        $elementMock = $this->createElementMock();
        $this->watermark->setForm($this->createMock(Form::class));

        $this->requestMock->expects($this->atLeastOnce())
            ->method('getParam')
            ->willReturnMap([
                ['website', null, $websiteParam],
                ['store', null, $storeParam]
            ]);

        $this->watermarkPositionMock->expects($this->exactly(3))
            ->method('toOptionArray')
            ->willReturn([
                ['value' => 'stretch', 'label' => 'Stretch'],
                ['value' => 'center', 'label' => 'Center']
            ]);

        $this->setupElementFactoryMock();

        $result = $this->watermark->render($elementMock);

        $this->assertStringContainsString('Watermark Settings', $result);
        $this->assertStringContainsString('watermark_fieldset', $result);
        $this->assertStringContainsString('</fieldset>', $result);

        foreach ($expectedContains as $expected) {
            $this->assertStringContainsString($expected, $result);
        }

        if ($expectUseDefault) {
            $this->assertStringContainsString('use-default', $result);
        }
    }

    /**
     * Data provider for testRender
     *
     * @return array
     */
    public static function renderDataProvider(): array
    {
        return [
            'default_scope' => [
                'websiteParam' => null,
                'storeParam' => null,
                'expectUseDefault' => false,
                'expectedContains' => [
                    '<div>text field</div>',
                    '<div>image field</div>',
                    '<div>select field</div>'
                ]
            ],
            'website_scope' => [
                'websiteParam' => 'base',
                'storeParam' => null,
                'expectUseDefault' => true,
                'expectedContains' => []
            ],
            'store_scope' => [
                'websiteParam' => null,
                'storeParam' => 'default',
                'expectUseDefault' => true,
                'expectedContains' => []
            ]
        ];
    }

    /**
     * Test render with empty image types
     *
     * @covers \Magento\Catalog\Block\Adminhtml\Product\Frontend\Product\Watermark::render
     * @return void
     */
    public function testRenderWithEmptyImageTypes(): void
    {
        $objectManager = new ObjectManager($this);
        $watermarkEmpty = $objectManager->getObject(
            Watermark::class,
            [
                'context' => $this->contextMock,
                'watermarkPosition' => $this->watermarkPositionMock,
                'formField' => $this->formFieldMock,
                'elementFactory' => $this->elementFactoryMock,
                'imageTypes' => []
            ]
        );

        $elementMock = $this->createElementMock();
        $watermarkEmpty->setForm($this->createMock(Form::class));

        $this->requestMock->expects($this->atLeastOnce())
            ->method('getParam')
            ->willReturnMap([
                ['website', null, null],
                ['store', null, null]
            ]);

        $this->elementFactoryMock->expects($this->never())->method('create');

        $result = $watermarkEmpty->render($elementMock);

        $this->assertStringContainsString('Watermark Settings', $result);
        $this->assertStringContainsString('</fieldset>', $result);
        $this->assertStringNotContainsString('<div>text field</div>', $result);
    }
}
