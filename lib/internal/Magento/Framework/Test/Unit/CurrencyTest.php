<?php
/**
 * Copyright 2016 Adobe
 * All Rights Reserved.
 */
declare(strict_types=1);

namespace Magento\Framework\Test\Unit;

use Magento\Framework\App\CacheInterface;
use Magento\Framework\Cache\FrontendInterface;
use Magento\Framework\Currency;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Test for Magento\Framework\Currency
 */
class CurrencyTest extends TestCase
{
    public function testConstruct()
    {
        $frontendCache = $this->getMockForAbstractClass(FrontendInterface::class);
        /** @var CacheInterface|MockObject $appCache */
        $appCache = $this->getMockForAbstractClass(CacheInterface::class);
        $appCache->expects($this->once())
            ->method('getFrontend')
            ->willReturn($frontendCache);

        // Create new currency object
        $currency = new Currency($appCache, null, 'en_US');
        
        // Currency now stores the FrontendInterface directly, not the low-level frontend
        // This is correct for Symfony cache architecture
        $this->assertEquals($frontendCache, $currency->getCache());
        $this->assertEquals('USD', $currency->getShortName());
    }
}
