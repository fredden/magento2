<?php
/**
 * Copyright 2025 Adobe
 * All Rights Reserved.
 */
declare(strict_types=1);

namespace Magento\Catalog\Test\Unit\Block\Adminhtml\Product\Edit\Tab;

use Magento\Backend\Block\Widget\Tab\TabInterface;
use Magento\Catalog\Block\Adminhtml\Product\Edit\Tab\ChildTab;
use Magento\Framework\TestFramework\Unit\Helper\ObjectManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Unit test for Magento\Catalog\Block\Adminhtml\Product\Edit\Tab\ChildTab
 */
class ChildTabTest extends TestCase
{
    /**
     * @var ChildTab
     */
    private $block;

    /**
     * @var TabInterface&MockObject
     */
    private $tabMock;

    /**
     * Set up test environment
     *
     * @return void
     */
    protected function setUp(): void
    {
        $helper = new ObjectManager($this);
        $this->tabMock = $this->getMockBuilder(TabInterface::class)
            ->onlyMethods(['getTabLabel', 'getTabTitle', 'canShowTab', 'isHidden'])
            ->addMethods(['toHtml', 'getTabId', 'getData'])
            ->getMock();
        $this->block = $helper->getObject(ChildTab::class);
    }

    /**
     * Test that setTab correctly sets the tab property and returns $this for chaining
     *
     * @return void
     */
    public function testSetTabSetsTabAndReturnsThis(): void
    {
        $result = $this->block->setTab($this->tabMock);

        $this->assertSame($this->block, $result);
    }

    /**
     * Test that getTitle returns the correct tab title from the tab
     *
     * @return void
     */
    public function testGetTitleReturnsTabTitle(): void
    {
        $expectedTitle = 'Product Tab Title';

        $this->tabMock->expects($this->once())
            ->method('getTabTitle')
            ->willReturn($expectedTitle);

        $this->block->setTab($this->tabMock);
        $result = $this->block->getTitle();

        $this->assertEquals($expectedTitle, $result);
    }

    /**
     * Test that getContent returns the HTML content from the tab
     *
     * @return void
     */
    public function testGetContentReturnsTabHtml(): void
    {
        $expectedHtml = '<div>Tab Content</div>';

        $this->tabMock->expects($this->once())
            ->method('toHtml')
            ->willReturn($expectedHtml);

        $this->block->setTab($this->tabMock);
        $result = $this->block->getContent();

        $this->assertEquals($expectedHtml, $result);
    }

    /**
     * Test that getTabId returns the correct tab ID from the tab
     *
     * @return void
     */
    public function testGetTabIdReturnsTabId(): void
    {
        $expectedTabId = 'product_tab_id';

        $this->tabMock->expects($this->once())
            ->method('getTabId')
            ->willReturn($expectedTabId);

        $this->block->setTab($this->tabMock);
        $result = $this->block->getTabId();

        $this->assertEquals($expectedTabId, $result);
    }

    /**
     * Test that isTabOpened returns true when tab has opened data set to true
     *
     * @return void
     */
    public function testIsTabOpenedReturnsTrueWhenTabIsOpened(): void
    {
        $this->tabMock->expects($this->once())
            ->method('getData')
            ->with('opened')
            ->willReturn(true);

        $this->block->setTab($this->tabMock);
        $result = $this->block->isTabOpened();

        $this->assertTrue($result);
    }

    /**
     * Test that isTabOpened returns false when tab has opened data set to false
     *
     * @return void
     */
    public function testIsTabOpenedReturnsFalseWhenTabIsClosed(): void
    {
        $this->tabMock->expects($this->once())
            ->method('getData')
            ->with('opened')
            ->willReturn(false);

        $this->block->setTab($this->tabMock);
        $result = $this->block->isTabOpened();

        $this->assertFalse($result);
    }

    /**
     * Test that isTabOpened returns false when tab has no opened data
     *
     * @return void
     */
    public function testIsTabOpenedReturnsFalseWhenOpenedDataIsNull(): void
    {
        $this->tabMock->expects($this->once())
            ->method('getData')
            ->with('opened')
            ->willReturn(null);

        $this->block->setTab($this->tabMock);
        $result = $this->block->isTabOpened();

        $this->assertFalse($result);
    }

    /**
     * Test that isTabOpened returns boolean for various truthy/falsy values
     *
     * @dataProvider openedDataProvider
     * @param mixed $openedValue
     * @param bool $expectedResult
     * @return void
     */
    public function testIsTabOpenedHandlesDifferentDataTypes($openedValue, bool $expectedResult): void
    {
        $this->tabMock->expects($this->once())
            ->method('getData')
            ->with('opened')
            ->willReturn($openedValue);

        $this->block->setTab($this->tabMock);
        $result = $this->block->isTabOpened();

        $this->assertSame($expectedResult, $result);
    }

    /**
     * Data provider for opened data type scenarios
     *
     * @return array
     */
    public static function openedDataProvider(): array
    {
        return [
            'integer_1' => [1, true],
            'integer_0' => [0, false],
            'string_true' => ['true', true],
            'string_false' => ['false', true], // Non-empty string is truthy
            'string_empty' => ['', false],
            'string_1' => ['1', true],
            'string_0' => ['0', false],
        ];
    }

    /**
     * Test that method chaining works correctly with setTab
     *
     * @return void
     */
    public function testMethodChainingWorksWithSetTab(): void
    {
        $expectedTitle = 'Chained Tab Title';

        $this->tabMock->method('getTabTitle')
            ->willReturn($expectedTitle);

        $result = $this->block->setTab($this->tabMock)->getTitle();

        $this->assertEquals($expectedTitle, $result);
    }
}
