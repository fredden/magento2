<?php
/**
 * Copyright 2015 Adobe
 * All Rights Reserved.
 */
declare(strict_types=1);

namespace Magento\Backend\Test\Unit\Cron;

use Magento\Backend\Cron\CleanCache;
use Magento\Framework\App\Cache\Frontend\Pool;
use Magento\Framework\Cache\CacheConstants;
use Magento\Framework\Cache\FrontendInterface;
use Magento\Framework\TestFramework\Unit\Helper\ObjectManager;
use PHPUnit\Framework\TestCase;

class CleanCacheTest extends TestCase
{
    public function testCleanCache()
    {
        $cacheFrontendMock = $this->getMockForAbstractClass(FrontendInterface::class);
        $frontendPoolMock = $this->createMock(Pool::class);

        // Expect clean to be called on the frontend with CLEANING_MODE_OLD
        $cacheFrontendMock->expects(
            $this->once()
        )->method(
            'clean'
        )->with(
            CacheConstants::CLEANING_MODE_OLD,
            []
        )->willReturn(true);

        $frontendPoolMock->expects(
            $this->any()
        )->method(
            'valid'
        )->will(
            $this->onConsecutiveCalls(true, false)
        );

        $frontendPoolMock->expects(
            $this->any()
        )->method(
            'current'
        )->willReturn(
            $cacheFrontendMock
        );

        $objectManagerHelper = new ObjectManager($this);
        /**
         * @var CleanCache
         */
        $model = $objectManagerHelper->getObject(
            CleanCache::class,
            [
                'cacheFrontendPool' => $frontendPoolMock,
            ]
        );

        $model->execute();
    }
}
