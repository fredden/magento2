<?php
/**
 * Copyright 2025 Adobe
 * All Rights Reserved.
 */
declare(strict_types=1);

namespace Magento\Catalog\Test\Unit\Block\Product\ReviewRenderer;

use Magento\Catalog\Block\Product\ReviewRenderer\DefaultProvider;
use Magento\Catalog\Block\Product\ReviewRendererInterface;
use Magento\Catalog\Model\Product;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class DefaultProviderTest extends TestCase
{
    /**
     * @var DefaultProvider
     */
    private DefaultProvider $model;

    /**
     * @var Product|MockObject
     */
    private Product|MockObject $productMock;

    protected function setUp(): void
    {
        $this->productMock = $this->getMockBuilder(Product::class)
            ->disableOriginalConstructor()
            ->getMock();

        $this->model = new DefaultProvider();
    }

    /**
     * Test that DefaultProvider implements ReviewRendererInterface
     */
    public function testImplementsReviewRendererInterface()
    {
        $this->assertInstanceOf(ReviewRendererInterface::class, $this->model);
    }

    /**
     * Test with various template types
     *
     * @param string $templateType
     * @param bool $displayIfNoReviews
     * @dataProvider templateTypeDataProvider
     */
    public function testGetReviewsSummaryHtmlReturnsEmptyString($templateType, $displayIfNoReviews)
    {
        $result = $this->model->getReviewsSummaryHtml(
            $this->productMock,
            $templateType,
            $displayIfNoReviews
        );

        $this->assertSame('', $result);
        $this->assertIsString($result);
    }

    /**
     * Data provider for template types
     *
     * @return array
     */
    public static function templateTypeDataProvider(): array
    {
        return [
            'default_view_no_display' => [ReviewRendererInterface::DEFAULT_VIEW, false],
            'default_view_display' => [ReviewRendererInterface::DEFAULT_VIEW, true],
            'short_view_no_display' => [ReviewRendererInterface::SHORT_VIEW, false],
            'full_view_display' => [ReviewRendererInterface::FULL_VIEW, true],
            'custom_template_no_display' => ['custom_template_type', false]
        ];
    }
}
