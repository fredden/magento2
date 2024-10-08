<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Magento\Framework\Indexer\Test\Unit\Config;

use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Indexer\Config\DependencyInfoProvider;
use Magento\Framework\Indexer\ConfigInterface;
use Magento\Framework\TestFramework\Unit\Helper\ObjectManager as ObjectManagerHelper;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class DependencyInfoProviderTest extends TestCase
{
    /**
     * @var ObjectManagerHelper
     */
    private $objectManagerHelper;

    /**
     * @var ConfigInterface|MockObject
     */
    private $configMock;

    /**
     * @var DependencyInfoProvider
     */
    private $dependencyInfoProvider;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        $this->objectManagerHelper = new ObjectManagerHelper($this);

        $this->configMock = $this->getMockBuilder(ConfigInterface::class)
            ->getMockForAbstractClass();

        $this->dependencyInfoProvider = $this->objectManagerHelper->getObject(
            DependencyInfoProvider::class,
            [
                'config' => $this->configMock,
            ]
        );
    }

    public function testGetDependencies()
    {
        $indexerId = 'indexer_1';
        $dependencies = [
            'indexer_2',
            'indexer_3',
        ];
        $this->addSeparateIndexersToConfigMock([
            [
                'indexer_id' => $indexerId,
                'dependencies' => $dependencies,
            ]
        ]);
        $this->assertSame($dependencies, $this->dependencyInfoProvider->getIndexerIdsToRunBefore($indexerId));
    }

    public function testGetDependenciesNonExistentIndexer()
    {
        $indexerId = 'indexer_1';
        $this->configMock
            ->method('getIndexer')
            ->willReturn([]);
        $this->expectException(NoSuchEntityException::class);
        $this->expectExceptionMessage((string)__("%1 indexer does not exist.", $indexerId));
        $this->dependencyInfoProvider->getIndexerIdsToRunBefore($indexerId);
    }

    /**
     * @param string $indexerId
     * @param array $indexersData
     * @param array $dependentIndexerIds
     * @dataProvider getDependentIndexerIdsDataProvider
     */
    public function testGetDependentIndexerIds(string $indexerId, array $indexersData, array $dependentIndexerIds)
    {
        $this->addSeparateIndexersToConfigMock($indexersData);
        $this->addAllIndexersToConfigMock($indexersData);
        $this->assertSame(
            $dependentIndexerIds,
            array_values($this->dependencyInfoProvider->getIndexerIdsToRunAfter($indexerId))
        );
    }

    /**
     * @return array
     */
    public static function getDependentIndexerIdsDataProvider()
    {
        return [
            [
                'indexerId' => 'indexer_2',
                'indexersData' => [
                    'indexer_2' => [
                        'indexer_id' => 'indexer_2',
                        'dependencies' => [],
                    ],
                    'indexer_4' => [
                        'indexer_id' => 'indexer_4',
                        'dependencies' => [
                            'indexer_2',
                        ],
                    ],
                    'indexer_3' => [
                        'indexer_id' => 'indexer_3',
                        'dependencies' => [
                            'indexer_4',
                        ],
                    ],
                    'indexer_1' => [
                        'indexer_id' => 'indexer_1',
                        'dependencies' => [
                            'indexer_2',
                            'indexer_3',
                        ],
                    ],
                    'indexer_5' => [
                        'indexer_id' => 'indexer_5',
                        'dependencies' => [],
                    ],
                ],
                'dependentIndexerIds' => ['indexer_4', 'indexer_1'],
            ]
        ];
    }

    public function testGetDependentIndexerIdsNonExistentIndexer()
    {
        $indexerId = 'indexer_1';
        $this->configMock
            ->method('getIndexer')
            ->willReturn([]);
        $this->expectException(NoSuchEntityException::class);
        $this->expectExceptionMessage((string)__("%1 indexer does not exist.", $indexerId));
        $this->dependencyInfoProvider->getIndexerIdsToRunAfter($indexerId);
    }

    /**
     * @param array $indexers
     */
    private function addSeparateIndexersToConfigMock(array $indexers)
    {
        $this->configMock
            ->method('getIndexer')
            ->willReturnMap(
                array_map(
                    function ($elem) {
                        return [$elem['indexer_id'], $elem];
                    },
                    $indexers
                )
            );
    }

    /**
     * @param array $indexers
     */
    private function addAllIndexersToConfigMock(array $indexers)
    {
        $this->configMock
            ->method('getIndexers')
            ->willReturn($indexers);
    }
}
