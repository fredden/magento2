<?php
/**
 * Copyright 2025 Adobe
 * All Rights Reserved.
 */
declare(strict_types=1);

namespace Magento\Catalog\Test\Unit\Block\Adminhtml\Product\Edit\Tab;

use Magento\Backend\Block\Widget\Button;
use Magento\Catalog\Block\Adminhtml\Product\Edit\Tab\Options;
use Magento\Catalog\Block\Adminhtml\Product\Edit\Tab\Options\Option;
use Magento\Framework\TestFramework\Unit\Helper\ObjectManager;
use Magento\Framework\View\LayoutInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Magento\Framework\View\Element\BlockInterface;

/**
 * Unit test for Magento\Catalog\Block\Adminhtml\Product\Edit\Tab\Options
 *
 */
class OptionsTest extends TestCase
{
    /**
     * @var Options
     */
    private $block;

    /**
     * @var LayoutInterface&MockObject
     */
    private $layoutMock;

    /**
     * @var Button&MockObject
     */
    private $addButtonMock;

    /**
     * @var Button&MockObject
     */
    private $importButtonMock;

    /**
     * @var Option&MockObject
     */
    private $optionsBoxMock;

    /**
     * Set up test environment
     *
     * @return void
     * @throws \PHPUnit\Framework\MockObject\Exception
     */
    protected function setUp(): void
    {
        $helper = new ObjectManager($this);
        $this->layoutMock = $this->createMock(LayoutInterface::class);
        $this->addButtonMock = $this->getMockBuilder(Button::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['toHtml'])
            ->getMock();
        $this->importButtonMock = $this->getMockBuilder(Button::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['toHtml'])
            ->getMock();
        $this->optionsBoxMock = $this->getMockBuilder(Option::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['toHtml'])
            ->getMock();

        $this->block = $helper->getObject(Options::class);
    }

    /**
     * Test that _prepareLayout creates all required child blocks
     *
     * @return void
     * @throws \ReflectionException
     */
    public function testPrepareLayoutAddsAllChildBlocks(): void
    {
        $this->setupLayoutMock();
        $this->block->setLayout($this->layoutMock);

        // Use reflection to call protected _prepareLayout method
        $reflection = new \ReflectionClass($this->block);
        $method = $reflection->getMethod('_prepareLayout');
        $method->setAccessible(true);
        $result = $method->invoke($this->block);

        $this->assertInstanceOf(Options::class, $result);
        $this->assertNotNull($this->block->getAddButtonHtml());
        $this->assertNotNull($this->block->getOptionsBoxHtml());
    }

    /**
     * Test that getAddButtonHtml method executes without errors
     *
     * @return void
     * @throws \ReflectionException
     */
    public function testGetAddButtonHtmlIsCallable(): void
    {
        $this->addButtonMock->method('toHtml')->willReturn('<button>Add</button>');
        $this->setupLayoutMock();
        $this->block->setLayout($this->layoutMock);

        // Prepare layout first
        $reflection = new \ReflectionClass($this->block);
        $method = $reflection->getMethod('_prepareLayout');
        $method->setAccessible(true);
        $method->invoke($this->block);

        $result = $this->block->getAddButtonHtml();

        $this->assertIsString($result);
    }

    /**
     * Test that getOptionsBoxHtml method executes without errors
     *
     * @return void
     * @throws \ReflectionException
     */
    public function testGetOptionsBoxHtmlIsCallable(): void
    {
        $this->optionsBoxMock->method('toHtml')->willReturn('<div>Options</div>');
        $this->setupLayoutMock();
        $this->block->setLayout($this->layoutMock);

        // Prepare layout first
        $reflection = new \ReflectionClass($this->block);
        $method = $reflection->getMethod('_prepareLayout');
        $method->setAccessible(true);
        $method->invoke($this->block);

        $result = $this->block->getOptionsBoxHtml();

        $this->assertIsString($result);
    }

    /**
     * Configure layout mock to return appropriate child blocks
     *
     * @return void
     */
    private function setupLayoutMock(): void
    {
        $this->layoutMock->method('createBlock')
            ->willReturnCallback(function ($class, $name) {
                unset($class);
                if (str_contains($name, 'add_button')) {
                    return $this->addButtonMock;
                } elseif (str_contains($name, 'options_box')) {
                    return $this->optionsBoxMock;
                } elseif (str_contains($name, 'import_button')) {
                    return $this->importButtonMock;
                }
                // Return a default mock for any other createBlock calls
                return $this->createMock(BlockInterface::class);
            });
    }
}
