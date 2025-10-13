<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Magento\Framework\Cache\Frontend\Adapter;

use Magento\Framework\Cache\FrontendInterface;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\Cache\Adapter\TagAwareAdapterInterface;

/**
 * Symfony Cache adapter for Magento cache frontend interface
 *
 * Provides backward-compatible wrapper around Symfony Cache (PSR-6)
 * with support for:
 * - Tag-based cache invalidation
 * - Process fork detection
 * - Full FrontendInterface compatibility
 *
 * Performance optimizations:
 * - Cached identifier cleaning
 * - Optimized regex operations
 * - Reduced instanceof checks
 * - Minimal PID checking overhead
 */
class Symfony implements FrontendInterface
{
    /**
     * @var CacheItemPoolInterface
     */
    private CacheItemPoolInterface $cache;

    /**
     * Factory that creates the cache pool (for fork detection)
     *
     * @var \Closure|null
     */
    private ?\Closure $cacheFactory;

    /**
     * The PID that owns the cache pool
     *
     * @var int
     */
    private int $pid;

    /**
     * We need to keep references to parent's cache pools so that they don't get destroyed
     *
     * @var array
     */
    private array $parentCachePools = [];

    /**
     * Cache for cleaned identifiers (performance optimization)
     *
     * @var array
     */
    private array $cleanedIdentifiers = [];

    /**
     * Whether the cache supports tag-aware operations (cached to avoid repeated instanceof checks)
     *
     * @var bool|null
     */
    private ?bool $isTagAware = null;

    /**
     * Frontend identifier for cache isolation
     * Mimics Zend's cache_id_prefix - used to prefix all tags
     *
     * @var string
     */
    private string $frontendIdentifier;

    /**
     * Constructor
     *
     * @param CacheItemPoolInterface $cache
     * @param \Closure|null $cacheFactory Factory to recreate cache pool after fork
     * @param string|null $frontendIdentifier Unique identifier for this frontend (for cache isolation)
     */
    public function __construct(
        CacheItemPoolInterface $cache,
        ?\Closure $cacheFactory = null,
        ?string $frontendIdentifier = null
    ) {
        $this->cache = $cache;
        $this->cacheFactory = $cacheFactory;
        $this->pid = getmypid();
        $this->frontendIdentifier = $frontendIdentifier ?: 'frontend_' . spl_object_hash($this);
    }

    /**
     * Get cache pool, recreating if process has forked
     *
     * Optimized to minimize getmypid() calls and fork detection overhead
     * Public to allow backend wrapper access for fork detection
     *
     * @return CacheItemPoolInterface
     */
    public function getCache(): CacheItemPoolInterface
    {
        // Only check for fork if we have a factory (optimization)
        if ($this->cacheFactory === null) {
            return $this->cache;
        }

        $currentPid = getmypid();
        if ($currentPid !== $this->pid) {
            // Fork detected - save parent's cache pool and create new one
            $this->parentCachePools[$this->pid] = $this->cache;
            $this->cache = ($this->cacheFactory)();
            $this->pid = $currentPid;

            // Reset caches after fork
            $this->cleanedIdentifiers = [];
            $this->isTagAware = null;
        }
        return $this->cache;
    }

    /**
     * Clean identifier from reserved characters
     *
     * PSR-6 reserved characters: {}()/\@:
     *
     * Performance optimizations:
     * - Cached results for repeated identifiers
     * - Single optimized regex operation
     * - Early return for already-clean identifiers
     *
     * @param string $identifier
     * @return string
     */
    public function cleanIdentifier(string $identifier): string
    {
        // Return cached result if available (major performance improvement)
        if (isset($this->cleanedIdentifiers[$identifier])) {
            return $this->cleanedIdentifiers[$identifier];
        }

        // Store original for caching
        $original = $identifier;

        // Optimize: replace dots first (faster than regex for single char)
        $identifier = str_replace('.', '__', $identifier);

        // Single optimized regex: replace any non-alphanumeric/underscore with underscore
        // This handles both Zend normalization and PSR-6 reserved characters
        $cleaned = preg_replace('/[^a-zA-Z0-9_]/', '_', $identifier);

        // Cache the result (limit cache size to prevent memory issues)
        if (count($this->cleanedIdentifiers) < 1000) {
            $this->cleanedIdentifiers[$original] = $cleaned;
        }

        return $cleaned;
    }

    /**
     * Check if cache pool supports tag-aware operations (cached for performance)
     *
     * @return bool
     */
    private function isTagAware(): bool
    {
        if ($this->isTagAware === null) {
            $this->isTagAware = $this->cache instanceof TagAwareAdapterInterface;
        }
        return $this->isTagAware;
    }

    /**
     * @inheritdoc
     */
    public function test($identifier)
    {
        $cache = $this->getCache();
        $item = $cache->getItem($this->cleanIdentifier($identifier));

        if (!$item->isHit()) {
            return false;
        }

        // Try to get modification time from metadata
        if (method_exists($item, 'getMetadata')) {
            $metadata = $item->getMetadata();
            return $metadata['mtime'] ?? time();
        }

        return time(); // Fallback: return current time if cache hit
    }

    /**
     * @inheritdoc
     */
    public function load($identifier)
    {
        $item = $this->getCache()->getItem($this->cleanIdentifier($identifier));
        return $item->isHit() ? $item->get() : false;
    }

    /**
     * @inheritdoc
     * 
     * Mimics Zend Cache Core::save() behavior:
     * - Prefixes ALL tags with frontendIdentifier (like Zend's cache_id_prefix)
     * - No special tag name checking
     */
    public function save($data, $identifier, array $tags = [], $lifeTime = null)
    {
        $cache = $this->getCache();
        $cleanedId = $this->cleanIdentifier($identifier);
        $item = $cache->getItem($cleanedId);
        $item->set($data);

        // Set expiration time (cast to int if needed)
        if ($lifeTime !== null && $lifeTime !== false) {
            $item->expiresAfter((int)$lifeTime);
        }

        // Handle tags if cache supports it
        if ($this->isTagAware() && !empty($tags)) {
            // Prefix ALL tags with frontendIdentifier (exactly like Zend's _tags())
            // In Zend: tags get cache_id_prefix: 'TAG' -> '69d_TAG'
            // In Symfony: tags get frontendIdentifier: 'TAG' -> 'prefix_TAG'
            $cleanTags = [];
            foreach ($tags as $tag) {
                $cleanedTag = $this->cleanIdentifier($tag);
                $cleanTags[] = $this->frontendIdentifier . '_' . $cleanedTag;
            }
            
            $item->tag($cleanTags);
        }

        return $cache->save($item);
    }

    /**
     * @inheritdoc
     */
    public function remove($identifier)
    {
        return $this->getCache()->deleteItem($this->cleanIdentifier($identifier));
    }

    /**
     * @inheritdoc
     * 
     * Mimics Zend Cache Core::clean() behavior:
     * - Prefixes ALL tags with frontendIdentifier (like Zend's cache_id_prefix)
     * - No special tag name checking
     * - MODE_ALL clears everything (no isolation like Zend)
     */
    public function clean($mode = \Zend_Cache::CLEANING_MODE_ALL, array $tags = [])
    {
        $cache = $this->getCache();
        $isTagAware = $this->isTagAware();
        
        switch ($mode) {
            case \Zend_Cache::CLEANING_MODE_ALL:
            case 'all':
                // Clear all cache (exactly like Zend)
                return $cache->clear();

            case \Zend_Cache::CLEANING_MODE_MATCHING_TAG:
            case 'matchingTag':
                // Early return if no tags
                if (empty($tags)) {
                    return true;
                }

                // Prefix ALL tags with frontendIdentifier (exactly like Zend's _tags())
                // In Zend: clean(['TAG']) becomes clean(['69d_TAG'])
                // In Symfony: clean(['TAG']) becomes clean(['prefix_TAG'])
                if ($isTagAware) {
                    $cleanTags = [];
                    foreach ($tags as $tag) {
                        $cleanedTag = $this->cleanIdentifier($tag);
                        $cleanTags[] = $this->frontendIdentifier . '_' . $cleanedTag;
                    }
                    return $cache->invalidateTags($cleanTags);
                }
                return true;

            case \Zend_Cache::CLEANING_MODE_MATCHING_ANY_TAG:
            case 'matchingAnyTag':
                // Early return if no tags
                if (empty($tags)) {
                    return true;
                }

                // Prefix ALL tags with frontendIdentifier (exactly like Zend's _tags())
                if ($isTagAware) {
                    $cleanTags = [];
                    foreach ($tags as $tag) {
                        $cleanedTag = $this->cleanIdentifier($tag);
                        $cleanTags[] = $this->frontendIdentifier . '_' . $cleanedTag;
                    }
                    return $cache->invalidateTags($cleanTags);
                }
                return true;

            case \Zend_Cache::CLEANING_MODE_OLD:
            case 'old':
                // Symfony Cache handles this automatically via expiration
                return true;

            case \Zend_Cache::CLEANING_MODE_NOT_MATCHING_TAG:
            case 'notMatchingTag':
                // Not efficiently supported by PSR-6/Symfony
                return true;

            default:
                return false;
        }
    }

    /**
     * Get backend that bypasses frontend logic (mimics Zend's getBackend())
     * 
     * In Zend Cache, getBackend() returns the backend which bypasses:
     * - Frontend decorators (TagScope, Logger, etc.)
     * - cache_id_prefix on tags
     * 
     * This wrapper provides the same behavior for Symfony Cache.
     * 
     * @return object
     */
    public function getBackend()
    {
        $adapter = $this;
        
        return new class($adapter) {
            private $adapter;
            
            public function __construct($adapter)
            {
                $this->adapter = $adapter;
            }
            
            /**
             * Get current cache instance (handles fork detection)
             */
            private function getCache()
            {
                return $this->adapter->getCache();
            }
            
            /**
             * Save without prefixing tags (backend-level save)
             */
            public function save($data, $id, $tags = [], $specificLifetime = null)
            {
                $cache = $this->getCache();
                $cleanedId = $this->adapter->cleanIdentifier($id);
                $item = $cache->getItem($cleanedId);
                $item->set($data);
                
                if ($specificLifetime !== null && $specificLifetime !== false) {
                    $item->expiresAfter((int)$specificLifetime);
                }
                
                // NO prefix on tags (backend bypass)
                if ($cache instanceof \Symfony\Component\Cache\Adapter\TagAwareAdapterInterface && !empty($tags)) {
                    $cleanTags = [];
                    foreach ($tags as $tag) {
                        $cleanTags[] = $this->adapter->cleanIdentifier($tag);
                    }
                    $item->tag($cleanTags);
                }
                
                return $cache->save($item);
            }
            
            /**
             * Load directly by ID
             */
            public function load($id)
            {
                return $this->adapter->load($id);
            }
            
            /**
             * Clean without prefixing tags (backend-level clean)
             */
            public function clean($mode = \Zend_Cache::CLEANING_MODE_ALL, array $tags = [])
            {
                $cache = $this->getCache();
                
                switch ($mode) {
                    case \Zend_Cache::CLEANING_MODE_ALL:
                    case 'all':
                        return $cache->clear();
                        
                    case \Zend_Cache::CLEANING_MODE_OLD:
                    case 'old':
                        return true;
                        
                    case \Zend_Cache::CLEANING_MODE_MATCHING_TAG:
                    case 'matchingTag':
                        if (empty($tags) || !($cache instanceof \Symfony\Component\Cache\Adapter\TagAwareAdapterInterface)) {
                            return true;
                        }
                        // NO prefix on tags (backend bypass)
                        $cleanTags = [];
                        foreach ($tags as $tag) {
                            $cleanTags[] = $this->adapter->cleanIdentifier($tag);
                        }
                        return $cache->invalidateTags($cleanTags);
                        
                    case \Zend_Cache::CLEANING_MODE_NOT_MATCHING_TAG:
                    case 'notMatchingTag':
                    case \Zend_Cache::CLEANING_MODE_MATCHING_ANY_TAG:
                    case 'matchingAnyTag':
                        return true;
                        
                    default:
                        return false;
                }
            }
            
            /**
             * Clear entire backend
             */
            public function clear()
            {
                return $this->getCache()->clear();
            }
            
            /**
             * Forward other method calls
             */
            public function __call($method, $args)
            {
                return call_user_func_array([$this->getCache(), $method], $args);
            }
        };
    }

    /**
     * @inheritdoc
     */
    public function getLowLevelFrontend()
    {
        return $this->getCache();
    }
}

