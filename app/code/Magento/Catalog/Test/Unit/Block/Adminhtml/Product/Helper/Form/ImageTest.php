<?php
/**
 * Copyright 2025 Adobe
 * All Rights Reserved.
 */
declare(strict_types=1);

namespace Magento\Catalog\Test\Unit\Block\Adminhtml\Product\Helper\Form;

use Magento\Catalog\Block\Adminhtml\Product\Helper\Form\Image;
use Magento\Catalog\Model\ResourceModel\Eav\Attribute;
use Magento\Framework\Data\Form;
use Magento\Framework\Data\Form\Element\CollectionFactory;
use Magento\Framework\Data\Form\Element\Factory;
use Magento\Framework\Escaper;
use Magento\Framework\TestFramework\Unit\Helper\ObjectManager;
use Magento\Framework\UrlInterface;
use Magento\Framework\View\Helper\SecureHtmlRenderer;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class ImageTest extends TestCase
{
    /**
     * @var Image
     */
    private Image $model;

    /**
     * @var Factory|MockObject
     */
    private Factory|MockObject $factoryElement;

    /**
     * @var CollectionFactory|MockObject
     */
    private CollectionFactory|MockObject $factoryCollection;

    /**
     * @var Escaper|MockObject
     */
    private Escaper|MockObject $escaper;

    /**
     * @var UrlInterface|MockObject
     */
    private UrlInterface|MockObject $urlBuilder;

    /**
     * @var SecureHtmlRenderer|MockObject
     */
    private SecureHtmlRenderer|MockObject $secureRenderer;

    /**
     * @var ObjectManager
     */
    private ObjectManager $objectManager;

    private function createFormMock(): Form
    {
        $form = $this->getMockBuilder(Form::class)
            ->disableOriginalConstructor()
            ->addMethods(['getHtmlIdPrefix', 'getHtmlIdSuffix'])
            ->getMock();
        $form->method('getHtmlIdPrefix')->willReturn('');
        $form->method('getHtmlIdSuffix')->willReturn('');

        return $form;
    }

    private function invokeProtected(string $methodName)
    {
        $reflection = new \ReflectionClass($this->model);
        $method = $reflection->getMethod($methodName);
        $method->setAccessible(true);

        return $method->invoke($this->model);
    }

    protected function setUp(): void
    {
        $this->factoryElement = $this->createMock(Factory::class);
        $this->factoryCollection = $this->createMock(CollectionFactory::class);
        $this->escaper = $this->createMock(Escaper::class);
        $this->urlBuilder = $this->getMockForAbstractClass(UrlInterface::class);
        $this->secureRenderer = $this->createMock(SecureHtmlRenderer::class);

        $this->objectManager = new ObjectManager($this);
        
        // Prepare ObjectManager for the parent class (Image) that uses ObjectManager::getInstance()
        $objects = [
            [
                SecureHtmlRenderer::class,
                $this->secureRenderer
            ]
        ];
        $this->objectManager->prepareObjectManager($objects);
        
        $this->model = $this->objectManager->getObject(
            Image::class,
            [
                'factoryElement' => $this->factoryElement,
                'factoryCollection' => $this->factoryCollection,
                'escaper' => $this->escaper,
                'urlBuilder' => $this->urlBuilder,
                'data' => [],
                'secureRenderer' => $this->secureRenderer
            ]
        );
    }

    /**
     * Test _getUrl returns proper URL when value is set
     */
    public function testGetUrlWithValue()
    {
        $imageValue = 'test/image.jpg';
        $baseUrl = 'http://example.com/pub/media/';
        $expectedUrl = $baseUrl . 'catalog/product/' . $imageValue;

        $this->model->setValue($imageValue);

        $this->urlBuilder->expects($this->once())
            ->method('getBaseUrl')
            ->with(['_type' => UrlInterface::URL_TYPE_MEDIA])
            ->willReturn($baseUrl);

        $result = $this->invokeProtected('_getUrl');
        $this->assertSame($expectedUrl, $result);
    }

    /**
     * Test _getUrl returns false when no value is set
     */
    public function testGetUrlWithoutValue()
    {
        $this->model->setValue(null);

        $this->urlBuilder->expects($this->never())
            ->method('getBaseUrl');

        $result = $this->invokeProtected('_getUrl');
        $this->assertSame(false, $result);
    }

    /**
     * Test _getUrl returns false when value is empty string
     */
    public function testGetUrlWithEmptyValue()
    {
        $this->model->setValue('');

        $this->urlBuilder->expects($this->never())
            ->method('getBaseUrl');

        $result = $this->invokeProtected('_getUrl');
        $this->assertSame(false, $result);
    }

    /**
     * Test _getDeleteCheckbox returns parent checkbox when attribute is not required
     */
    public function testGetDeleteCheckboxWithNonRequiredAttribute()
    {
        $attribute = $this->createMock(Attribute::class);
        $attribute->expects($this->once())
            ->method('getIsRequired')
            ->willReturn(false);

        $this->model->setEntityAttribute($attribute);

        $result = $this->invokeProtected('_getDeleteCheckbox');

        // Should return parent's delete checkbox (empty string in this test scenario)
        $this->assertIsString($result);
    }

    /**
     * Test _getDeleteCheckbox returns hidden input when attribute is required
     */
    public function testGetDeleteCheckboxWithRequiredAttribute()
    {
        $htmlId = 'test_image';
        $imageValue = 'test/image.jpg';

        $attribute = $this->createMock(Attribute::class);
        $attribute->expects($this->once())
            ->method('getIsRequired')
            ->willReturn(true);

        $this->model->setForm($this->createFormMock());
        $this->model->setEntityAttribute($attribute);
        $this->model->setId($htmlId);
        $this->model->setValue($imageValue);

        $this->secureRenderer->expects($this->once())
            ->method('renderTag')
            ->with('script', [], $this->anything(), false)
            ->willReturn('<script type="text/x-magento-template">test_script</script>');

        $result = $this->invokeProtected('_getDeleteCheckbox');

        $this->assertStringContainsString('type="hidden"', $result);
        $this->assertStringContainsString('class="required-entry"', $result);
        $this->assertStringContainsString('_hidden', $result);
        $this->assertStringContainsString($imageValue, $result);
        $this->assertStringContainsString('text/x-magento-template', $result);
    }

    /**
     * Test _getDeleteCheckbox returns parent checkbox when no attribute is set
     */
    public function testGetDeleteCheckboxWithoutAttribute()
    {
        $this->model->setEntityAttribute(null);

        $result = $this->invokeProtected('_getDeleteCheckbox');

        // Should return parent's delete checkbox
        $this->assertIsString($result);
    }

    /**
     * Test _getDeleteCheckbox includes syncOnchangeValue JavaScript for required attribute
     *
     * Uses pattern-based assertions to verify JavaScript functionality
     * without triggering static analysis inline JS warnings
     */
    public function testGetDeleteCheckboxIncludesJavaScriptForRequiredAttribute()
    {
        $htmlId = 'test_image';

        $attribute = $this->createMock(Attribute::class);
        $attribute->expects($this->once())
            ->method('getIsRequired')
            ->willReturn(true);

        $this->model->setForm($this->createFormMock());
        $this->model->setEntityAttribute($attribute);
        $this->model->setId($htmlId);
        $this->model->setValue('test.jpg');

        $this->secureRenderer->expects($this->once())
            ->method('renderTag')
            ->with('script', [], $this->callback(function ($content) {
                // Check for pattern without creating inline JS string
                return strpos($content, 'syncOnchangeValue') !== false;
            }), false)
            ->willReturn('<script type="text/x-magento-template">test</script>');

        $result = $this->invokeProtected('_getDeleteCheckbox');

        $this->assertNotEmpty($result);
        $this->assertStringContainsString('type="hidden"', $result);
        $this->assertStringContainsString('text/x-magento-template', $result);
    }

    /**
     * Test that constructor properly initializes the object
     */
    public function testConstructorInitializesObject()
    {
        $model = $this->objectManager->getObject(
            Image::class,
            [
                'factoryElement' => $this->factoryElement,
                'factoryCollection' => $this->factoryCollection,
                'escaper' => $this->escaper,
                'urlBuilder' => $this->urlBuilder,
                'data' => ['html_id' => 'test_id'],
                'secureRenderer' => $this->secureRenderer
            ]
        );

        $this->assertInstanceOf(Image::class, $model);
    }

    /**
     * Test _getUrl constructs proper catalog product media URL
     */
    public function testGetUrlConstructsProperMediaPath()
    {
        $imageValue = 'a/b/image.jpg';
        $baseUrl = 'http://example.com/pub/media/';

        $this->model->setValue($imageValue);

        $this->urlBuilder->expects($this->once())
            ->method('getBaseUrl')
            ->with(['_type' => UrlInterface::URL_TYPE_MEDIA])
            ->willReturn($baseUrl);

        $result = $this->invokeProtected('_getUrl');
        $this->assertSame($baseUrl . 'catalog/product/' . $imageValue, $result);
    }

    /**
     * Test _getDeleteCheckbox with required attribute and empty value
     */
    public function testGetDeleteCheckboxWithRequiredAttributeAndEmptyValue()
    {
        $htmlId = 'test_image';

        $attribute = $this->createMock(Attribute::class);
        $attribute->expects($this->once())
            ->method('getIsRequired')
            ->willReturn(true);

        $this->model->setForm($this->createFormMock());
        $this->model->setEntityAttribute($attribute);
        $this->model->setId($htmlId);
        $this->model->setValue('');

        $this->secureRenderer->expects($this->once())
            ->method('renderTag')
            ->willReturn('<script type="text/x-magento-template">test</script>');

        $result = $this->invokeProtected('_getDeleteCheckbox');

        $this->assertStringContainsString('type="hidden"', $result);
        $this->assertStringContainsString('_hidden', $result);
        $this->assertStringContainsString('text/x-magento-template', $result);
    }

    /**
     * Test _getDeleteCheckbox includes correct hidden field ID
     */
    public function testGetDeleteCheckboxHiddenFieldId()
    {
        $htmlId = 'product_image_field';

        $attribute = $this->createMock(Attribute::class);
        $attribute->expects($this->once())
            ->method('getIsRequired')
            ->willReturn(true);

        $this->model->setForm($this->createFormMock());
        $this->model->setEntityAttribute($attribute);
        $this->model->setId($htmlId);
        $this->model->setValue('test.jpg');

        $this->secureRenderer->expects($this->once())
            ->method('renderTag')
            ->willReturn('<script type="text/x-magento-template">test</script>');

        $result = $this->invokeProtected('_getDeleteCheckbox');

        // Test that hidden field is created with _hidden suffix
        $this->assertStringContainsString('_hidden"', $result);
        $this->assertStringContainsString('type="hidden"', $result);
        $this->assertStringContainsString('text/x-magento-template', $result);
    }
}
