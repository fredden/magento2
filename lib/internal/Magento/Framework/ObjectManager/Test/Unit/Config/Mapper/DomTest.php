<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Magento\Framework\ObjectManager\Test\Unit\Config\Mapper;

use Magento\Framework\Data\Argument\InterpreterInterface;
use Magento\Framework\ObjectManager\Config\Mapper\ArgumentParser;
use Magento\Framework\ObjectManager\Config\Mapper\Dom;
use Magento\Framework\Stdlib\BooleanUtils;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class DomTest extends TestCase
{
    /**
     * @var Dom
     */
    protected $_mapper;

    /**
     * @var MockObject
     */
    protected $argumentInterpreter;

    protected function setUp(): void
    {
        $argumentParser = $this->createMock(ArgumentParser::class);
        $argumentParser->expects(
            $this->any()
        )->method(
            'parse'
        )->willReturnCallback(
            [$this, 'parserMockCallback']
        );

        $booleanUtils = $this->createMock(BooleanUtils::class);
        $booleanUtils->expects(
            $this->any()
        )->method(
            'toBoolean'
        )->willReturnMap(
            [['true', true], ['false', false]]
        );

        $this->argumentInterpreter = $this->getMockForAbstractClass(InterpreterInterface::class);
        $this->argumentInterpreter->expects(
            $this->any()
        )->method(
            'evaluate'
        )->with(
            ['xsi:type' => 'string', 'value' => 'test value']
        )->willReturn(
            'test value'
        );
        $this->_mapper = new Dom($this->argumentInterpreter, $booleanUtils, $argumentParser);
    }

    public function testConvert()
    {
        $dom = new \DOMDocument();
        $xmlFile = __DIR__ . '/_files/simple_di_config.xml';
        $dom->loadXML(file_get_contents($xmlFile));

        $resultFile = __DIR__ . '/_files/mapped_simple_di_config.php';
        $expectedResult = include $resultFile;
        $this->assertEquals($expectedResult, $this->_mapper->convert($dom));
    }

    /**
     * Callback for mocking parse() method of the argument parser
     *
     * @param \DOMElement $argument
     * @return string
     */
    public function parserMockCallback(\DOMElement $argument)
    {
        $this->assertNotEmpty($argument->getAttribute('name'));
        $this->assertNotEmpty($argument->getAttribute('xsi:type'));
        return ['xsi:type' => 'string', 'value' => 'test value'];
    }

    /**
     * @param string $xmlData
     * @dataProvider wrongXmlDataProvider
     */
    public function testMapThrowsExceptionWhenXmlHasWrongFormat($xmlData)
    {
        $this->expectException('Exception');
        $this->expectExceptionMessage('Invalid application config. Unknown node: wrong_node.');
        $dom = new \DOMDocument();
        $dom->loadXML($xmlData);
        $this->_mapper->convert($dom);
    }

    /**
     * @return array
     */
    public static function wrongXmlDataProvider()
    {
        return [
            [
                '<?xml version="1.0"?><config><type name="some_type">' .
                '<wrong_node name="wrong_node" />' .
                '</type></config>',
            ],
            [
                '<?xml version="1.0"?><config><virtualType name="some_type">' .
                '<wrong_node name="wrong_node" />' .
                '</virtualType></config>'
            ],
            [
                '<?xml version="1.0"?><config>' .
                '<preference for="some_interface" type="some_class" />' .
                '<wrong_node name="wrong_node" />' .
                '</config>'
            ]
        ];
    }
}
