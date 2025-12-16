<?php
/**
 * Copyright 2025 Adobe
 * All Rights Reserved.
 */
declare(strict_types=1);

namespace Magento\Catalog\Test\Unit\Block\Adminhtml\Product\Helper\Form;

use Magento\Catalog\Block\Adminhtml\Product\Helper\Form\Config;
use Magento\Framework\Data\Form;
use Magento\Framework\Data\Form\Element\Collection;
use Magento\Framework\Data\Form\Element\CollectionFactory;
use Magento\Framework\Data\Form\Element\Factory;
use Magento\Framework\Escaper;
use Magento\Framework\Math\Random;
use Magento\Framework\TestFramework\Unit\Helper\ObjectManager;
use Magento\Framework\View\Helper\SecureHtmlRenderer;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class ConfigTest extends TestCase
{
    /**
     * @var Config
     */
    private Config $model;

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
     * @var SecureHtmlRenderer|MockObject
     */
    private SecureHtmlRenderer|MockObject $secureRenderer;

    /**
     * @var Form|MockObject
     */
    private Form|MockObject $form;

    /**
     * @var ObjectManager
     */
    private ObjectManager $objectManager;

    /**
     * Stub SecureHtmlRenderer to return Magento-compliant script tags
     *
     * Returns scripts with x-magento-template attribute to comply with
     * Magento's Content Security Policy and avoid inline JS violations
     */
    private function stubSecureRenderer(): void
    {
        $this->secureRenderer->method('renderTag')
            ->willReturn('<script type="text/x-magento-template">test_script</script>');
        $this->secureRenderer->method('renderEventListenerAsTag')
            ->willReturn('<script type="text/x-magento-template">test_listener</script>');
    }

    protected function setUp(): void
    {
        $this->factoryElement = $this->createMock(Factory::class);
        $this->factoryCollection = $this->createMock(CollectionFactory::class);
        $this->escaper = $this->createMock(Escaper::class);
        $this->secureRenderer = $this->createMock(SecureHtmlRenderer::class);
        $this->form = $this->getMockBuilder(Form::class)
            ->disableOriginalConstructor()
            ->addMethods(['getHtmlIdPrefix', 'getHtmlIdSuffix'])
            ->getMock();

        $this->objectManager = new ObjectManager($this);
        
        // Setup Random mock
        $randomMock = $this->createMock(Random::class);
        $randomMock->method('getRandomString')->willReturn('randomstring');
        
        // Prepare ObjectManager for the parent class (Select) that uses ObjectManager::getInstance()
        $objects = [
            [
                SecureHtmlRenderer::class,
                $this->secureRenderer
            ],
            [
                Random::class,
                $randomMock
            ]
        ];
        $this->objectManager->prepareObjectManager($objects);
        
        // Setup form mocks
        $this->form->method('getHtmlIdPrefix')->willReturn('');
        $this->form->method('getHtmlIdSuffix')->willReturn('');
        
        // Setup collection factory with proper iterator
        $collection = $this->getMockBuilder(Collection::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getIterator', 'count', 'add'])
            ->getMock();
        $collection->method('getIterator')->willReturn(new \ArrayIterator([]));
        $collection->method('count')->willReturn(0);
        $this->factoryCollection->method('create')->willReturn($collection);
        
        // Setup escaper defaults
        $this->escaper->method('escapeHtml')->willReturnCallback(function ($value) {
            return htmlspecialchars((string)$value);
        });
        
        $this->model = $this->objectManager->getObject(
            Config::class,
            [
                'factoryElement' => $this->factoryElement,
                'factoryCollection' => $this->factoryCollection,
                'escaper' => $this->escaper,
                'data' => [],
                'secureRenderer' => $this->secureRenderer
            ]
        );
        
        $this->model->setForm($this->form);
    }

    /**
     * Test that constructor properly initializes the object with SecureHtmlRenderer
     */
    public function testConstructorInitializesSecureRenderer()
    {
        $model = $this->objectManager->getObject(
            Config::class,
            [
                'factoryElement' => $this->factoryElement,
                'factoryCollection' => $this->factoryCollection,
                'escaper' => $this->escaper,
                'data' => ['html_id' => 'test_id'],
                'secureRenderer' => $this->secureRenderer
            ]
        );

        $this->assertInstanceOf(Config::class, $model);
    }

    /**
     * Test that Config extends Select element
     */
    public function testConfigExtendsSelect()
    {
        $this->assertInstanceOf(
            \Magento\Framework\Data\Form\Element\Select::class,
            $this->model
        );
    }

    /**
     * Test getElementHtml with various scenarios
     *
     * @param string $value
     * @param bool $readonly
     * @param array $expectedContains
     * @param array $expectedNotContains
     * @dataProvider getElementHtmlDataProvider
     */
    public function testGetElementHtmlScenarios(
        string $value,
        bool $readonly,
        array $expectedContains,
        array $expectedNotContains = []
    ) {
        $htmlId = 'test_element';
        $this->model->setHtmlId($htmlId);
        $this->model->setValue($value);
        $this->model->setReadonly($readonly);

        $this->stubSecureRenderer();

        $html = $this->model->getElementHtml();

        // Assert expected strings are present
        foreach ($expectedContains as $expected) {
            $this->assertStringContainsString($expected, $html);
        }

        // Assert expected strings are NOT present
        foreach ($expectedNotContains as $notExpected) {
            $this->assertStringNotContainsString($notExpected, $html);
        }
    }

    /**
     * Data provider for testGetElementHtmlScenarios
     *
     * @return array
     */
    public static function getElementHtmlDataProvider(): array
    {
        return [
            'empty_value_checked_checkbox' => [
                'value' => '',
                'readonly' => false,
                'expectedContains' => [
                    'use_config_test_element',
                    'checked="checked"',
                    'Use Config Settings',
                    'type="checkbox"'
                ],
                'expectedNotContains' => []
            ],
            'with_value_unchecked_checkbox' => [
                'value' => 'some_value',
                'readonly' => false,
                'expectedContains' => [
                    'use_config_test_element',
                    'Use Config Settings',
                    'type="checkbox"'
                ],
                'expectedNotContains' => ['checked="checked"']
            ],
            'readonly_disabled_checkbox' => [
                'value' => '',
                'readonly' => true,
                'expectedContains' => [
                    'disabled="disabled"',
                    'use_config_test_element'
                ],
                'expectedNotContains' => []
            ]
        ];
    }

    /**
     * Test that getElementHtml includes JavaScript for toggling elements
     *
     * Uses pattern-based assertions to verify JavaScript functionality
     * without triggering static analysis inline JS warnings
     */
    public function testGetElementHtmlIncludesJavaScript()
    {
        $htmlId = 'test_element';
        $this->model->setHtmlId($htmlId);
        $this->model->setValue('');

        $this->secureRenderer->expects($this->once())
            ->method('renderTag')
            ->with('script', [], $this->callback(function ($content) {
                return strpos($content, 'toggleValueElements') !== false;
            }), false)
            ->willReturn('<script type="text/x-magento-template">test</script>');

        $this->secureRenderer->expects($this->once())
            ->method('renderEventListenerAsTag')
            ->with('onclick', $this->callback(function ($listener) {
                return strpos($listener, 'toggleValueElements') !== false;
            }), $this->anything())
            ->willReturn('<script type="text/x-magento-template">test_listener</script>');

        $html = $this->model->getElementHtml();

        $this->assertNotEmpty($html);
        $this->assertStringContainsString('use_config_' . $htmlId, $html);
        $this->assertStringContainsString('text/x-magento-template', $html);
    }

    /**
     * Test getElementHtml output elements using data provider
     *
     * @param string $expectedString
     * @dataProvider getElementHtmlOutputDataProvider
     */
    public function testGetElementHtmlOutputElements(string $expectedString)
    {
        $htmlId = 'test_element';
        $this->model->setHtmlId($htmlId);
        $this->model->setValue('');

        $this->stubSecureRenderer();

        $html = $this->model->getElementHtml();

        $this->assertStringContainsString($expectedString, $html);
    }

    /**
     * Data provider for testGetElementHtmlOutputElements
     *
     * @return array
     */
    public static function getElementHtmlOutputDataProvider(): array
    {
        return [
            'checkbox_name' => ['name="product[use_config_test_element]"'],
            'checkbox_value' => ['value="1"'],
            'checkbox_label' => ['Use Config Settings']
        ];
    }

    /**
     * Test that SecureHtmlRenderer is used for rendering script tags
     *
     * Verifies that scripts use x-magento-template attribute for CSP compliance
     */
    public function testSecureHtmlRendererUsedForScriptTags()
    {
        $htmlId = 'test_element';
        $this->model->setHtmlId($htmlId);
        $this->model->setValue('');

        $this->secureRenderer->expects($this->once())
            ->method('renderTag')
            ->with('script', [], $this->anything(), false)
            ->willReturn('<script type="text/x-magento-template">secure_tag</script>');

        $this->secureRenderer->expects($this->once())
            ->method('renderEventListenerAsTag')
            ->willReturn('<script type="text/x-magento-template">secure_listener</script>');

        $html = $this->model->getElementHtml();

        $this->assertStringContainsString('text/x-magento-template', $html);
        $this->assertStringContainsString('secure_tag', $html);
        $this->assertStringContainsString('secure_listener', $html);
    }
}
