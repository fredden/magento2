<?php
/**
 * Copyright 2015 Adobe
 * All Rights Reserved.
 */
declare(strict_types=1);

namespace Magento\Ui\Test\Unit\Component\Form;

use Magento\Framework\View\Element\UiComponent\ContextInterface;
use Magento\Framework\View\Element\UiComponentFactory;
use Magento\Ui\Component\Form\Field;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Class FieldTest
 *
 * Test for class \Magento\Ui\Component\Form\Field
 */
class FieldTest extends TestCase
{
    const NAME = 'test-name';
    const COMPONENT_NAME = 'test-name';
    const COMPONENT_NAMESPACE = 'test-name';

    /**
     * @var Field
     */
    protected $field;

    /**
     * @var UiComponentFactory|MockObject
     */
    protected $uiComponentFactoryMock;

    /**
     * @var ContextInterface|MockObject
     */
    protected $contextMock;

    /**
     * @var array
     */
    protected $testConfigData = [
        ['config', null, ['test-key' => 'test-value']],
        ['js_config', null, ['test-key' => 'test-value']]
    ];

    /**
     * Set up
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->uiComponentFactoryMock = $this->createMock(UiComponentFactory::class);
        $this->contextMock = $this->createMock(ContextInterface::class);

        $this->field = new Field(
            $this->contextMock,
            $this->uiComponentFactoryMock
        );
    }

    /**
     * Run test for prepare method
     *
     * @param array $data
     * @param array $expectedData
     * @return void
     *
     */
    public function testPrepareException()
    {
        $this->expectException('Magento\Framework\Exception\LocalizedException');
        $this->expectExceptionMessage(
            'The "formElement" configuration parameter is required for the "test-name" field.'
        );
        $this->contextMock->expects($this->never())->method('getProcessor');
        $this->uiComponentFactoryMock->expects($this->never())
            ->method('create');
        $this->field->setData(['name' => self::NAME]);
        $this->field->prepare();
    }
}
