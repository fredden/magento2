<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Magento\Framework\App\Test\Unit\Response\HeaderProvider;

use Magento\Framework\App\Response\HeaderProvider\XssProtection;
use Magento\Framework\HTTP\Header;
use Magento\Framework\TestFramework\Unit\Helper\ObjectManager;
use PHPUnit\Framework\TestCase;

class XssProtectionTest extends TestCase
{
    /**
     * @dataProvider userAgentDataProvider
     * @param string $userAgent
     * @param string $expectedHeader
     */
    public function testGetValue($userAgent, $expectedHeader)
    {
        $headerServiceMock = $this->getMockBuilder(Header::class)
            ->disableOriginalConstructor()
            ->getMock();
        $headerServiceMock->expects($this->once())->method('getHttpUserAgent')->willReturn($userAgent);
        $model = (new ObjectManager($this))->getObject(
            XssProtection::class,
            ['headerService' => $headerServiceMock]
        );
        $this->assertSame($expectedHeader, $model->getValue());
    }

    /**
     * @return array
     */
    public static function userAgentDataProvider()
    {
        return [
            [
                'userAgent' => 'Mozilla/5.0 (compatible; MSIE 8.0; Windows NT 6.1; Trident/4.0; GTB7.4)',
                'expectedHeader' => XssProtection::HEADER_DISABLED
            ],
            [
                'userAgent' => 'Mozilla/5.0 (compatible; MSIE 10.0; Windows NT 6.1; Trident/4.0; GTB7.4)',
                'expectedHeader' => XssProtection::HEADER_ENABLED
            ],
            [
                'userAgent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_10_1) Chrome/41.0.2227.1 Safari/537.36',
                'expectedHeader' => XssProtection::HEADER_ENABLED
            ],
        ];
    }
}
