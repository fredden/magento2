<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Magento\Framework\Cache\Frontend\Adapter\Symfony;

use Magento\Framework\Cache\Frontend\Adapter\Helper\AdapterHelperInterface;
use Psr\Cache\CacheItemPoolInterface;
use Magento\Framework\Cache\FrontendInterface;

/**
 * Low-level frontend wrapper for Symfony cache adapter
 * 
 * Provides Zend_Cache_Core compatible interface for backward compatibility
 * Used by legacy code that needs direct access to cache internals
 */
class LowLevelFrontend
{
    /**
     * @var CacheItemPoolInterface
     */
    private CacheItemPoolInterface $cache;

    /**
     * @var FrontendInterface
     */
    private FrontendInterface $symfony;

    /**
     * @var AdapterHelperInterface
     */
    private AdapterHelperInterface $helper;

    /**
     * @var string
     */
    private string $idPrefix;

    /**
     * @var LowLevelBackend|null
     */
    private ?LowLevelBackend $backend = null;

    /**
     * @param CacheItemPoolInterface $cache
     * @param FrontendInterface $symfony
     * @param AdapterHelperInterface $helper
     * @param string $idPrefix
     */
    public function __construct(
        CacheItemPoolInterface $cache,
        FrontendInterface $symfony,
        AdapterHelperInterface $helper,
        string $idPrefix
    ) {
        $this->cache = $cache;
        $this->symfony = $symfony;
        $this->helper = $helper;
        $this->idPrefix = $idPrefix;
    }

    /**
     * Get metadata for cache entry
     *
     * @param string $id
     * @return array|false
     */
    public function getMetadatas($id)
    {
        return $this->symfony->getMetadatas($id);
    }

    /**
     * Get cache option
     *
     * @param string $name
     * @return mixed
     */
    public function getOption(string $name)
    {
        if ($name === 'cache_id_prefix') {
            return $this->idPrefix;
        }
        return null;
    }

    /**
     * Get IDs matching tags
     *
     * @param array $tags
     * @return array
     */
    public function getIdsMatchingTags(array $tags): array
    {
        // Get IDs from helper (uses backend-specific logic)
        if (method_exists($this->helper, 'getIdsMatchingTags')) {
            // Tags are already in the correct format from the caller
            // Helper will add namespace prefix internally
            return $this->helper->getIdsMatchingTags($tags);
        }
        
        // For GenericAdapterHelper, return empty array
        // (it doesn't support native ID lookup by tags)
        return [];
    }

    /**
     * Get backend wrapper
     *
     * @return LowLevelBackend
     */
    public function getBackend(): LowLevelBackend
    {
        if ($this->backend === null) {
            $this->backend = new LowLevelBackend($this->helper);
        }
        return $this->backend;
    }

    /**
     * Delegate all other method calls to the cache
     *
     * @param string $method
     * @param array $arguments
     * @return mixed
     */
    public function __call(string $method, array $arguments)
    {
        return $this->cache->$method(...$arguments);
    }
}

