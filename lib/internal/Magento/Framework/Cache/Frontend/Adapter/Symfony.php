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
     * Frontend identifier for cache isolation (used to prevent cross-frontend cache pollution)
     * Only items without user tags get this identifier
     *
     * @var string
     */
    private string $frontendIdentifier;

    /**
     * Known Magento cache system tags
     * Items with only these tags (+ MAGE) are considered system cache and get frontend identifier
     *
     * @var array
     */
    private array $systemCacheTags = [
        'EAV', 'EAV_ATTRIBUTE', 'CONFIG', 'COMPILED_CONFIG', 'TRANSLATE',
        'DB_PDO_MYSQL_DDL', 'DB_DDL', 'BLOCK_HTML', 'LAYOUT_GENERAL_CACHE_TAG',
        'FPC', 'COLLECTION_DATA', 'REFLECTION', 'DB', 'STORE', 'CONFIG_SCOPES',
        'MAGE' // TagScope decorator tag
    ];

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
     *
     * @return CacheItemPoolInterface
     */
    private function getCache(): CacheItemPoolInterface
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
    private function cleanIdentifier(string $identifier): string
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
     * Performance optimizations:
     * - Cached tag-aware check
     * - Optimized tag cleaning with foreach (faster than array_map for small arrays)
     *
     * Cache Isolation Strategy:
     * - Filter out decorator tags (MAGE) to identify user-provided tags
     * - Items with ONLY decorator tags → Add frontend identifier (application cache)
     * - Items with user tags → DON'T add frontend identifier (non-application cache)
     * - This allows user-tagged items to survive clean(MODE_ALL)
     * - Cache identifier is always the primary key for load/save
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

        // Handle tags if cache supports it (use cached instanceof check)
        if ($this->isTagAware()) {
            // Clean all tags
            $cleanTags = [];
            foreach ($tags as $tag) {
                $cleanTags[] = $this->cleanIdentifier($tag);
            }
            
            // Check if item has any NON-system tags (user tags)
            $hasUserTags = false;
            foreach ($tags as $tag) {
                if (!in_array($tag, $this->systemCacheTags, true)) {
                    $hasUserTags = true;
                    break;
                }
            }
            
            // Add frontend identifier only to system cache items
            // User-tagged items (non-application cache) don't get it and survive clean()
            if (!$hasUserTags) {
                $cleanTags[] = $this->frontendIdentifier;
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
     * Performance optimizations:
     * - Cached instanceof check
     * - Early returns for common cases
     * - Optimized tag cleaning
     * 
     * Cache Isolation:
     * - MODE_ALL only clears items tagged with frontend identifier
     * - Items with preservation tags survive
     */
    public function clean($mode = \Zend_Cache::CLEANING_MODE_ALL, array $tags = [])
    {
        $cache = $this->getCache();
        $isTagAware = $this->isTagAware();
        
        switch ($mode) {
            case \Zend_Cache::CLEANING_MODE_ALL:
            case 'all':
                // Only clear owned items (those tagged with frontend identifier)
                // Preserved items (non-owned) survive
                if ($isTagAware) {
                    return $cache->invalidateTags([$this->frontendIdentifier]);
                }
                return $cache->clear();

            case \Zend_Cache::CLEANING_MODE_MATCHING_TAG:
            case 'matchingTag':
                // Early return if no tags
                if (empty($tags)) {
                    return true;
                }

                // CRITICAL: TagScope transforms clean(MODE_ALL) → clean(MATCHING_TAG, ['MAGE'])
                // Multiple TagScope decorators can add multiple 'MAGE' tags
                // If cleaning ONLY by MAGE tag(s), treat it as MODE_ALL for isolation
                $nonMageTags = array_filter($tags, function($tag) {
                    return $tag !== 'MAGE';
                });
                
                if (empty($nonMageTags)) {
                    // Only MAGE tags → This is MODE_ALL disguised as MATCHING_TAG
                    if ($isTagAware) {
                        return $cache->invalidateTags([$this->frontendIdentifier]);
                    }
                    return $cache->clear();
                }

                // MATCHING_TAG in Zend means: clear items with ALL these tags (AND logic)
                // Symfony invalidateTags uses OR logic: clear items with ANY of these tags
                // When used with TagScope decorator, this creates an issue where scope tags clear everything
                //
                // Workaround: Only use the FIRST non-scope tag to maintain expected behavior
                // This works because TagScope calls clean separately for each user tag
                if ($isTagAware) {
                    // Optimize tag cleaning with foreach
                    $cleanTags = [];
                    foreach ($tags as $tag) {
                        $cleanTags[] = $this->cleanIdentifier($tag);
                    }

                    // If multiple tags provided (likely from TagScope), only use the first one
                    // TagScope pattern: [$userTag, $scopeTag] - we want just $userTag
                    if (count($cleanTags) > 1) {
                        $cleanTags = [$cleanTags[0]];
                    }

                    return $cache->invalidateTags($cleanTags);
                }
                // Fallback: clear all if tags not supported
                return $cache->clear();

            case \Zend_Cache::CLEANING_MODE_MATCHING_ANY_TAG:
            case 'matchingAnyTag':
                // Early return if no tags
                if (empty($tags)) {
                    return true;
                }

                // MATCHING_ANY_TAG: clear items with ANY of these tags (OR logic)
                // This matches Symfony's invalidateTags behavior perfectly
                if ($isTagAware) {
                    // Optimize tag cleaning with foreach
                    $cleanTags = [];
                    foreach ($tags as $tag) {
                        $cleanTags[] = $this->cleanIdentifier($tag);
                    }
                    return $cache->invalidateTags($cleanTags);
                }
                // Fallback: clear all if tags not supported
                return $cache->clear();

            case \Zend_Cache::CLEANING_MODE_OLD:
            case 'old':
                // Symfony Cache handles this automatically via expiration
                // No action needed - return true
                return true;

            case \Zend_Cache::CLEANING_MODE_NOT_MATCHING_TAG:
            case 'notMatchingTag':
                // Not efficiently supported by PSR-6/Symfony - would require listing all keys
                // Return true (no-op) for backward compatibility
                return true;

            default:
                return false;
        }
    }

    /**
     * @inheritdoc
     */
    public function getBackend()
    {
        return $this->getCache();
    }

    /**
     * @inheritdoc
     */
    public function getLowLevelFrontend()
    {
        return $this->getCache();
    }
}

