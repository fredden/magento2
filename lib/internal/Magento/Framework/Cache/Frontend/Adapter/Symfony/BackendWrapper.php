<?php
/**
 * Copyright 2025 Adobe
 * All Rights Reserved.
 */
declare(strict_types=1);

namespace Magento\Framework\Cache\Frontend\Adapter\Symfony;

use InvalidArgumentException;
use Magento\Framework\Cache\Backend\BackendInterface;
use Magento\Framework\Cache\CacheConstants;
use Magento\Framework\Cache\Frontend\Adapter\Helper\AdapterHelperInterface;
use Magento\Framework\Cache\FrontendInterface;
use Psr\Cache\CacheItemPoolInterface;

/**
 * Backend wrapper for Symfony cache adapter
 *
 * Provides BackendInterface-compatible wrapper for Symfony PSR-6 cache.
 * Delegates operations to the Symfony frontend for proper tag and metadata handling.
 *
 * @api
 */
class BackendWrapper implements BackendInterface
{
    /**
     * @var CacheItemPoolInterface
     */
    private CacheItemPoolInterface $cache;

    /**
     * @var AdapterHelperInterface
     */
    private AdapterHelperInterface $helper;

    /**
     * @var FrontendInterface
     */
    private FrontendInterface $symfony;

    /**
     * @param CacheItemPoolInterface $cache
     * @param AdapterHelperInterface $helper
     * @param FrontendInterface $symfony
     */
    public function __construct(
        CacheItemPoolInterface $cache,
        AdapterHelperInterface $helper,
        FrontendInterface $symfony
    ) {
        $this->cache = $cache;
        $this->helper = $helper;
        $this->symfony = $symfony;
    }

    /**
     * Test if a cache is available for the given id
     *
     * @param string $id Cache id
     * @return int|false Last modified timestamp if available, false otherwise
     */
    public function test(string $id)
    {
        return $this->symfony->test($id);
    }

    /**
     * Load value with given id from cache
     *
     * @param string $id Cache id
     * @param bool $doNotTestCacheValidity If true, validity not tested
     * @return string|false Cached data or false if not available
     */
    public function load(string $id, bool $doNotTestCacheValidity = false)
    {
        // Delegate to frontend (validity always tested in Symfony)
        return $this->symfony->load($id);
    }

    /**
     * Save some data in cache
     *
     * @param mixed $data Data to cache
     * @param string $id Cache id
     * @param array $tags Array of tags
     * @param int|null $specificLifetime Specific lifetime (null = infinite)
     * @return bool True if no problem
     */
    public function save($data, string $id, array $tags = [], ?int $specificLifetime = null): bool
    {
        // Delegate to frontend for full save logic
        return $this->symfony->save($data, $id, $tags, $specificLifetime);
    }

    /**
     * Remove a cache record
     *
     * @param string $id Cache id
     * @return bool True if no problem
     */
    public function remove(string $id): bool
    {
        // Delegate to frontend
        return $this->symfony->remove($id);
    }

    /**
     * Clean some cache records
     *
     * @param string $mode Clean mode ('all', 'old')
     * @param array $tags Array of tags (unused for backend clean)
     * @return bool True if no problem
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function clean(string $mode = 'all', array $tags = []): bool
    {
        return match ($mode) {
            CacheConstants::CLEANING_MODE_ALL, 'all' => $this->clear(),
            CacheConstants::CLEANING_MODE_OLD, 'old' => true,
            default => throw new InvalidArgumentException("Backend clean only supports ALL and OLD modes")
        };
    }

    /**
     * Set an option
     *
     * @param string $name Option name
     * @param mixed $value Option value
     * @return void
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function setOption(string $name, $value): void
    {
        // For Symfony, backend options are not stored in the wrapper
        // This method exists for BackendInterface compliance but does nothing
    }

    /**
     * Clear all cache entries
     *
     * @return bool
     */
    public function clear(): bool
    {
        $this->helper->clearAllIndices();
        return $this->cache->clear();
    }

    /**
     * Get backend option
     *
     * @param string $name
     * @return mixed
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function getOption(string $name)
    {
        // For Symfony, backend options are not stored in the wrapper
        // This method exists for Zend compatibility but returns null
        return null;
    }
}
