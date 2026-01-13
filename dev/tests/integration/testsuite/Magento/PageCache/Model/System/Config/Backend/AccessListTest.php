<?php
/**
 * Copyright 2026 Adobe
 * All Rights Reserved.
 */
declare(strict_types=1);

namespace Magento\PageCache\Model\System\Config\Backend;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\TestFramework\Helper\Bootstrap;
use PHPUnit\Framework\TestCase;

/**
 * Integration test for AccessList config backend model
 *
 * Tests validation of access list values including:
 * - IP addresses (IPv4 and IPv6)
 * - Hostnames
 * - CIDR notation support
 *
 * @magentoAppArea adminhtml
 */
class AccessListTest extends TestCase
{
    /**
     * @var AccessList
     */
    private $model;

    /**
     * @var ScopeConfigInterface
     */
    private $config;

    /**
     * @inheritdoc
     */
    protected function setUp(): void
    {
        $objectManager = Bootstrap::getObjectManager();
        $this->config = $objectManager->create(ScopeConfigInterface::class);
        $this->model = $objectManager->create(AccessList::class);
    }

    /**
     * Test that valid IP addresses are accepted
     *
     * @dataProvider validIpAddressesDataProvider
     * @param string $value
     * @param string $description
     */
    public function testValidIpAddresses(string $value, string $description): void
    {
        $this->model->setValue($value);
        $this->model->setPath('system/full_page_cache/caching_application/access_list');
        $this->model->setField('access_list');
        
        // Should not throw exception
        $result = $this->model->beforeSave();
        
        $this->assertInstanceOf(AccessList::class, $result);
        $this->assertEquals($value, $this->model->getValue(), "Failed for: {$description}");
    }

    /**
     * Data provider for valid IP addresses
     *
     * @return array
     */
    public static function validIpAddressesDataProvider(): array
    {
        return [
            ['127.0.0.1', 'IPv4 localhost'],
            ['192.168.1.1', 'IPv4 private network'],
            ['10.0.0.1', 'IPv4 private network (10.x)'],
            ['172.16.0.1', 'IPv4 private network (172.16.x)'],
            ['8.8.8.8', 'IPv4 public DNS'],
            ['::1', 'IPv6 localhost'],
            ['2001:0db8:85a3:0000:0000:8a2e:0370:7334', 'IPv6 full notation'],
            ['2001:db8::1', 'IPv6 compressed notation'],
            ['fe80::1', 'IPv6 link-local'],
            ['::ffff:192.0.2.1', 'IPv6 mapped IPv4'],
        ];
    }

    /**
     * Test that valid hostnames are accepted
     *
     * @dataProvider validHostnamesDataProvider
     * @param string $value
     * @param string $description
     */
    public function testValidHostnames(string $value, string $description): void
    {
        $this->model->setValue($value);
        $this->model->setPath('system/full_page_cache/caching_application/access_list');
        $this->model->setField('access_list');
        
        $result = $this->model->beforeSave();
        
        $this->assertInstanceOf(AccessList::class, $result);
        $this->assertEquals($value, $this->model->getValue(), "Failed for: {$description}");
    }

    /**
     * Data provider for valid hostnames
     *
     * @return array
     */
    public static function validHostnamesDataProvider(): array
    {
        return [
            ['localhost', 'localhost'],
            ['example.com', 'domain'],
            ['sub.example.com', 'subdomain'],
            ['my-server.local', 'hyphenated hostname'],
            ['server01', 'alphanumeric hostname'],
            ['cache-server-01.internal.corp', 'complex hostname'],
        ];
    }

    /**
     * Test that valid CIDR notation is accepted for IPv4
     *
     * @dataProvider validCidrIpv4DataProvider
     * @param string $value
     * @param string $description
     */
    public function testValidCidrNotationIpv4(string $value, string $description): void
    {
        $this->model->setValue($value);
        $this->model->setPath('system/full_page_cache/caching_application/access_list');
        $this->model->setField('access_list');
        
        $result = $this->model->beforeSave();
        
        $this->assertInstanceOf(AccessList::class, $result);
        $this->assertEquals($value, $this->model->getValue(), "Failed for: {$description}");
    }

    /**
     * Data provider for valid IPv4 CIDR notation
     *
     * @return array
     */
    public static function validCidrIpv4DataProvider(): array
    {
        return [
            ['192.168.1.0/24', 'IPv4 /24 network'],
            ['10.0.0.0/8', 'IPv4 /8 network'],
            ['172.16.0.0/12', 'IPv4 /12 network'],
            ['192.168.1.0/32', 'IPv4 single host /32'],
            ['0.0.0.0/0', 'IPv4 all addresses /0'],
            ['192.168.1.128/25', 'IPv4 /25 subnet'],
            ['10.10.10.0/30', 'IPv4 /30 point-to-point'],
            ['192.168.0.0/16', 'IPv4 /16 network'],
        ];
    }

    /**
     * Test that valid CIDR notation is accepted for IPv6
     *
     * @dataProvider validCidrIpv6DataProvider
     * @param string $value
     * @param string $description
     */
    public function testValidCidrNotationIpv6(string $value, string $description): void
    {
        $this->model->setValue($value);
        $this->model->setPath('system/full_page_cache/caching_application/access_list');
        $this->model->setField('access_list');
        
        $result = $this->model->beforeSave();
        
        $this->assertInstanceOf(AccessList::class, $result);
        $this->assertEquals($value, $this->model->getValue(), "Failed for: {$description}");
    }

    /**
     * Data provider for valid IPv6 CIDR notation
     *
     * @return array
     */
    public static function validCidrIpv6DataProvider(): array
    {
        return [
            ['2001:db8::/32', 'IPv6 /32 network'],
            ['fe80::/10', 'IPv6 link-local /10'],
            ['::/0', 'IPv6 all addresses /0'],
            ['ff00::/8', 'IPv6 multicast /8'],
            ['2001:0db8:/32', 'IPv6 partial notation with CIDR'],
        ];
    }

    /**
     * Test that multiple valid values separated by commas are accepted
     *
     * @dataProvider validMultipleValuesDataProvider
     * @param string $value
     * @param string $description
     */
    public function testValidMultipleValues(string $value, string $description): void
    {
        $this->model->setValue($value);
        $this->model->setPath('system/full_page_cache/caching_application/access_list');
        $this->model->setField('access_list');
        
        $result = $this->model->beforeSave();
        
        $this->assertInstanceOf(AccessList::class, $result);
        $this->assertEquals($value, $this->model->getValue(), "Failed for: {$description}");
    }

    /**
     * Data provider for multiple valid values
     *
     * @return array
     */
    public static function validMultipleValuesDataProvider(): array
    {
        return [
            ['127.0.0.1, localhost', 'IPv4 and hostname'],
            ['192.168.1.0/24, 10.0.0.0/8', 'Multiple IPv4 CIDR'],
            ['::1, 127.0.0.1, localhost', 'IPv6, IPv4, and hostname'],
            ['2001:db8::/32, fe80::/10', 'Multiple IPv6 CIDR'],
            ['192.168.1.1, 192.168.1.0/24, example.com', 'Mixed types'],
            ['10.0.0.1, 172.16.0.0/12, cache.local, 2001:db8::1', 'Complex mixed list'],
        ];
    }

    /**
     * Test that values with extra whitespace are handled correctly
     */
    public function testValuesWithWhitespace(): void
    {
        $value = '  192.168.1.1  ,  localhost  ,  10.0.0.0/8  ';
        
        $this->model->setValue($value);
        $this->model->setPath('system/full_page_cache/caching_application/access_list');
        $this->model->setField('access_list');
        
        $result = $this->model->beforeSave();
        
        $this->assertInstanceOf(AccessList::class, $result);
        $this->assertEquals($value, $this->model->getValue());
    }

    /**
     * Test that invalid CIDR notation is rejected
     *
     * @dataProvider invalidCidrDataProvider
     * @param string $value
     * @param string $description
     */
    public function testInvalidCidrNotation(string $value, string $description): void
    {
        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('is not valid');
        
        $this->model->setValue($value);
        $this->model->setPath('system/full_page_cache/caching_application/access_list');
        $this->model->setField('access_list');
        
        $this->model->beforeSave();
    }

    /**
     * Data provider for invalid CIDR notation
     *
     * @return array
     */
    public static function invalidCidrDataProvider(): array
    {
        return [
            ['192.168.1.0/33', 'IPv4 CIDR > 32'],
            ['192.168.1.0/99', 'IPv4 CIDR > 32 (large)'],
            ['192.168.1.0/-1', 'IPv4 CIDR negative'],
            ['192.168.1.0/', 'IPv4 CIDR empty'],
            ['192.168.1.0/abc', 'IPv4 CIDR non-numeric'],
        ];
    }

    /**
     * Test that invalid characters are rejected
     *
     * @dataProvider invalidCharactersDataProvider
     * @param string $value
     * @param string $description
     */
    public function testInvalidCharacters(string $value, string $description): void
    {
        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('is not valid');
        
        $this->model->setValue($value);
        $this->model->setPath('system/full_page_cache/caching_application/access_list');
        $this->model->setField('access_list');
        
        $this->model->beforeSave();
    }

    /**
     * Data provider for invalid characters
     *
     * @return array
     */
    public static function invalidCharactersDataProvider(): array
    {
        return [
            ['192.168.1.1;rm -rf /', 'Command injection attempt'],
            ['<script>alert("xss")</script>', 'XSS attempt'],
            ['{*I am not an IP*}', 'Invalid characters in braces'],
            ['\\invalid\\path\\', 'Backslashes'],
            ['192.168.1.1 OR 1=1', 'SQL injection attempt'],
            ['192.168.1.1`whoami`', 'Command substitution attempt'],
            ['../../etc/passwd', 'Path traversal attempt'],
        ];
    }

    /**
     * Test that non-string values are rejected
     *
     * @dataProvider invalidTypeDataProvider
     * @param mixed $value
     * @param string $description
     */
    public function testInvalidValueTypes($value, string $description): void
    {
        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('is not valid');
        
        $this->model->setValue($value);
        $this->model->setPath('system/full_page_cache/caching_application/access_list');
        $this->model->setField('access_list');
        
        $this->model->beforeSave();
    }

    /**
     * Data provider for invalid value types
     *
     * @return array
     */
    public static function invalidTypeDataProvider(): array
    {
        return [
            [123, 'Integer value'],
            [123.456, 'Float value'],
            [true, 'Boolean value'],
            [['192.168.1.1'], 'Array value'],
        ];
    }

    /**
     * Test that mixed valid and invalid values in comma-separated list are rejected
     *
     * @dataProvider mixedValidInvalidDataProvider
     * @param string $value
     * @param string $description
     */
    public function testMixedValidAndInvalidValues(string $value, string $description): void
    {
        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('is not valid because of item');
        
        $this->model->setValue($value);
        $this->model->setPath('system/full_page_cache/caching_application/access_list');
        $this->model->setField('access_list');
        
        $this->model->beforeSave();
    }

    /**
     * Data provider for mixed valid and invalid values
     *
     * @return array
     */
    public static function mixedValidInvalidDataProvider(): array
    {
        return [
            ['127.0.0.1, invalid@#$, localhost', 'Valid IPs with invalid middle'],
            ['192.168.1.0/24, 10.0.0.0/33', 'Valid CIDR with invalid CIDR'],
            ['example.com, <script>, localhost', 'Valid hosts with XSS attempt'],
            ['::1, invalid!value, 127.0.0.1', 'Valid with invalid characters'],
        ];
    }

    /**
     * Test CIDR boundary values
     *
     * Tests edge cases for CIDR notation boundaries
     */
    public function testCidrBoundaryValues(): void
    {
        $validBoundaries = [
            '192.168.1.0/0',   // Minimum CIDR
            '192.168.1.0/1',
            '192.168.1.0/31',
            '192.168.1.0/32',  // Maximum CIDR for IPv4
        ];

        foreach ($validBoundaries as $value) {
            $this->model->setValue($value);
            $this->model->setPath('system/full_page_cache/caching_application/access_list');
            $this->model->setField('access_list');
            
            $result = $this->model->beforeSave();
            $this->assertInstanceOf(AccessList::class, $result, "Failed for CIDR: {$value}");
        }
    }

    /**
     * Test that model can be saved successfully with valid value
     *
     * This test verifies the full save process works with the database
     */
    public function testModelCanBeSaved(): void
    {
        $value = '192.168.1.0/24, localhost, ::1';
        
        $this->model->setValue($value);
        $this->model->setPath('system/full_page_cache/caching_application/access_list_test');
        $this->model->setField('access_list');
        $this->model->setScope('default');
        $this->model->setScopeId(0);
        
        // Save the model
        $this->model->save();
        
        // Verify it was saved
        $this->assertNotNull($this->model->getId());
        $this->assertEquals($value, $this->model->getValue());
    }

    /**
     * Test empty string value behavior
     *
     * Empty values should fall back to default value from parent class
     */
    public function testEmptyValueUsesDefault(): void
    {
        $this->model->setValue('');
        $this->model->setPath('system/full_page_cache/caching_application/access_list');
        $this->model->setField('access_list');
        
        $result = $this->model->beforeSave();
        
        $this->assertInstanceOf(AccessList::class, $result);
        // Value should be set to default (handled by parent Varnish class)
        $this->assertNotEmpty($this->model->getValue());
    }
}
