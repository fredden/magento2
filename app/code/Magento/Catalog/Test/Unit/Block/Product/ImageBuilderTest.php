<?php
/**
 * Copyright 2025 Adobe
 * All Rights Reserved.
 */
declare(strict_types=1);

namespace Magento\Catalog\Test\Unit\Block\Product;

use Magento\Catalog\Block\Product\Image;
use Magento\Catalog\Block\Product\ImageBuilder;
use Magento\Catalog\Block\Product\ImageFactory;
use Magento\Catalog\Helper\Image as ImageHelper;
use Magento\Catalog\Helper\ImageFactory as HelperFactory;
use Magento\Catalog\Model\Product;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Unit test for ImageBuilder
 *
 * @covers \Magento\Catalog\Block\Product\ImageBuilder
 */
class ImageBuilderTest extends TestCase
{
    /**
     * @var ImageBuilder
     */
    private ImageBuilder $imageBuilder;

    /**
     * @var HelperFactory|MockObject
     */
    private MockObject $helperFactoryMock;

    /**
     * @var ImageFactory|MockObject
     */
    private MockObject $imageFactoryMock;

    /**
     * @var Product|MockObject
     */
    private MockObject $productMock;

    /**
     * @var Image|MockObject
     */
    private MockObject $imageMock;

    /**
     * @inheritdoc
     */
    protected function setUp(): void
    {
        $this->helperFactoryMock = $this->createMock(HelperFactory::class);
        $this->imageFactoryMock = $this->createMock(ImageFactory::class);
        $this->productMock = $this->createMock(Product::class);
        $this->imageMock = $this->createMock(Image::class);

        $this->imageBuilder = new ImageBuilder(
            $this->helperFactoryMock,
            $this->imageFactoryMock
        );
    }

    /**
     * @inheritdoc
     */
    protected function tearDown(): void
    {
        unset($this->imageBuilder);
    }

    /**
     * Test setProduct returns self for method chaining
     *
     * @covers \Magento\Catalog\Block\Product\ImageBuilder::setProduct
     * @return void
     */
    public function testSetProductReturnsSelfForMethodChaining(): void
    {
        $result = $this->imageBuilder->setProduct($this->productMock);

        $this->assertSame($this->imageBuilder, $result);
    }

    /**
     * Test setImageId returns self for method chaining
     *
     * @covers \Magento\Catalog\Block\Product\ImageBuilder::setImageId
     * @return void
     */
    public function testSetImageIdReturnsSelfForMethodChaining(): void
    {
        $result = $this->imageBuilder->setImageId('product_thumbnail_image');

        $this->assertSame($this->imageBuilder, $result);
    }

    /**
     * Test setAttributes returns self for method chaining
     *
     * @covers \Magento\Catalog\Block\Product\ImageBuilder::setAttributes
     * @return void
     */
    public function testSetAttributesReturnsSelfForMethodChaining(): void
    {
        $result = $this->imageBuilder->setAttributes(['class' => 'product-image']);

        $this->assertSame($this->imageBuilder, $result);
    }

    /**
     * Test create uses passed parameters when provided
     *
     * @covers \Magento\Catalog\Block\Product\ImageBuilder::create
     * @return void
     */
    public function testCreateUsesPassedParameters(): void
    {
        $imageId = 'product_base_image';
        $attributes = ['alt' => 'Product Image'];

        $this->imageFactoryMock->expects($this->once())
            ->method('create')
            ->with($this->productMock, $imageId, $attributes)
            ->willReturn($this->imageMock);

        $result = $this->imageBuilder->create($this->productMock, $imageId, $attributes);

        $this->assertSame($this->imageMock, $result);
    }

    /**
     * Test create uses stored properties when no parameters passed
     *
     * @covers \Magento\Catalog\Block\Product\ImageBuilder::create
     * @return void
     */
    public function testCreateUsesStoredPropertiesWhenNoParametersPassed(): void
    {
        $imageId = 'product_small_image';
        $attributes = ['class' => 'gallery-image'];

        $this->imageBuilder->setProduct($this->productMock);
        $this->imageBuilder->setImageId($imageId);
        $this->imageBuilder->setAttributes($attributes);

        $this->imageFactoryMock->expects($this->once())
            ->method('create')
            ->with($this->productMock, $imageId, $attributes)
            ->willReturn($this->imageMock);

        $result = $this->imageBuilder->create();

        $this->assertSame($this->imageMock, $result);
    }

    /**
     * Test create with method chaining
     *
     * @covers \Magento\Catalog\Block\Product\ImageBuilder::create
     * @covers \Magento\Catalog\Block\Product\ImageBuilder::setProduct
     * @covers \Magento\Catalog\Block\Product\ImageBuilder::setImageId
     * @covers \Magento\Catalog\Block\Product\ImageBuilder::setAttributes
     * @return void
     */
    public function testCreateWithMethodChaining(): void
    {
        $imageId = 'product_listing_image';
        $attributes = ['loading' => 'lazy'];

        $this->imageFactoryMock->expects($this->once())
            ->method('create')
            ->with($this->productMock, $imageId, $attributes)
            ->willReturn($this->imageMock);

        $result = $this->imageBuilder
            ->setProduct($this->productMock)
            ->setImageId($imageId)
            ->setAttributes($attributes)
            ->create();

        $this->assertSame($this->imageMock, $result);
    }

    /**
     * Test create parameters override stored properties
     *
     * @covers \Magento\Catalog\Block\Product\ImageBuilder::create
     * @return void
     */
    public function testCreateParametersOverrideStoredProperties(): void
    {
        $storedImageId = 'stored_image_id';
        $storedAttributes = ['class' => 'stored-class'];

        $passedImageId = 'passed_image_id';
        $passedAttributes = ['class' => 'passed-class'];

        $anotherProductMock = $this->createMock(Product::class);

        $this->imageBuilder->setProduct($this->productMock);
        $this->imageBuilder->setImageId($storedImageId);
        $this->imageBuilder->setAttributes($storedAttributes);

        $this->imageFactoryMock->expects($this->once())
            ->method('create')
            ->with($anotherProductMock, $passedImageId, $passedAttributes)
            ->willReturn($this->imageMock);

        $result = $this->imageBuilder->create($anotherProductMock, $passedImageId, $passedAttributes);

        $this->assertSame($this->imageMock, $result);
    }

    /**
     * Test getRatio returns correct ratio
     *
     * @dataProvider ratioDataProvider
     * @covers \Magento\Catalog\Block\Product\ImageBuilder::getRatio
     * @param int|null $width
     * @param int|null $height
     * @param float|int $expectedRatio
     * @return void
     */
    public function testGetRatioReturnsCorrectRatio(?int $width, ?int $height, float|int $expectedRatio): void
    {
        $helperMock = $this->createMock(ImageHelper::class);
        $helperMock->expects($this->once())->method('getWidth')->willReturn($width);
        $helperMock->expects($this->once())->method('getHeight')->willReturn($height);

        $reflection = new ReflectionClass($this->imageBuilder);
        $method = $reflection->getMethod('getRatio');
        $method->setAccessible(true);

        $result = $method->invoke($this->imageBuilder, $helperMock);

        $this->assertSame($expectedRatio, $result);
    }

    /**
     * Data provider for ratio test scenarios
     *
     * @return array
     */
    public static function ratioDataProvider(): array
    {
        return [
            'square image' => [
                'width' => 100,
                'height' => 100,
                'expectedRatio' => 1
            ],
            'landscape image' => [
                'width' => 200,
                'height' => 100,
                'expectedRatio' => 0.5
            ],
            'portrait image' => [
                'width' => 100,
                'height' => 200,
                'expectedRatio' => 2
            ],
            'zero width returns 1' => [
                'width' => 0,
                'height' => 100,
                'expectedRatio' => 1
            ],
            'zero height returns 1' => [
                'width' => 100,
                'height' => 0,
                'expectedRatio' => 1
            ],
            'null width returns 1' => [
                'width' => null,
                'height' => 100,
                'expectedRatio' => 1
            ],
            'null height returns 1' => [
                'width' => 100,
                'height' => null,
                'expectedRatio' => 1
            ]
        ];
    }

    /**
     * Test getCustomAttributes returns formatted string
     *
     * @dataProvider customAttributesDataProvider
     * @covers \Magento\Catalog\Block\Product\ImageBuilder::getCustomAttributes
     * @param array $attributes
     * @param string $expectedResult
     * @return void
     */
    public function testGetCustomAttributesReturnsFormattedString(
        array $attributes,
        string $expectedResult
    ): void {
        $this->imageBuilder->setAttributes($attributes);

        $reflection = new ReflectionClass($this->imageBuilder);
        $method = $reflection->getMethod('getCustomAttributes');
        $method->setAccessible(true);

        $result = $method->invoke($this->imageBuilder);

        $this->assertSame($expectedResult, $result);
    }

    /**
     * Data provider for custom attributes test scenarios
     *
     * @return array
     */
    public static function customAttributesDataProvider(): array
    {
        return [
            'single attribute' => [
                'attributes' => ['class' => 'product-image'],
                'expectedResult' => 'class="product-image"'
            ],
            'multiple attributes' => [
                'attributes' => ['class' => 'image', 'alt' => 'Product'],
                'expectedResult' => 'class="image" alt="Product"'
            ],
            'empty attributes' => [
                'attributes' => [],
                'expectedResult' => ''
            ]
        ];
    }

    /**
     * Test getCustomAttributes with XSS attack vectors documents current behavior
     *
     * @dataProvider xssVulnerabilityDataProvider
     * @covers \Magento\Catalog\Block\Product\ImageBuilder::getCustomAttributes
     * @param array $attributes
     * @param string $expectedResult
     * @return void
     */
    public function testGetCustomAttributesWithXssVectors(
        array $attributes,
        string $expectedResult
    ): void {
        $this->imageBuilder->setAttributes($attributes);

        $reflection = new ReflectionClass($this->imageBuilder);
        $method = $reflection->getMethod('getCustomAttributes');
        $method->setAccessible(true);

        $result = $method->invoke($this->imageBuilder);

        $this->assertSame($expectedResult, $result);
    }

    /**
     * Data provider for XSS vulnerability test scenarios
     *
     * Documents current behavior where special characters are NOT escaped.
     * Note: These test cases highlight potential XSS vulnerabilities in the code.
     *
     * @return array
     */
    public static function xssVulnerabilityDataProvider(): array
    {
        return [
            'double quotes in value - not escaped' => [
                'attributes' => ['alt' => 'Product "Special" Name'],
                'expectedResult' => 'alt="Product "Special" Name"'
            ],
            'script injection via attribute value - not escaped' => [
                'attributes' => ['alt' => '<script>alert("XSS")</script>'],
                'expectedResult' => 'alt="<script>alert("XSS")</script>"'
            ],
            'attribute breakout attempt with quotes - not escaped' => [
                'attributes' => ['class' => 'test" onclick="alert(1)'],
                'expectedResult' => 'class="test" onclick="alert(1)"'
            ],
            'html entity injection - not escaped' => [
                'attributes' => ['alt' => 'Product & Description'],
                'expectedResult' => 'alt="Product & Description"'
            ],
            'angle brackets in value - not escaped' => [
                'attributes' => ['alt' => '<img src=x onerror=alert(1)>'],
                'expectedResult' => 'alt="<img src=x onerror=alert(1)>"'
            ],
            'single quotes in value - not escaped' => [
                'attributes' => ['alt' => "Product's Name"],
                'expectedResult' => 'alt="Product\'s Name"'
            ],
            'javascript protocol injection' => [
                'attributes' => ['src' => 'javascript:alert(document.cookie)'],
                'expectedResult' => 'src="javascript:alert(document.cookie)"'
            ],
            'mixed special characters - not escaped' => [
                'attributes' => ['alt' => '<"Product"> & \'Test\''],
                'expectedResult' => 'alt="<"Product"> & \'Test\'"'
            ]
        ];
    }
}
