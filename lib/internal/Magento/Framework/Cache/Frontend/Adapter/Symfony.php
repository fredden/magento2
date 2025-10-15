<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Magento\Framework\Cache\Frontend\Adapter;

use Magento\Framework\Cache\Frontend\Adapter\Helper\AdapterHelperInterface;
use Magento\Framework\Cache\Frontend\Adapter\Helper\GenericAdapterHelper;
use Magento\Framework\Cache\FrontendInterface;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\Cache\Adapter\TagAwareAdapterInterface;

/**
 * Symfony Cache adapter for Magento - FRESH IMPLEMENTATION
 * 
 * This is a complete rewrite that uses AdapterHelper classes for backend-specific operations.
 * 
 * Supported cleaning modes:
 * - CLEANING_MODE_ALL: Clear all cache (native Symfony)
 * - CLEANING_MODE_OLD: Remove expired items (native Symfony)
 * - CLEANING_MODE_MATCHING_TAG: AND logic (uses helper classes)
 * - CLEANING_MODE_NOT_MATCHING_TAG: Inverse logic (uses helper classes)
 * - CLEANING_MODE_MATCHING_ANY_TAG: OR logic (native Symfony)
 * 
 * Architecture:
 * - RedisAdapterHelper: Uses Redis SINTER for true AND logic
 * - FilesystemAdapterHelper: Uses file indices with array_intersect for AND logic
 * - GenericAdapterHelper: Fallback using namespace tags for other adapters
 */
class Symfony implements FrontendInterface
{
    private CacheItemPoolInterface $cache;
    private AdapterHelperInterface $helper;
    private ?\Closure $cacheFactory;
    private int $pid;
    private array $parentCachePools = [];
    private ?bool $isTagAware = null;

    /**
     * @param \Closure $cacheFactory Factory that creates the cache pool
     * @param AdapterHelperInterface|null $helper Backend-specific helper
     */
    public function __construct(\Closure $cacheFactory, ?AdapterHelperInterface $helper = null)
    {
        $this->cacheFactory = $cacheFactory;
        $this->pid = getmypid();
        $this->cache = $cacheFactory();
        
        // Use provided helper or create generic fallback
        $this->helper = $helper ?? new GenericAdapterHelper($this->cache);
    }

    /**
     * Get cache pool (with fork detection)
     * 
     * @return CacheItemPoolInterface
     */
    private function getCache(): CacheItemPoolInterface
    {
        $currentPid = getmypid();
        
        if ($currentPid !== $this->pid) {
            // Fork detected - create new cache pool
            $this->parentCachePools[] = $this->cache; // Prevent destruction
            $this->cache = ($this->cacheFactory)();
            $this->pid = $currentPid;
            $this->isTagAware = null; // Reset cache
        }
        
        return $this->cache;
    }

    /**
     * Check if cache supports tag-aware operations
     * 
     * @return bool
     */
    private function isTagAware(): bool
    {
        if ($this->isTagAware === null) {
            $this->isTagAware = $this->getCache() instanceof TagAwareAdapterInterface;
        }
        return $this->isTagAware;
    }

    /**
     * Clean cache identifier (remove invalid characters)
     * 
     * @param string|null $identifier
     * @return string|null
     */
    private function cleanIdentifier(?string $identifier): ?string
    {
        if ($identifier === null) {
            return null;
        }
        
        // Uppercase to match Zend's _unifyId behavior
        $identifier = strtoupper($identifier);
        
        // Replace periods and invalid characters
        $cleaned = str_replace('.', '__', $identifier);
        return preg_replace('/[^a-zA-Z0-9_]/', '_', $cleaned);
    }

    /**
     * Clean multiple identifiers
     * 
     * @param array $identifiers
     * @return array
     */
    private function cleanIdentifiers(array $identifiers): array
    {
        return array_map([$this, 'cleanIdentifier'], $identifiers);
    }

    /**
     * {@inheritdoc}
     */
    public function test($identifier)
    {
        $cache = $this->getCache();
        $item = $cache->getItem($this->cleanIdentifier($identifier));
        
        return $item->isHit() ? (int)time() : false;
    }

    /**
     * {@inheritdoc}
     */
    public function load($identifier)
    {
        $cache = $this->getCache();
        $item = $cache->getItem($this->cleanIdentifier($identifier));
        
        return $item->isHit() ? $item->get() : false;
    }

    /**
     * {@inheritdoc}
     */
    public function save($data, $identifier, array $tags = [], $lifeTime = null)
    {
        $cache = $this->getCache();
        $cleanId = $this->cleanIdentifier($identifier);
        $item = $cache->getItem($cleanId);
        
        $item->set($data);
        
        // Set expiration
        if ($lifeTime !== null && $lifeTime !== false) {
            $item->expiresAfter((int)$lifeTime);
        }
        
        // Handle tags
        if ($this->isTagAware() && !empty($tags)) {
            $cleanTags = $this->cleanIdentifiers($tags);
            
            // For GenericHelper, get enhanced tags (including namespace tags if applicable)
            if ($this->helper instanceof GenericAdapterHelper) {
                $cleanTags = $this->helper->getTagsForSave($cleanTags);
            }
            
            $item->tag($cleanTags);
        }
        
        $success = $cache->save($item);
        
        // Ensure immediate persistence (commit any deferred saves)
        if ($success && method_exists($cache, 'commit')) {
            $cache->commit();
        }
        
        // Notify helper about the save (for Redis/Filesystem to maintain indices)
        if ($success && !empty($tags)) {
            $cleanTags = $this->cleanIdentifiers($tags);
            $this->helper->onSave($cleanId, $cleanTags);
            
            // For Redis, also store reverse index
            if (method_exists($this->helper, 'storeReverseIndex')) {
                $this->helper->storeReverseIndex($cleanId, $cleanTags);
            }
        }
        
        return $success;
    }

    /**
     * {@inheritdoc}
     */
    public function remove($identifier)
    {
        $cache = $this->getCache();
        $cleanId = $this->cleanIdentifier($identifier);
        
        // Notify helper before removal (for index cleanup)
        $this->helper->onRemove($cleanId);
        
        return $cache->deleteItem($cleanId);
    }

    /**
     * {@inheritdoc}
     */
    public function clean($mode = \Zend_Cache::CLEANING_MODE_ALL, array $tags = [])
    {
        // Validate cleaning mode
        $validModes = [
            \Zend_Cache::CLEANING_MODE_ALL,
            \Zend_Cache::CLEANING_MODE_OLD,
            \Zend_Cache::CLEANING_MODE_MATCHING_TAG,
            \Zend_Cache::CLEANING_MODE_NOT_MATCHING_TAG,
            \Zend_Cache::CLEANING_MODE_MATCHING_ANY_TAG
        ];
        
        if (!in_array($mode, $validModes, true)) {
            throw new \InvalidArgumentException(
                "Invalid cleaning mode '{$mode}'. Supported modes: ALL, OLD, MATCHING_TAG, NOT_MATCHING_TAG, MATCHING_ANY_TAG"
            );
        }
        
        $cache = $this->getCache();
        
        return match ($mode) {
            \Zend_Cache::CLEANING_MODE_ALL, 'all' => $this->cleanAll($cache),
            \Zend_Cache::CLEANING_MODE_OLD, 'old' => $this->cleanOld($cache),
            \Zend_Cache::CLEANING_MODE_MATCHING_TAG, 'matchingTag' => $this->cleanMatchingTag($cache, $tags),
            \Zend_Cache::CLEANING_MODE_NOT_MATCHING_TAG, 'notMatchingTag' => $this->cleanNotMatchingTag($cache, $tags),
            \Zend_Cache::CLEANING_MODE_MATCHING_ANY_TAG, 'matchingAnyTag' => $this->cleanMatchingAnyTag($cache, $tags),
            default => throw new \InvalidArgumentException("Unsupported cleaning mode: {$mode}")
        };
    }

    /**
     * Clean all cache entries
     * 
     * @param CacheItemPoolInterface $cache
     * @return bool
     */
    private function cleanAll(CacheItemPoolInterface $cache): bool
    {
        $this->helper->clearAllIndices();
        return $cache->clear();
    }

    /**
     * Clean old/expired cache entries
     * 
     * @param CacheItemPoolInterface $cache
     * @return bool
     */
    private function cleanOld(CacheItemPoolInterface $cache): bool
    {
        // Symfony handles expiration automatically
        // This is a no-op as expired items are not returned
        return true;
    }

    /**
     * Clean entries matching ALL given tags (AND logic)
     * 
     * @param CacheItemPoolInterface $cache
     * @param array $tags
     * @return bool
     */
    private function cleanMatchingTag(CacheItemPoolInterface $cache, array $tags): bool
    {
        if (empty($tags)) {
            return true;
        }
        
        $cleanTags = $this->cleanIdentifiers($tags);
        
        // For GenericHelper with namespace tags, use the namespace tag
        if ($this->helper instanceof GenericAdapterHelper) {
            if ($this->helper->usesNamespaceTags()) {
                $tagsToInvalidate = $this->helper->getTagsForMatchingTag($cleanTags);
                return $this->isTagAware() && $cache->invalidateTags($tagsToInvalidate);
            } else {
                // For non-FPC generic adapters, use OR logic (best we can do)
                return $this->isTagAware() && $cache->invalidateTags($cleanTags);
            }
        }
        
        // For Redis/Filesystem helpers with native AND support
        $ids = $this->helper->getIdsMatchingTags($cleanTags);
        
        if (empty($ids)) {
            return true;
        }
        
        return $this->helper->deleteByIds($ids);
    }

    /**
     * Clean entries NOT matching any of the given tags
     * 
     * @param CacheItemPoolInterface $cache
     * @param array $tags
     * @return bool
     */
    private function cleanNotMatchingTag(CacheItemPoolInterface $cache, array $tags): bool
    {
        if (empty($tags)) {
            // No tags means clean all
            return $this->cleanAll($cache);
        }
        
        $cleanTags = $this->cleanIdentifiers($tags);
        $ids = $this->helper->getIdsNotMatchingTags($cleanTags);
        
        if (empty($ids)) {
            return true;
        }
        
        return $this->helper->deleteByIds($ids);
    }

    /**
     * Get cache entry metadata (Zend compatibility)
     * 
     * @param string $id
     * @return array|false
     */
    public function getMetadatas($id)
    {
        $cache = $this->getCache();
        $cleanId = $this->cleanIdentifier($id);
        
        $item = $cache->getItem($cleanId);
        
        if (!$item->isHit()) {
            return false;
        }
        
        // Get metadata from the item
        $metadata = $item->getMetadata();
        
        // Calculate expiry timestamp
        $expiry = null;
        if (isset($metadata[\Symfony\Component\Cache\CacheItem::METADATA_EXPIRY])) {
            $expiry = (int) $metadata[\Symfony\Component\Cache\CacheItem::METADATA_EXPIRY];
        }
        
        // Get tags from metadata  
        $tags = [];
        if (isset($metadata[\Symfony\Component\Cache\CacheItem::METADATA_TAGS])) {
            $rawTags = $metadata[\Symfony\Component\Cache\CacheItem::METADATA_TAGS];
            // Add the cache ID prefix to tags (to match Zend behavior)
            $prefix = '69d_';
            $tags = array_map(function($tag) use ($prefix) {
                return $prefix . $tag;
            }, $rawTags);
        }
        
        // Get ctime (creation time) as mtime
        $mtime = null;
        if (isset($metadata[\Symfony\Component\Cache\CacheItem::METADATA_CTIME])) {
            $mtime = (int) $metadata[\Symfony\Component\Cache\CacheItem::METADATA_CTIME];
        }
        
        return [
            'expire' => $expiry ?: (time() + 86400), // Default to 24 hours if not set
            'tags' => is_array($tags) ? $tags : [],
            'mtime' => $mtime ?: time(),
        ];
    }

    /**
     * Clean entries matching ANY of the given tags (OR logic)
     * 
     * @param CacheItemPoolInterface $cache
     * @param array $tags
     * @return bool
     */
    private function cleanMatchingAnyTag(CacheItemPoolInterface $cache, array $tags): bool
    {
        if (empty($tags)) {
            return true;
        }
        
        $cleanTags = $this->cleanIdentifiers($tags);
        
        // Try Symfony's native invalidateTags first (OR logic)
        if ($this->isTagAware()) {
            return $cache->invalidateTags($cleanTags);
        }
        
        // Fallback: use helper
        $ids = $this->helper->getIdsMatchingAnyTags($cleanTags);
        
        if (empty($ids)) {
            return true;
        }
        
        return $this->helper->deleteByIds($ids);
    }

    /**
     * {@inheritdoc}
     */
    public function getBackend()
    {
        $symfony = $this;
        
        // Return backend wrapper for backward compatibility
        return new class($this->getCache(), $this->helper, $symfony) {
            private CacheItemPoolInterface $cache;
            private AdapterHelperInterface $helper;
            private FrontendInterface $symfony;

            public function __construct(CacheItemPoolInterface $cache, AdapterHelperInterface $helper, FrontendInterface $symfony)
            {
                $this->cache = $cache;
                $this->helper = $helper;
                $this->symfony = $symfony;
            }

            public function save($data, $id, array $tags = [], $specificLifetime = false)
            {
                // Delegate to frontend for full save logic
                return $this->symfony->save($data, $id, $tags, $specificLifetime);
            }

            public function load($id)
            {
                // Delegate to frontend
                return $this->symfony->load($id);
            }

            public function remove($id)
            {
                // Delegate to frontend
                return $this->symfony->remove($id);
            }

            public function clean($mode = \Zend_Cache::CLEANING_MODE_ALL, array $tags = [])
            {
                return match ($mode) {
                    \Zend_Cache::CLEANING_MODE_ALL, 'all' => $this->clear(),
                    \Zend_Cache::CLEANING_MODE_OLD, 'old' => true,
                    default => throw new \InvalidArgumentException("Backend clean only supports ALL and OLD modes")
                };
            }

            public function clear()
            {
                $this->helper->clearAllIndices();
                return $this->cache->clear();
            }
        };
    }

    /**
     * {@inheritdoc}
     */
    public function getLowLevelFrontend()
    {
        $cache = $this->getCache();
        $symfony = $this;
        $idPrefix = '69d_'; // Match the ID prefix used in Factory
        
        // Return a wrapper that adds Zend-compatible methods for backward compatibility
        return new class($cache, $symfony, $idPrefix) {
            private $cache;
            private $symfony;
            private $idPrefix;
            
            public function __construct($cache, $symfony, $idPrefix)
            {
                $this->cache = $cache;
                $this->symfony = $symfony;
                $this->idPrefix = $idPrefix;
            }
            
            public function getMetadatas($id)
            {
                return $this->symfony->getMetadatas($id);
            }
            
            public function getOption($name)
            {
                if ($name === 'cache_id_prefix') {
                    return $this->idPrefix;
                }
                return null;
            }
            
            // Delegate all other methods to the cache
            public function __call($method, $arguments)
            {
                return $this->cache->$method(...$arguments);
            }
        };
    }

    /**
     * {@inheritdoc}
     */
    public function getFrontend()
    {
        return $this;
    }
}
