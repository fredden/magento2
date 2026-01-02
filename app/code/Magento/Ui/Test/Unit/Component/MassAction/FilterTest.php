<?php
/**
 * Copyright 2016 Adobe
 * All Rights Reserved.
 */
declare(strict_types=1);

namespace Magento\Ui\Test\Unit\Component\MassAction;

use Magento\Framework\Api\Filter as ApiFilter;
use Magento\Framework\Api\FilterBuilder;
use Magento\Framework\Api\Search\SearchResultInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Data\Collection\AbstractDb;
use Magento\Framework\DataObject;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Model\ResourceModel\Db\AbstractDb as ResourceAbstractDb;
use Magento\Framework\TestFramework\Unit\Helper\MockCreationTrait;
use Magento\Framework\TestFramework\Unit\Helper\ObjectManager;
use Magento\Framework\View\Element\UiComponent\ContextInterface;
use Magento\Framework\View\Element\UiComponent\DataProvider\DataProviderInterface;
use Magento\Framework\View\Element\UiComponentFactory;
use Magento\Framework\View\Element\UiComponentInterface;
use Magento\Ui\Component\MassAction\Filter;
use Magento\Ui\DataProvider\AbstractDataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Ui component massaction filter tests
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class FilterTest extends TestCase
{
    use MockCreationTrait;

    /**
     * @var MockObject
     */
    private $requestMock;

    /**
     * @var MockObject
     */
    private $uiComponentFactoryMock;

    /**
     * @var MockObject
     */
    private $filterBuilderMock;

    /**
     * @var Filter
     */
    private $filter;

    /**
     * @var ObjectManager
     */
    private $objectManager;

    /**
     * @var MockObject
     */
    private $dataProviderMock;

    /**
     * @var MockObject
     */
    private $abstractDbMock;

    /**
     * @var MockObject
     */
    private $searchResultMock;

    /**
     * @var MockObject
     */
    private $uiComponentMock;

    /**
     * @var MockObject
     */
    private $contextMock;

    /**
     * @var MockObject
     */
    private $resourceAbstractDbMock;

    /**
     * @inheritDoc
     */
    protected function setUp(): void
    {
        $this->objectManager = new ObjectManager($this);
        $this->uiComponentFactoryMock = $this->createMock(UiComponentFactory::class);
        $this->filterBuilderMock = $this->createPartialMockWithReflection(
            FilterBuilder::class,
            ['setConditionType', 'create', 'setField', 'value']
        );
        $this->requestMock = $this->createMock(RequestInterface::class);
        $this->dataProviderMock = $this->createMock(DataProviderInterface::class);
        $this->uiComponentMock = $this->createMock(UiComponentInterface::class);
        $this->abstractDbMock = $this->createPartialMock(
            AbstractDb::class,
            ['getResource', 'addFieldToFilter']
        );
        $this->resourceAbstractDbMock = $this->createMock(ResourceAbstractDb::class);
        $this->contextMock = $this->createMock(ContextInterface::class);
        $this->searchResultMock = $this->createMock(SearchResultInterface::class);
        $uiComponentMockTwo = $this->createMock(UiComponentInterface::class);
        $this->filter = $this->objectManager->getObject(
            Filter::class,
            [
                'factory' => $this->uiComponentFactoryMock,
                'request' => $this->requestMock,
                'filterBuilder' => $this->filterBuilderMock
            ]
        );
        $this->uiComponentFactoryMock->expects($this->any())
            ->method('create')
            ->willReturn($this->uiComponentMock);
        $this->uiComponentMock->expects($this->any())
            ->method('getChildComponents')
            ->willReturn([$uiComponentMockTwo]);
        $uiComponentMockTwo->expects($this->any())
            ->method('getChildComponents')
            ->willReturn([]);
        $this->uiComponentMock->expects($this->any())
            ->method('getContext')
            ->willReturn($this->contextMock);
    }

    /**
     * Run test for applySelectionOnTargetProvider method
     *
     * @param int[]|bool $selectedIds
     * @param int[]|bool $excludedIds
     * @param int $filterExpected
     * @param string $conditionExpected
     *
     * @return void
     * @throws LocalizedException
     * */
    public function testGetComponentRefererUrlIsNull(): void
    {
        $this->contextMock->expects($this->any())
            ->method('getDataProvider')
            ->willReturn($this->dataProviderMock);
        $this->assertNull($this->filter->getComponentRefererUrl());
    }

    /**
     * Apply mocks for current parameters from datasource.
     *
     * @param int $filterExpected
     * @param string $conditionExpected
     *
     * @return void
     */
    private function setUpApplySelection($filterExpected, $conditionExpected): void
    {
        $this->contextMock->expects($this->any())
            ->method('getDataProvider')
            ->willReturn($this->dataProviderMock);
        $this->dataProviderMock->expects($this->any())
            ->method('setLimit');
        $this->dataProviderMock->expects($this->any())
            ->method('getSearchResult')
            ->willReturn($this->searchResultMock);
        $this->searchResultMock->expects($this->any())
            ->method('getItems')
            ->willReturn([new DataObject(['id' => 1])]);
        $filterMock = $this->createMock(ApiFilter::class);
        $this->dataProviderMock->expects($this->exactly($filterExpected))
            ->method('addFilter')
            ->with($filterMock);

        $this->filterBuilderMock->expects($this->exactly($filterExpected))
            ->method('setConditionType')
            ->with($conditionExpected)
            ->willReturnSelf();
        $this->filterBuilderMock->expects($this->any())
            ->method('setField')
            ->willReturnSelf();
        $this->filterBuilderMock->expects($this->any())
            ->method('value')
            ->willReturnSelf();
        $this->filterBuilderMock->expects($this->any())
            ->method('create')
            ->willReturn($filterMock);
        $this->filterBuilderMock->expects($this->any())
            ->method('setConditionType')
            ->willReturnSelf();
        $this->filterBuilderMock->expects($this->any())
            ->method('setField')
            ->willReturnSelf();
        $this->filterBuilderMock->expects($this->any())
            ->method('value')
            ->willReturnSelf();
        $this->filterBuilderMock->expects($this->any())
            ->method('create')
            ->willReturn($filterMock);
    }
}
