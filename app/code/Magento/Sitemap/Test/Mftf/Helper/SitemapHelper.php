<?php
/**
 * Copyright 2026 Adobe
 * All Rights Reserved.
 */
declare(strict_types=1);

namespace Magento\Sitemap\Test\Mftf\Helper;

use Magento\FunctionalTestingFramework\Helper\Helper;
use Magento\Framework\HTTP\Client\Curl;

/**
 * Helper class for sitemap HTTP assertions in MFTF tests
 */
class SitemapHelper extends Helper
{

    /**
     * Check HTTP status code for a given URL
     *
     * @param string $url
     * @return int
     */
    public function getHttpStatusCode(string $url): int
    {
        $curl = new Curl();
        $curl->setOption(CURLOPT_RETURNTRANSFER, true);
        $curl->setOption(CURLOPT_FOLLOWLOCATION, false);
        $curl->setOption(CURLOPT_NOBODY, true); // HEAD request
        $curl->get($url);

        return (int) $curl->getStatus();
    }

    /**
     * Check if URL returns an image content type
     *
     * @param string $url
     * @return bool
     */
    public function isImageContentType(string $url): bool
    {
        $curl = new Curl();
        $curl->setOption(CURLOPT_RETURNTRANSFER, true);
        $curl->setOption(CURLOPT_FOLLOWLOCATION, false);
        $curl->setOption(CURLOPT_NOBODY, true); // HEAD request
        $curl->get($url);

        $headers = $curl->getHeaders();
        $contentType = $headers['content-type'] ?? $headers['Content-Type'] ?? '';

        return str_contains(strtolower($contentType), 'image/');
    }

    /**
     * Check if response body contains specific text
     *
     * @param string $url
     * @param string $searchText
     * @return bool
     */
    public function responseContains(string $url, string $searchText): bool
    {
        $curl = new Curl();
        $curl->setOption(CURLOPT_RETURNTRANSFER, true);
        $curl->setOption(CURLOPT_FOLLOWLOCATION, false);
        $curl->get($url);

        $body = $curl->getBody();

        return str_contains($body, $searchText);
    }
}
