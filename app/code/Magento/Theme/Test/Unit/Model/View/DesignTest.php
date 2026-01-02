<?php
/**
 * Copyright 2015 Adobe
 * All Rights Reserved.
 */
declare(strict_types=1);

namespace Magento\Theme\Test\Unit\Model\View;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\State;
use Magento\Framework\Locale\ResolverInterface;
use Magento\Framework\ObjectManagerInterface;
use Magento\Framework\View\Design\Theme\FlyweightFactory;
use Magento\Framework\View\Design\ThemeInterface;
use Magento\Framework\View\DesignInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Theme\Model\View\Design;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class DesignTest extends TestCase
{
    /**
     * @var State|MockObject
     */
    protected $state;

    /**
     * @var StoreManagerInterface|MockObject
     */
    protected $storeManager;

    /**
     * @var FlyweightFactory|MockObject
     */
    protected $flyweightThemeFactory;

    /**
     * @var \Magento\Theme\Model\ThemeFactory|MockObject
     */
    protected $themeFactory;

    /**
     * @var ScopeConfigInterface|MockObject
     */
    protected $config;

    /**
     * @var string|MockObject
     */
    protected $defaultTheme = 'anyName4Theme';

    /**
     * @var ObjectManagerInterface|MockObject
     */
    private $objectManager;

    /**
     * @var Design::__construct
     */
    private $model;

    protected function setUp(): void
    {
        $this->storeManager = $this->createMock(StoreManagerInterface::class);
        $this->flyweightThemeFactory = $this->createMock(FlyweightFactory::class);
        $this->config = $this->createMock(ScopeConfigInterface::class);
        $this->themeFactory = $this->createPartialMock(\Magento\Theme\Model\ThemeFactory::class, ['create']);
        $this->objectManager = $this->createMock(ObjectManagerInterface::class);
        $this->state = $this->createMock(State::class);
        $themes = [Design::DEFAULT_AREA => $this->defaultTheme];
        $this->model = new Design(
            $this->storeManager,
            $this->flyweightThemeFactory,
            $this->config,
            $this->themeFactory,
            $this->objectManager,
            $this->state,
            $themes
        );
    }

    /**
     * @param string $themePath
     * @param string $themeId
     * @param string $expectedResult
     */
    #[DataProvider('getThemePathDataProvider')]
    public function testGetThemePath($themePath, $themeId, $expectedResult)
    {
        $theme = $this->createMock(ThemeInterface::class);
        $theme->expects($this->once())->method('getThemePath')->willReturn($themePath);
        $theme->expects($this->any())->method('getId')->willReturn($themeId);
        /** @var ThemeInterface $theme */
        $this->assertEquals($expectedResult, $this->model->getThemePath($theme));
    }

    /**
     * @return array
     */
    public static function getThemePathDataProvider()
    {
        return [
            ['some_path', '', 'some_path'],
            ['', '2', DesignInterface::PUBLIC_THEME_DIR . '2'],
            ['', '', DesignInterface::PUBLIC_VIEW_DIR],
        ];
    }

    /**
     * @return array
     */
    public static function designThemeDataProvider()
    {
        return [
            'single' => [true, ScopeInterface::SCOPE_WEBSITES],
            'multi'  => [false, ScopeInterface::SCOPE_STORE],
        ];
    }

    /**
     * @test
     * @param bool $storeMode
     * @param string $scope
     * */
    #[DataProvider('designThemeDataProvider')]
    public function testSetDesignTheme($storeMode, $scope)
    {
        $area = 'adminhtml';
        $theme = $this->getMockBuilder(ThemeInterface::class)
            ->getMock();

        $this->assertInstanceOf(get_class($this->model), $this->model->setDesignTheme($theme, $area));
    }
}
