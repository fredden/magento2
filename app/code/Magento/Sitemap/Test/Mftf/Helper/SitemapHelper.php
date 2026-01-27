<?php
/**
 * Copyright 2026 Adobe
 * All Rights Reserved.
 */
declare(strict_types=1);

namespace Magento\Sitemap\Test\Mftf\Helper;

use Magento\FunctionalTestingFramework\Helper\Helper;

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
        $session = curl_init($url);
        curl_setopt($session, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($session, CURLOPT_FOLLOWLOCATION, false);
        curl_setopt($session, CURLOPT_NOBODY, true); // HEAD request

        curl_exec($session);
        $httpCode = (int) curl_getinfo($session, CURLINFO_HTTP_CODE);
        curl_close($session);

        return $httpCode;
    }

    /**
     * Check if URL returns an image content type
     *
     * @param string $url
     * @return bool
     */
    public function isImageContentType(string $url): bool
    {
        $session = curl_init($url);
        curl_setopt($session, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($session, CURLOPT_FOLLOWLOCATION, false);
        curl_setopt($session, CURLOPT_NOBODY, true); // HEAD request

        curl_exec($session);
        $contentType = curl_getinfo($session, CURLINFO_CONTENT_TYPE);
        curl_close($session);

        return str_contains(strtolower($contentType ?: ''), 'image/');
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
        $session = curl_init($url);
        curl_setopt($session, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($session, CURLOPT_FOLLOWLOCATION, false);

        $responseBody = curl_exec($session);
        curl_close($session);

        return str_contains($responseBody ?: '', $searchText);
    }
}
