<?php
/**
 * Copyright 2016 Adobe
 * All Rights Reserved.
 */
declare(strict_types=1);

namespace Magento\Catalog\Test\Unit\Model\Indexer\Product\Flat;

use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Helper\Product\Flat\Indexer;
use Magento\Catalog\Model\Indexer\Product\Flat\FlatTableBuilder;
use Magento\Catalog\Model\Indexer\Product\Flat\TableDataInterface;
use Magento\Eav\Model\Entity\Attribute;
use Magento\Eav\Model\Entity\Attribute\Backend\AbstractBackend;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Ddl\Table;
use Magento\Framework\DB\Select;
use Magento\Framework\EntityManager\EntityMetadataInterface;
use Magento\Framework\EntityManager\MetadataPool;
use Magento\Framework\TestFramework\Unit\Helper\ObjectManager;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class FlatTableBuilderTest extends TestCase
{
    /**
     * @var Indexer|MockObject
     */
    private $flatIndexerMock;

    /**
     * @var ResourceConnection|MockObject
     */
    private $resourceMock;

    /**
     * @var ScopeConfigInterface|MockObject
     */
    private $scopeConfigMock;

    /**
     * @var StoreManagerInterface|MockObject
     */
    private $storeManagerMock;

    /**
     * @var TableDataInterface|MockObject
     */
    private $tableDataMock;

    /**
     * @var AdapterInterface|MockObject
     */
    private $connectionMock;

    /**
     * @var MetadataPool|MockObject
     */
    private $metadataPoolMock;

    /**
     * @var EntityMetadataInterface|MockObject
     */
    private $metadataMock;

    /**
     * @var FlatTableBuilder
     */
    private $flatTableBuilder;

    protected function setUp(): void
    {
        $objectManagerHelper = new ObjectManager($this);
        $this->flatIndexerMock = $this->createMock(Indexer::class);
        $this->resourceMock = $this->createMock(ResourceConnection::class);
        $this->scopeConfigMock = $this->createMock(ScopeConfigInterface::class);
        $this->storeManagerMock = $this->createMock(StoreManagerInterface::class);
        $this->tableDataMock = $this->createMock(TableDataInterface::class);
        $this->connectionMock = $this->createMock(AdapterInterface::class);
        $this->metadataPoolMock = $this->createMock(MetadataPool::class);
        $this->metadataMock = $this->createMock(EntityMetadataInterface::class);
        $this->metadataMock->method('getLinkField')->willReturn('entity_id');

        $this->flatTableBuilder = $objectManagerHelper->getObject(
            FlatTableBuilder::class,
            [
                'productIndexerHelper' => $this->flatIndexerMock,
                'resource' => $this->resourceMock,
                'config' => $this->scopeConfigMock,
                'storeManager' => $this->storeManagerMock,
                'tableData' => $this->tableDataMock,
                '_connection' => $this->connectionMock
            ]
        );
        $objectManagerHelper->setBackwardCompatibleProperty(
            $this->flatTableBuilder,
            'metadataPool',
            $this->metadataPoolMock
        );
    }

    /**
     * @return void
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
     */
    public function testBuild()
    {
        $storeId = 1;
        $changedIds = [];
        $valueFieldSuffix = '_value';
        $tableDropSuffix = '';
        $fillTmpTables = true;
        $tableName = 'catalog_product_entity';
        $attributeTable = 'catalog_product_entity_int';
        $temporaryTableName = 'catalog_product_entity_int_tmp_indexer';
        $temporaryValueTableName = 'catalog_product_entity_int_tmp_indexer_value';
        $linkField = 'entity_id';
        $statusId = 22;
        $eavCustomField = 'space_weight';
        $eavCustomValueField = $eavCustomField . $valueFieldSuffix;
        $this->flatIndexerMock->expects($this->once())->method('getAttributes')->willReturn([]);
        $this->flatIndexerMock->expects($this->exactly(3))->method('getFlatColumns')
            ->willReturnOnConsecutiveCalls([], [$eavCustomValueField => []], [$eavCustomValueField => []]);
        $this->flatIndexerMock->expects($this->once())->method('getFlatIndexes')->willReturn([]);
        $statusAttributeMock = $this->createMock(Attribute::class);
        $eavCustomAttributeMock = $this->createMock(Attribute::class);
        $this->flatIndexerMock->expects($this->once())->method('getTablesStructure')
            ->willReturn(
                [
                    'catalog_product_entity' => [$linkField => $statusAttributeMock],
                    'catalog_product_entity_int' => [
                        $linkField => $statusAttributeMock,
                        $eavCustomField => $eavCustomAttributeMock
                    ]
                ]
            );
        $this->flatIndexerMock->expects($this->atLeastOnce())->method('getTable')
                ->willReturnCallback(
                    function ($arg) use ($tableName) {
                        if ($arg == $tableName) {
                            return $tableName;
                        } elseif ($arg == 'catalog_product_website') {
                            return 'catalog_product_website';
                        }
                    }
                );
        $this->flatIndexerMock->expects($this->once())->method('getAttribute')
            ->with('status')
            ->willReturn($statusAttributeMock);
        $backendMock = $this->createMock(AbstractBackend::class);
        $backendMock->expects($this->atLeastOnce())->method('getTable')->willReturn($attributeTable);
        $statusAttributeMock->expects($this->atLeastOnce())->method('getBackend')->willReturn(
            $backendMock
        );
        $statusAttributeMock->expects($this->atLeastOnce())->method('getAttributeCode')->willReturn($linkField);
        $eavCustomAttributeMock->expects($this->atLeastOnce())->method('getBackend')->willReturn(
            $backendMock
        );
        $eavCustomAttributeMock->expects($this->atLeastOnce())->method('getAttributeCode')
            ->willReturn($eavCustomField);
        $statusAttributeMock->expects($this->atLeastOnce())->method('getId')->willReturn($statusId);
        $tableMock = $this->createMock(Table::class);
        $this->connectionMock->method('newTable')->willReturn($tableMock);
        $selectMock = $this->createMock(Select::class);
        $this->connectionMock->expects($this->any())->method('select')->willReturn($selectMock);
        $selectMock->expects($this->once())->method('from')->with(
            ['et' => 'catalog_product_entity_tmp_indexer'],
            [$linkField, 'type_id', 'attribute_set_id']
        )->willReturnSelf();
        $selectMock->expects($this->any())->method('joinInner')->willReturnSelf();
        $selectMock->expects($this->any())->method('joinLeft')
            ->willReturnCallback(
                function (
                    $arg1,
                    $arg2,
                    $arg3
                ) use (
                    $selectMock,
                    $attributeTable,
                    $linkField,
                    $storeId,
                    $statusId,
                    $temporaryTableName,
                    $eavCustomField,
                    $temporaryValueTableName,
                    $eavCustomValueField
                ) {
                    // Original case 1: status attribute join
                    if ($arg1 === ['dstatus' => $attributeTable] &&
                        $arg2 ===
                        sprintf(
                            'e.%s = dstatus.%s AND dstatus.store_id = %s AND dstatus.attribute_id = %s',
                            $linkField,
                            $linkField,
                            $storeId,
                            $statusId
                        )
                        && empty($arg3)) {
                            return $selectMock;
                    }
                    // Original case 2: temporary table join for entity_id
                    elseif ($arg1 === $temporaryTableName &&
                        $arg2 === "e.{$linkField} = {$temporaryTableName}.{$linkField}" &&
                        $arg3 === [$linkField, $eavCustomField]) {
                            return $selectMock;
                    }
                    // Original case 3: temporary value table join
                    elseif ($arg1 === $temporaryValueTableName &&
                        $arg2 === "e.{$linkField} = {$temporaryValueTableName}.{$linkField}" &&
                        $arg3 === [$eavCustomValueField]) {
                            return $selectMock;
                    }
                    // New case 4: eav_attribute_option_value join for store 0 (default values)
                    // This happens when $columnName exists in $flatColumns (line 367)
                    elseif (is_array($arg1) && isset($arg1['t0']) &&
                        strpos($arg2, 't0.option_id = et.' . $eavCustomField) !== false &&
                        strpos($arg2, 'AND t0.store_id = 0') !== false &&
                        empty($arg3)) {
                            return $selectMock;
                    }
                    // New case 5: eav_attribute_option_value join for specific store
                    elseif (is_array($arg1) && isset($arg1['ts']) &&
                        strpos($arg2, 'ts.option_id = et.' . $eavCustomField) !== false &&
                        strpos($arg2, 'AND ts.store_id = ' . $storeId) !== false &&
                        empty($arg3)) {
                            return $selectMock;
                    }
                    // New case 6: Same joins but for entity_id attribute (linkField)
                    elseif (is_array($arg1) && isset($arg1['t0']) &&
                        strpos($arg2, 't0.option_id = et.' . $linkField) !== false &&
                        strpos($arg2, 'AND t0.store_id = 0') !== false &&
                        empty($arg3)) {
                            return $selectMock;
                    }
                    // New case 7: entity_id attribute for specific store
                    elseif (is_array($arg1) && isset($arg1['ts']) &&
                        strpos($arg2, 'ts.option_id = et.' . $linkField) !== false &&
                        strpos($arg2, 'AND ts.store_id = ' . $storeId) !== false &&
                        empty($arg3)) {
                            return $selectMock;
                    }
                    // Always return selectMock to prevent null errors
                    return $selectMock;
                }
            );
        $selectMock->expects($this->any())->method('where')->willReturnSelf();
        $selectMock->expects($this->any())->method('columns')->willReturnSelf();
        $selectMock->expects($this->any())->method('crossUpdateFromSelect')->willReturn('');
        $this->connectionMock->expects($this->any())->method('query')->willReturn(true);
        $this->connectionMock->expects($this->any())->method('quoteInto')->willReturnCallback(
            function ($text, $value) {
                return str_replace('?', is_array($value) ? implode(',', $value) : $value, $text);
            }
        );
        $this->connectionMock->expects($this->any())->method('getIfNullSql')->willReturnCallback(
            function ($arg1, $arg2) {
                return "IFNULL($arg1, $arg2)";
            }
        );
        $this->metadataPoolMock->expects($this->atLeastOnce())->method('getMetadata')->with(ProductInterface::class)
            ->willReturn($this->metadataMock);
        $storeMock = $this->createMock(StoreInterface::class);
        $this->storeManagerMock->expects($this->once())->method('getStore')->with($storeId)->willReturn($storeMock);
        $this->flatTableBuilder->build($storeId, $changedIds, $valueFieldSuffix, $tableDropSuffix, $fillTmpTables);
    }
}
