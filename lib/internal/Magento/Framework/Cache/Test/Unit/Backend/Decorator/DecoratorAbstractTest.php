<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

/**
 * \Magento\Framework\Cache\Backend\Decorator\AbstractDecorator test case
 */
namespace Magento\Framework\Cache\Test\Unit\Backend\Decorator;

use Magento\Framework\Cache\Backend\Decorator\AbstractDecorator;
use Magento\Framework\Cache\Backend\ExtendedBackendInterface;
use Magento\Framework\Cache\Exception\CacheException;
use PHPUnit\Framework\TestCase;

class DecoratorAbstractTest extends TestCase
{
    /**
     * @var ExtendedBackendInterface
     */
    protected $_mockBackend;

    protected function setUp(): void
    {
        $this->_mockBackend = $this->createMock(ExtendedBackendInterface::class);
    }

    protected function tearDown(): void
    {
        unset($this->_mockBackend);
    }

    public function testConstructor()
    {
        $options = ['concrete_backend' => $this->_mockBackend, 'testOption' => 'testOption'];

        $decorator = $this->getMockForAbstractClass(
            AbstractDecorator::class,
            [$options]
        );

        $backendProperty = new \ReflectionProperty(
            AbstractDecorator::class,
            '_backend'
        );
        $backendProperty->setAccessible(true);

        $optionsProperty = new \ReflectionProperty(
            AbstractDecorator::class,
            '_decoratorOptions'
        );
        $optionsProperty->setAccessible(true);

        $this->assertSame($backendProperty->getValue($decorator), $this->_mockBackend);

        $this->assertArrayNotHasKey('concrete_backend', $optionsProperty->getValue($decorator));
        $this->assertArrayNotHasKey('testOption', $optionsProperty->getValue($decorator));
    }

    /**
     * @param array $options
     * @dataProvider constructorExceptionDataProvider
     */
    public function testConstructorException($options)
    {
        if (!empty($options)) {
           $options['concrete_backend'] = $options['concrete_backend']($this);
        }

        $this->expectException(CacheException::class);
        $this->getMockForAbstractClass(AbstractDecorator::class, [$options]);
    }

    /**
     * @return array
     */
    public static function constructorExceptionDataProvider()
    {
        return [
            'empty' => [[]],
            'wrong_class' => [['concrete_backend' => static fn (self $testCase) => $testCase->getMockBuilder('Test_Class')
                ->getMock()]]
        ];
    }

    public function testLoad()
    {
        $this->_mockBackend->expects($this->once())
            ->method('load')
            ->with('test_id', false)
            ->willReturn('test_data');

        $decorator = $this->getMockForAbstractClass(
            AbstractDecorator::class,
            [['concrete_backend' => $this->_mockBackend]]
        );

        $result = $decorator->load('test_id', false);
        $this->assertEquals('test_data', $result);
    }

    public function testTest()
    {
        $this->_mockBackend->expects($this->once())
            ->method('test')
            ->with('test_id')
            ->willReturn(12345);

        $decorator = $this->getMockForAbstractClass(
            AbstractDecorator::class,
            [['concrete_backend' => $this->_mockBackend]]
        );

        $result = $decorator->test('test_id');
        $this->assertEquals(12345, $result);
    }

    public function testSave()
    {
        $this->_mockBackend->expects($this->once())
            ->method('save')
            ->with('test_data', 'test_id', ['tag1'], 3600)
            ->willReturn(true);

        $decorator = $this->getMockForAbstractClass(
            AbstractDecorator::class,
            [['concrete_backend' => $this->_mockBackend]]
        );

        $result = $decorator->save('test_data', 'test_id', ['tag1'], 3600);
        $this->assertTrue($result);
    }

    public function testRemove()
    {
        $this->_mockBackend->expects($this->once())
            ->method('remove')
            ->with('test_id')
            ->willReturn(true);

        $decorator = $this->getMockForAbstractClass(
            AbstractDecorator::class,
            [['concrete_backend' => $this->_mockBackend]]
        );

        $result = $decorator->remove('test_id');
        $this->assertTrue($result);
    }

    public function testClean()
    {
        $this->_mockBackend->expects($this->once())
            ->method('clean')
            ->with('matchingTag', ['tag1'])
            ->willReturn(true);

        $decorator = $this->getMockForAbstractClass(
            AbstractDecorator::class,
            [['concrete_backend' => $this->_mockBackend]]
        );

        $result = $decorator->clean('matchingTag', ['tag1']);
        $this->assertTrue($result);
    }

    public function testGetIds()
    {
        $this->_mockBackend->expects($this->once())
            ->method('getIds')
            ->willReturn(['id1', 'id2']);

        $decorator = $this->getMockForAbstractClass(
            AbstractDecorator::class,
            [['concrete_backend' => $this->_mockBackend]]
        );

        $result = $decorator->getIds();
        $this->assertEquals(['id1', 'id2'], $result);
    }

    public function testGetTags()
    {
        $this->_mockBackend->expects($this->once())
            ->method('getTags')
            ->willReturn(['tag1', 'tag2']);

        $decorator = $this->getMockForAbstractClass(
            AbstractDecorator::class,
            [['concrete_backend' => $this->_mockBackend]]
        );

        $result = $decorator->getTags();
        $this->assertEquals(['tag1', 'tag2'], $result);
    }

    public function testGetIdsMatchingTags()
    {
        $this->_mockBackend->expects($this->once())
            ->method('getIdsMatchingTags')
            ->with(['tag1'])
            ->willReturn(['id1']);

        $decorator = $this->getMockForAbstractClass(
            AbstractDecorator::class,
            [['concrete_backend' => $this->_mockBackend]]
        );

        $result = $decorator->getIdsMatchingTags(['tag1']);
        $this->assertEquals(['id1'], $result);
    }

    public function testGetIdsNotMatchingTags()
    {
        $this->_mockBackend->expects($this->once())
            ->method('getIdsNotMatchingTags')
            ->with(['tag1'])
            ->willReturn(['id2']);

        $decorator = $this->getMockForAbstractClass(
            AbstractDecorator::class,
            [['concrete_backend' => $this->_mockBackend]]
        );

        $result = $decorator->getIdsNotMatchingTags(['tag1']);
        $this->assertEquals(['id2'], $result);
    }

    public function testGetIdsMatchingAnyTags()
    {
        $this->_mockBackend->expects($this->once())
            ->method('getIdsMatchingAnyTags')
            ->with(['tag1'])
            ->willReturn(['id1', 'id3']);

        $decorator = $this->getMockForAbstractClass(
            AbstractDecorator::class,
            [['concrete_backend' => $this->_mockBackend]]
        );

        $result = $decorator->getIdsMatchingAnyTags(['tag1']);
        $this->assertEquals(['id1', 'id3'], $result);
    }

    public function testGetFillingPercentage()
    {
        $this->_mockBackend->expects($this->once())
            ->method('getFillingPercentage')
            ->willReturn(75);

        $decorator = $this->getMockForAbstractClass(
            AbstractDecorator::class,
            [['concrete_backend' => $this->_mockBackend]]
        );

        $result = $decorator->getFillingPercentage();
        $this->assertEquals(75, $result);
    }

    public function testGetMetadatas()
    {
        $metadata = ['expire' => 123456, 'tags' => ['tag1'], 'mtime' => 123450];

        $this->_mockBackend->expects($this->once())
            ->method('getMetadatas')
            ->with('test_id')
            ->willReturn($metadata);

        $decorator = $this->getMockForAbstractClass(
            AbstractDecorator::class,
            [['concrete_backend' => $this->_mockBackend]]
        );

        $result = $decorator->getMetadatas('test_id');
        $this->assertEquals($metadata, $result);
    }

    public function testTouch()
    {
        $this->_mockBackend->expects($this->once())
            ->method('touch')
            ->with('test_id', 100)
            ->willReturn(true);

        $decorator = $this->getMockForAbstractClass(
            AbstractDecorator::class,
            [['concrete_backend' => $this->_mockBackend]]
        );

        $result = $decorator->touch('test_id', 100);
        $this->assertTrue($result);
    }

    public function testGetCapabilities()
    {
        $capabilities = ['automatic_cleaning' => true, 'tags' => true];

        $this->_mockBackend->expects($this->once())
            ->method('getCapabilities')
            ->willReturn($capabilities);

        $decorator = $this->getMockForAbstractClass(
            AbstractDecorator::class,
            [['concrete_backend' => $this->_mockBackend]]
        );

        $result = $decorator->getCapabilities();
        $this->assertEquals($capabilities, $result);
    }

    public function testSetOption()
    {
        $this->_mockBackend->expects($this->once())
            ->method('setOption')
            ->with('test_option', 'test_value');

        $decorator = $this->getMockForAbstractClass(
            AbstractDecorator::class,
            [['concrete_backend' => $this->_mockBackend]]
        );

        $decorator->setOption('test_option', 'test_value');
    }
}
