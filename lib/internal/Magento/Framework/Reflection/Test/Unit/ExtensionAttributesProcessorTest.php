<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Magento\Framework\Reflection\Test\Unit;

use Magento\Framework\Api\ExtensionAttribute\Config;
use Magento\Framework\Api\ExtensionAttribute\Config\Converter;
use Magento\Framework\AuthorizationInterface;
use Magento\Framework\Reflection\DataObjectProcessor;
use Magento\Framework\Reflection\ExtensionAttributesProcessor;
use Magento\Framework\Reflection\FieldNamer;
use Magento\Framework\Reflection\MethodsMap;
use Magento\Framework\Reflection\TypeCaster;
use Magento\Framework\TestFramework\Unit\Helper\ObjectManager;
use PHPUnit\Framework\TestCase;

class ExtensionAttributesProcessorTest extends TestCase
{
    /**
     * @var ExtensionAttributesProcessor
     */
    private $model;

    /**
     * @var DataObjectProcessor
     */
    private $dataObjectProcessorMock;

    /**
     * @var MethodsMap
     */
    private $methodsMapProcessorMock;

    /**
     * @var FieldNamer
     */
    private $fieldNamerMock;

    /**
     * @var TypeCaster
     */
    private $typeCasterMock;

    /**
     * @var Config
     */
    private $configMock;

    /**
     * @var AuthorizationInterface
     */
    private $authorizationMock;

    /**
     * Set up helper.
     */
    protected function setUp(): void
    {
        $objectManager = new ObjectManager($this);

        $this->dataObjectProcessorMock = $this->getMockBuilder(DataObjectProcessor::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->methodsMapProcessorMock = $this->getMockBuilder(MethodsMap::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->typeCasterMock = $this->getMockBuilder(TypeCaster::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->fieldNamerMock = $this->getMockBuilder(FieldNamer::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->configMock = $this->getMockBuilder(Config::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->authorizationMock = $this->getMockBuilder(AuthorizationInterface::class)
            ->disableOriginalConstructor()
            ->getMockForAbstractClass();

        $this->model = $objectManager->getObject(
            ExtensionAttributesProcessor::class,
            [
                'dataObjectProcessor' => $this->dataObjectProcessorMock,
                'methodsMapProcessor' => $this->methodsMapProcessorMock,
                'typeCaster' => $this->typeCasterMock,
                'fieldNamer' => $this->fieldNamerMock,
                'authorization' => $this->authorizationMock,
                'config' => $this->configMock,
                'isPermissionChecked' => true,
            ]
        );
    }

    /**
     * @param bool $isPermissionAllowed
     * @param array $expectedValue
     * @dataProvider buildOutputDataArrayWithPermissionProvider
     */
    public function testBuildOutputDataArrayWithPermission($isPermissionAllowed, $expectedValue)
    {
        $dataObject = new ExtensionAttributesObject();
        $dataObjectType = ExtensionAttributesObject::class;
        $methodName = 'getAttrName';
        $attributeName = 'attr_name';
        $attributeValue = 'attrName';

        $this->methodsMapProcessorMock->expects($this->once())
            ->method('getMethodsMap')
            ->with($dataObjectType)
            ->willReturn([$methodName => []]);
        $this->methodsMapProcessorMock->expects($this->once())
            ->method('isMethodValidForDataField')
            ->with($dataObjectType, $methodName)
            ->willReturn(true);
        $this->fieldNamerMock->expects($this->once())
            ->method('getFieldNameForMethodName')
            ->with($methodName)
            ->willReturn($attributeName);
        $permissionName = 'Magento_Permission';
        $this->configMock->expects($this->once())
            ->method('get')
            ->willReturn([
                $dataObjectType => [
                    $attributeName => [ Converter::RESOURCE_PERMISSIONS => [ $permissionName ] ]
                ]
            ]);
        $this->authorizationMock->expects($this->once())
            ->method('isAllowed')
            ->with($permissionName)
            ->willReturn($isPermissionAllowed);

        if ($isPermissionAllowed) {
            $this->methodsMapProcessorMock->expects($this->once())
                ->method('getMethodReturnType')
                ->with($dataObjectType, $methodName)
                ->willReturn('string');
            $this->typeCasterMock->expects($this->once())
                ->method('castValueToType')
                ->with($attributeValue, 'string')
                ->willReturn($attributeValue);
        }

        $value = $this->model->buildOutputDataArray(
            $dataObject,
            $dataObjectType
        );

        $this->assertEquals(
            $value,
            $expectedValue
        );
    }

    /**
     * @return array
     */
    public static function buildOutputDataArrayWithPermissionProvider()
    {
        return [
            'permission allowed' => [
                true,
                [
                    'attr_name' => 'attrName',
                ],
            ],
            'permission not allowed' => [
                false,
                [],
            ],
        ];
    }
}
