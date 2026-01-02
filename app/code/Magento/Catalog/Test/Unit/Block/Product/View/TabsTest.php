<?php

/**
 * Copyright 2015 Adobe
 * All Rights Reserved.
 */

declare(strict_types=1);

namespace Magento\Catalog\Test\Unit\Block\Product\View;

use Magento\Catalog\Block\Product\View\Tabs;
use Magento\Framework\TestFramework\Unit\Helper\ObjectManager;
use Magento\Framework\View\Element\Template;
use Magento\Framework\View\Layout;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Unit test for Tabs class
 *
 * @covers \Magento\Catalog\Block\Product\View\Tabs
 */
class TabsTest extends TestCase
{
    /**
     * @var Tabs
     */
    private Tabs $block;

    /**
     * @var Layout|MockObject
     */
    private $layoutMock;

    /**
     * @var ObjectManager
     */
    private ObjectManager $helper;

    /**
     * @inheritdoc
     */
    protected function setUp(): void
    {
        $this->helper = new ObjectManager($this);
        $this->layoutMock = $this->createMock(Layout::class);
        $this->block = $this->helper->getObject(Tabs::class, ['layout' => $this->layoutMock]);
    }

    /**
     * Test addTab method
     *
     * @covers \Magento\Catalog\Block\Product\View\Tabs::addTab
     * @covers \Magento\Catalog\Block\Product\View\Tabs::getTabs
     * @return void
     */
    public function testAddTab(): void
    {
        $tabBlock = $this->createMock(Template::class);
        $tabBlock->expects($this->once())->method('setTemplate')->with('template')->willReturnSelf();

        $this->layoutMock->expects($this->once())->method('createBlock')->with('block')->willReturn($tabBlock);

        $this->block->addTab('alias', 'title', 'block', 'template', 'header');

        $expectedTabs = [['alias' => 'alias', 'title' => 'title', 'header' => 'header']];
        $this->assertSame($expectedTabs, $this->block->getTabs());
    }

    /**
     * Test addTab method without header parameter
     *
     * @covers \Magento\Catalog\Block\Product\View\Tabs::addTab
     * @covers \Magento\Catalog\Block\Product\View\Tabs::getTabs
     * @return void
     */
    public function testAddTabWithoutHeader(): void
    {
        $tabBlock = $this->createMock(Template::class);
        $tabBlock->expects($this->once())->method('setTemplate')->with('template')->willReturnSelf();

        $this->layoutMock->expects($this->once())->method('createBlock')->with('block')->willReturn($tabBlock);

        $this->block->addTab('alias', 'title', 'block', 'template');

        $expectedTabs = [['alias' => 'alias', 'title' => 'title', 'header' => null]];
        $this->assertSame($expectedTabs, $this->block->getTabs());
    }

    /**
     * Test addTab with invalid parameters
     *
     * @param string|null $title
     * @param string|null $block
     * @param string|null $template
     * @dataProvider invalidParametersDataProvider
     * @covers \Magento\Catalog\Block\Product\View\Tabs::addTab
     * @covers \Magento\Catalog\Block\Product\View\Tabs::getTabs
     * @return void
     */
    public function testAddTabWithInvalidParameters(?string $title, ?string $block, ?string $template): void
    {
        $this->layoutMock->expects($this->never())->method('createBlock');

        $this->block->addTab('alias', $title, $block, $template, 'header');

        $this->assertEmpty($this->block->getTabs());
    }

    /**
     * @return array
     */
    public static function invalidParametersDataProvider(): array
    {
        return [
            'empty_title' => ['', 'block', 'template'],
            'null_title' => [null, 'block', 'template'],
            'empty_block' => ['title', '', 'template'],
            'null_block' => ['title', null, 'template'],
            'empty_template' => ['title', 'block', ''],
            'null_template' => ['title', 'block', null],
            'zero_values' => ['0', '0', '0']
        ];
    }

    /**
     * Test adding multiple tabs
     *
     * @covers \Magento\Catalog\Block\Product\View\Tabs::addTab
     * @covers \Magento\Catalog\Block\Product\View\Tabs::getTabs
     * @return void
     */
    public function testAddMultipleTabs(): void
    {
        $tabBlock1 = $this->createMock(Template::class);
        $tabBlock1->expects($this->once())->method('setTemplate')->with('template1')->willReturnSelf();

        $tabBlock2 = $this->createMock(Template::class);
        $tabBlock2->expects($this->once())->method('setTemplate')->with('template2')->willReturnSelf();

        $this->layoutMock->expects($this->exactly(2))
            ->method('createBlock')
            ->willReturnOnConsecutiveCalls($tabBlock1, $tabBlock2);

        $this->block->addTab('alias1', 'title1', 'block1', 'template1', 'header1');
        $this->block->addTab('alias2', 'title2', 'block2', 'template2', 'header2');

        $expectedTabs = [
            ['alias' => 'alias1', 'title' => 'title1', 'header' => 'header1'],
            ['alias' => 'alias2', 'title' => 'title2', 'header' => 'header2']
        ];
        $this->assertSame($expectedTabs, $this->block->getTabs());
    }

    /**
     * Test getTabs returns empty array when no tabs added
     *
     * @covers \Magento\Catalog\Block\Product\View\Tabs::getTabs
     * @return void
     */
    public function testGetTabsWhenEmpty(): void
    {
        $this->assertEmpty($this->block->getTabs());
        $this->assertIsArray($this->block->getTabs());
    }
}
