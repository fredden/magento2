<?php
/**
 * Copyright 2025 Adobe
 * All Rights Reserved.
 */
declare(strict_types=1);

namespace Magento\Framework\Cache\Frontend\Adapter;

use Closure;
use InvalidArgumentException;
use Magento\Framework\Cache\CacheConstants;
use Magento\Framework\Cache\Frontend\Adapter\SymfonyAdapters\AdapterInterface;
use Magento\Framework\Cache\Frontend\Adapter\SymfonyAdapters\GenericAdapterService;
use Magento\Framework\Cache\FrontendInterface;
use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\Cache\Adapter\TagAwareAdapterInterface;
use Symfony\Component\Cache\CacheItem;

/**
 * Symfony Cache adapter for Magento - FRESH IMPLEMENTATION
 *
 * This is a complete rewrite that uses Symfony adapter services for backend-specific operations.
 *
 * Supported cleaning modes:
 * - CLEANING_MODE_ALL: Clear all cache (native Symfony)
 * - CLEANING_MODE_OLD: Remove expired items (native Symfony)
 * - CLEANING_MODE_MATCHING_TAG: AND logic (uses adapter services)
 * - CLEANING_MODE_NOT_MATCHING_TAG: Inverse logic (uses adapter services)
 * - CLEANING_MODE_MATCHING_ANY_TAG: OR logic (native Symfony)
 *
 * Architecture:
 * - RedisAdapterService: Uses Redis SINTER for true AND logic
 * - FilesystemAdapterService: Uses file indices with array_intersect for AND logic
 * - GenericAdapterService: Fallback using namespace tags for other adapters
 *
 * @see Symfony\BackendWrapper
 * @see Symfony\LowLevelFrontend
 * @see Symfony\LowLevelBackend
 */
class Symfony implements FrontendInterface
{
    /**
     * Default cache ID prefix (must match Factory configuration)
     */
    public const DEFAULT_CACHE_PREFIX = '69d_';
    
    /**
     * Default cache lifetime in seconds (2 hours)
     */
    public const DEFAULT_LIFETIME = 7200;
    
    /**
     * Fallback expiry for items without explicit lifetime (24 hours)
     */
    public const FALLBACK_EXPIRY = 86400;
    
    /**
     * Assumed lifetime for mtime calculation (2 hours)
     * Used when calculating modification time from expiry timestamp
     */
    public const ASSUMED_LIFETIME = 7200;

    /**
     * @var CacheItemPoolInterface
     */
    private CacheItemPoolInterface $cache;

    /**
     * @var AdapterInterface
     */
    private AdapterInterface $adapter;

    /**
     * @var Closure|null
     */
    private ?Closure $cacheFactory;

    /**
     * @var int
     */
    private int $pid;

    /**
     * @var array
     */
    private array $parentCachePools = [];

    /**
     * @var bool|null
     */
    private ?bool $isTagAware = null;

    /**
     * @var int
     */
    private int $defaultLifetime;

    /**
     * @var string
     */
    private string $idPrefix;

    /**
     * @param Closure $cacheFactory Factory that creates the cache pool
     * @param AdapterInterface|null $adapter Backend-specific adapter service
     * @param int $defaultLifetime Default cache lifetime in seconds
     * @param string $idPrefix Cache ID prefix
     */
    public function __construct(
        Closure $cacheFactory,
        ?AdapterInterface $adapter = null,
        int $defaultLifetime = self::DEFAULT_LIFETIME,
        string $idPrefix = self::DEFAULT_CACHE_PREFIX
    ) {
        $this->cacheFactory = $cacheFactory;
        $this->pid = getmypid();
        $this->cache = $cacheFactory();
        $this->defaultLifetime = $defaultLifetime;
        $this->idPrefix = $idPrefix;
        
        // Use provided adapter or create generic fallback
        $this->adapter = $adapter ?? new GenericAdapterService($this->cache);
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
     * @inheritDoc
     */
    public function test($identifier)
    {
        $cache = $this->getCache();
        $item = $cache->getItem($this->cleanIdentifier($identifier));
        
        if (!$item->isHit()) {
            return false;
        }
        
        $value = $item->get();
        
        // Return stored mtime from wrapper for consistent timestamps
        if (is_array($value) && isset($value['mtime'])) {
            return (int)$value['mtime'];
        }
        
        // OPTIMIZATION: For unwrapped data (fast path), return current time
        // This matches Zend behavior for cache entries without metadata
        return time();
    }

    /**
     * @inheritDoc
     */
    public function load($identifier)
    {
        $cache = $this->getCache();
        $item = $cache->getItem($this->cleanIdentifier($identifier));
        
        if (!$item->isHit()) {
            return false;
        }
        
        $wrappedData = $item->get();
        
        // Unwrap data from metadata wrapper
        // CRITICAL: Use array_key_exists instead of isset for 'data' key
        // because isset returns false when value is null!
        if (is_array($wrappedData) && array_key_exists('data', $wrappedData)) {
            return $wrappedData['data'];
        }
        
        // Fallback for non-wrapped data (shouldn't happen)
        return $wrappedData;
    }

    /**
     * @inheritDoc
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     * @SuppressWarnings(PHPMD.NPathComplexity)
     */
    public function save($data, $identifier, array $tags = [], $lifeTime = null)
    {
        $cache = $this->getCache();
        $cleanId = $this->cleanIdentifier($identifier);
        $item = $cache->getItem($cleanId);
        
        // Calculate actual lifetime to use
        $actualLifetime = $this->calculateActualLifetime($lifeTime);
        
        // Clean tags once for reuse
        $cleanTags = !empty($tags) ? $this->cleanIdentifiers($tags) : [];
        
        // OPTIMIZATION: Conditional metadata wrapping
        $needsMetadata = !empty($cleanTags) || ($actualLifetime !== $this->defaultLifetime);
        
        if ($needsMetadata) {
            $this->prepareItemWithMetadata($item, $data, $cleanTags, $actualLifetime);
        } else {
            // FAST PATH: Store data directly without metadata wrapper
            $item->set($data);
        }
        
        // Set expiration on Symfony item
        if ($actualLifetime !== null) {
            $item->expiresAfter($actualLifetime);
        }
        
        $success = $cache->save($item);
        
        // Commit and notify helpers
        $this->commitAndNotify($cache, $success, $cleanId, $cleanTags);
        
        return $success;
    }

    /**
     * Calculate the actual lifetime to use for cache entry
     *
     * @param mixed $lifeTime
     * @return int|null
     */
    private function calculateActualLifetime($lifeTime): ?int
    {
        if ($lifeTime !== null && $lifeTime !== false && $lifeTime !== 0) {
            return (int)$lifeTime;
        }
        
        if ($lifeTime === 0 || $lifeTime === false) {
            // 0 or false means use default in Zend behavior
            return $this->defaultLifetime;
        }
        
        return $this->defaultLifetime;
    }

    /**
     * Prepare cache item with metadata wrapper
     *
     * @param CacheItemInterface $item
     * @param mixed $data
     * @param array $cleanTags
     * @param int|null $actualLifetime
     * @return void
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     */
    private function prepareItemWithMetadata($item, $data, array $cleanTags, ?int $actualLifetime): void
    {
        $now = time();
        
        // Get enhanced tags (including namespace tags if applicable)
        $tagsToSet = $cleanTags;
        if ($this->adapter instanceof GenericAdapterService && !empty($cleanTags)) {
            $tagsToSet = $this->adapter->getTagsForSave($cleanTags);
        }
        
        // Calculate expiry timestamp (for Zend compatibility)
        $expiry = $actualLifetime !== null ? ($now + $actualLifetime) : null;
        
        // Wrap data with metadata for consistent timestamps
        $wrappedData = [
            'data' => $data,
            'mtime' => $now,
            'expire' => $expiry,
            'tags' => array_values(array_unique($tagsToSet))
        ];
        
        $item->set($wrappedData);
        
        // Handle tags
        if ($this->isTagAware() && !empty($tagsToSet)) {
            $item->tag($tagsToSet);
        }
    }

    /**
     * Commit cache and notify helpers
     *
     * @param CacheItemPoolInterface $cache
     * @param bool $success
     * @param string $cleanId
     * @param array $cleanTags
     * @return void
     */
    private function commitAndNotify($cache, bool $success, string $cleanId, array $cleanTags): void
    {
        // Ensure immediate persistence (commit any deferred saves)
        if ($success && method_exists($cache, 'commit')) {
            $cache->commit();
        }
        
        // Notify helper about the save (for Redis/Filesystem to maintain indices)
        if ($success && !empty($cleanTags)) {
            $this->adapter->onSave($cleanId, $cleanTags);
            
            // For Redis, also store reverse index
            if (method_exists($this->adapter, 'storeReverseIndex')) {
                $this->adapter->storeReverseIndex($cleanId, $cleanTags);
            }
        }
    }

    /**
     * @inheritDoc
     */
    public function remove($identifier)
    {
        $cache = $this->getCache();
        $cleanId = $this->cleanIdentifier($identifier);
        
        // Notify helper before removal (for index cleanup)
        $this->adapter->onRemove($cleanId);
        
        return $cache->deleteItem($cleanId);
    }

    /**
     * @inheritDoc
     */
    public function clean($mode = CacheConstants::CLEANING_MODE_ALL, array $tags = [])
    {
        // Validate cleaning mode
        $validModes = [
            CacheConstants::CLEANING_MODE_ALL,
            CacheConstants::CLEANING_MODE_OLD,
            CacheConstants::CLEANING_MODE_MATCHING_TAG,
            CacheConstants::CLEANING_MODE_NOT_MATCHING_TAG,
            CacheConstants::CLEANING_MODE_MATCHING_ANY_TAG
        ];
        
        if (!in_array($mode, $validModes, true)) {
            throw new InvalidArgumentException(
                "Invalid cleaning mode '{$mode}'. Supported modes: " .
                "ALL, OLD, MATCHING_TAG, NOT_MATCHING_TAG, MATCHING_ANY_TAG"
            );
        }
        
        $cache = $this->getCache();
        
        return match ($mode) {
            CacheConstants::CLEANING_MODE_ALL, 'all' => $this->cleanAll($cache),
            CacheConstants::CLEANING_MODE_OLD, 'old' => $this->cleanOld($cache),
            CacheConstants::CLEANING_MODE_MATCHING_TAG, 'matchingTag' =>
                $this->cleanMatchingTag($cache, $tags),
            CacheConstants::CLEANING_MODE_NOT_MATCHING_TAG, 'notMatchingTag' =>
                $this->cleanNotMatchingTag($cache, $tags),
            CacheConstants::CLEANING_MODE_MATCHING_ANY_TAG, 'matchingAnyTag' =>
                $this->cleanMatchingAnyTag($cache, $tags),
            default => throw new InvalidArgumentException("Unsupported cleaning mode: {$mode}")
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
        $this->adapter->clearAllIndices();
        return $cache->clear();
    }

    /**
     * Clean old/expired cache entries
     *
     * @param CacheItemPoolInterface $cache
     * @return bool
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
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
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     */
    private function cleanMatchingTag(CacheItemPoolInterface $cache, array $tags): bool
    {
        if (empty($tags)) {
            return true;
        }
        
        $cleanTags = $this->cleanIdentifiers($tags);
        
        // For GenericHelper with namespace tags, use the namespace tag
        if ($this->adapter instanceof GenericAdapterService) {
            if ($this->adapter->usesNamespaceTags()) {
                $tagsToInvalidate = $this->adapter->getTagsForMatchingTag($cleanTags);
                if ($this->isTagAware()) {
                    $success = $cache->invalidateTags($tagsToInvalidate);
                    
                    // CRITICAL: Commit invalidation immediately and clear internal cache state
                    if (method_exists($cache, 'commit')) {
                        $cache->commit();
                    }
                    if (method_exists($cache, 'prune')) {
                        $cache->prune();
                    }
                    
                    return $success;
                }
                return false;
            } else {
                // For non-FPC generic adapters, use OR logic (best we can do)
                if ($this->isTagAware()) {
                    $success = $cache->invalidateTags($cleanTags);
                    
                    // CRITICAL: Commit invalidation immediately and clear internal cache state
                    if (method_exists($cache, 'commit')) {
                        $cache->commit();
                    }
                    if (method_exists($cache, 'prune')) {
                        $cache->prune();
                    }
                    
                    return $success;
                }
                return false;
            }
        }
        
        // For Redis/Filesystem helpers with native AND support
        $ids = $this->adapter->getIdsMatchingTags($cleanTags);
        
        if (empty($ids)) {
            return true;
        }
        
        return $this->adapter->deleteByIds($ids);
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
        $ids = $this->adapter->getIdsNotMatchingTags($cleanTags);
        
        if (empty($ids)) {
            return true;
        }
        
        return $this->adapter->deleteByIds($ids);
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
        
        $wrappedData = $item->get();
        
        // Return stored metadata from wrapper
        if (is_array($wrappedData) && isset($wrappedData['mtime'])) {
            // Add cache ID prefix to tags (to match Zend behavior)
            $storedTags = $wrappedData['tags'] ?? [];
            $tags = array_values(array_map(function ($tag) {
                return self::DEFAULT_CACHE_PREFIX . $tag;
            }, $storedTags));
            
            return [
                'expire' => $wrappedData['expire'] ?? null,
                'tags' => $tags,
                'mtime' => $wrappedData['mtime'],
            ];
        }
        
        // Fallback for non-wrapped data (shouldn't happen)
        // Calculate metadata from Symfony metadata
        $metadata = $item->getMetadata();
        
        // Get expiry timestamp from Symfony metadata
        $expiry = null;
        if (isset($metadata[CacheItem::METADATA_EXPIRY])) {
            $expiry = (int) $metadata[CacheItem::METADATA_EXPIRY];
        }
        
        // If no expiry, default to now + 24 hours
        if (!$expiry) {
            $expiry = time() + self::FALLBACK_EXPIRY;
        }
        
        // Calculate mtime from expiry (Symfony doesn't store creation time)
        // Use ASSUMED_LIFETIME for approximation: mtime ≈ expiry - lifetime
        $mtime = $expiry - self::ASSUMED_LIFETIME;
        
        // Ensure mtime is not in the future
        $now = time();
        if ($mtime > $now) {
            $mtime = $now;
        }
        
        // Get tags from metadata and add cache ID prefix
        $tags = [];
        if (isset($metadata[CacheItem::METADATA_TAGS])) {
            $rawTags = $metadata[CacheItem::METADATA_TAGS];
            $tags = array_values(array_map(function ($tag) {
                return self::DEFAULT_CACHE_PREFIX . $tag;
            }, $rawTags));
        }
        
        return [
            'expire' => $expiry,
            'tags' => $tags,
            'mtime' => $mtime,
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
            $success = $cache->invalidateTags($cleanTags);
            
            // CRITICAL: Commit invalidation immediately and clear internal cache state
            // This ensures invalidated entries are not returned by subsequent getItem() calls
            if (method_exists($cache, 'commit')) {
                $cache->commit();
            }
            if (method_exists($cache, 'prune')) {
                $cache->prune();
            }
            
            return $success;
        }
        
        // Fallback: use helper
        $ids = $this->adapter->getIdsMatchingAnyTags($cleanTags);
        
        if (empty($ids)) {
            return true;
        }
        
        return $this->adapter->deleteByIds($ids);
    }

    /**
     * @inheritDoc
     */
    public function getBackend()
    {
        return new Symfony\BackendWrapper($this->getCache(), $this->adapter, $this);
    }

    /**
     * @inheritDoc
     */
    public function getLowLevelFrontend()
    {
        return new Symfony\LowLevelFrontend(
            $this->getCache(),
            $this,
            $this->adapter,
            $this->idPrefix,
            $this->defaultLifetime
        );
    }

    /**
     * @inheritDoc
     */
    public function getFrontend()
    {
        return $this;
    }
}
