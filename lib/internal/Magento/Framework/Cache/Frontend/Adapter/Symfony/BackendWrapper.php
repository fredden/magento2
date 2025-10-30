<?php
/**
 * Copyright 2025 Adobe
 * All Rights Reserved.
 */
declare(strict_types=1);

namespace Magento\Framework\Cache\Frontend\Adapter\Symfony;

use InvalidArgumentException;
use Magento\Framework\Cache\CacheConstants;
use Magento\Framework\Cache\Frontend\Adapter\Helper\AdapterHelperInterface;
use Magento\Framework\Cache\FrontendInterface;
use Psr\Cache\CacheItemPoolInterface;

/**
 * Backend wrapper for Symfony cache adapter
 *
 * Provides Zend_Cache_Backend compatible interface for backward compatibility
 */
class BackendWrapper
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
     * Save data to cache
     *
     * @param mixed $data
     * @param string $id
     * @param array $tags
     * @param int|bool $specificLifetime
     * @return bool
     */
    public function save($data, $id, array $tags = [], $specificLifetime = false): bool
    {
        // Delegate to frontend for full save logic
        return $this->symfony->save($data, $id, $tags, $specificLifetime);
    }

    /**
     * Load data from cache
     *
     * @param string $id
     * @return mixed
     */
    public function load($id)
    {
        // Delegate to frontend
        return $this->symfony->load($id);
    }

    /**
     * Remove cache entry
     *
     * @param string $id
     * @return bool
     */
    public function remove($id): bool
    {
        // Delegate to frontend
        return $this->symfony->remove($id);
    }

    /**
     * Clean cache entries
     *
     * @param string $mode
     * @param array $tags
     * @return bool
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function clean($mode = CacheConstants::CLEANING_MODE_ALL, array $tags = []): bool
    {
        return match ($mode) {
            CacheConstants::CLEANING_MODE_ALL, 'all' => $this->clear(),
            CacheConstants::CLEANING_MODE_OLD, 'old' => true,
            default => throw new InvalidArgumentException("Backend clean only supports ALL and OLD modes")
        };
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
