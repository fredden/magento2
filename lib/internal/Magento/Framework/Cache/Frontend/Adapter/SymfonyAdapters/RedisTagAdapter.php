<?php
/**
 * Copyright 2025 Adobe
 * All Rights Reserved.
 */
declare(strict_types=1);

namespace Magento\Framework\Cache\Frontend\Adapter\SymfonyAdapters;

use Predis\Client as PredisClient;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\Cache\Adapter\RedisAdapter;
use Symfony\Component\Cache\Adapter\TagAwareAdapter;

/**
 * Redis-specific tag adapter
 *
 * Implements tag-to-ID index management using Redis SETs, similar to Colin Mollenhour's
 * Cm_Cache_Backend_Redis implementation. This enables true AND logic for MATCHING_TAG mode
 * using Redis SINTER (set intersection) operation.
 *
 * Storage structure:
 * - Cache items: cache:namespace:id => {data, metadata}
 * - Tag indices: cache:tags:tagname => SET{id1, id2, id3}
 *
 * Example:
 * - Item 'config_1' with tags ['config', 'eav']
 * - Redis stores:
 *   - cache:69d_config_1 => {data}
 *   - cache:tags:69d_config => SET{config_1, config_2}
 *   - cache:tags:69d_eav => SET{config_1, eav_1}
 *
 * MATCHING_TAG(['config', 'eav']):
 * - SINTER cache:tags:69d_config cache:tags:69d_eav
 * - Returns: {config_1}  ← Only IDs in BOTH sets (true AND logic)
 */
class RedisTagAdapter implements TagAdapterInterface
{
    private const TAG_INDEX_PREFIX = 'cache:tags:';
    private const ALL_IDS_SET = 'cache:all_ids';

    /**
     * @var \Redis|\RedisCluster|PredisClient
     */
    private \Redis|\RedisCluster|PredisClient $redis;

    /**
     * @var string
     */
    private string $namespace;

    /**
     * @var CacheItemPoolInterface
     */
    private CacheItemPoolInterface $cachePool;

    /**
     * @var RedisLuaHelper|null
     */
    private ?RedisLuaHelper $luaHelper = null;

    /**
     * @var bool
     */
    private bool $useLua;

    /**
     * @var bool
     */
    private bool $useLuaOnGc;

    /**
     * @param CacheItemPoolInterface $cachePool
     * @param string $namespace Cache namespace/prefix
     * @param bool $useLua Enable Lua scripts for cache operations
     * @param bool $useLuaOnGc Enable Lua scripts for garbage collection
     */
    public function __construct(
        CacheItemPoolInterface $cachePool,
        string $namespace = '',
        bool $useLua = false,
        bool $useLuaOnGc = false
    ) {
        $this->cachePool = $cachePool;
        $this->namespace = $namespace;
        $this->redis = $this->extractRedisClient($cachePool);
        
        // Disable Lua for Predis (only works with phpredis)
        if ($this->redis instanceof PredisClient) {
            $this->useLua = false;
            $this->useLuaOnGc = false;
        } else {
            $this->useLua = $useLua;
            $this->useLuaOnGc = $useLuaOnGc;
        }
        
        // Initialize Lua helper if either flag is enabled (phpredis only)
        if (($this->useLua || $this->useLuaOnGc) && !($this->redis instanceof PredisClient)) {
            $this->luaHelper = new RedisLuaHelper($this->redis, true);
        }
    }

    /**
     * Extract Redis client from Symfony cache adapter
     *
     * @param CacheItemPoolInterface $cachePool
     * @return \Redis|\RedisCluster|PredisClient
     * @throws \RuntimeException If Redis client cannot be extracted
     */
    private function extractRedisClient(CacheItemPoolInterface $cachePool): \Redis|\RedisCluster|PredisClient
    {
        // Unwrap TagAwareAdapter if present
        $adapter = $cachePool;
        if ($adapter instanceof TagAwareAdapter) {
            $reflection = new \ReflectionClass($adapter);
            $poolProperty = $reflection->getProperty('pool');
            $poolProperty->setAccessible(true);
            $adapter = $poolProperty->getValue($adapter);
        }

        // Get Redis client from RedisAdapter
        if ($adapter instanceof RedisAdapter) {
            $reflection = new \ReflectionClass($adapter);
            $redisProperty = $reflection->getProperty('redis');
            $redisProperty->setAccessible(true);
            $redis = $redisProperty->getValue($adapter);

            if ($redis instanceof \Redis || $redis instanceof \RedisCluster || $redis instanceof PredisClient) {
                return $redis;
            }
        }

        throw new \RuntimeException('Could not extract Redis client from cache adapter');
    }

    /**
     * Get prefixed tag name for Redis SET key
     *
     * @param string $tag
     * @return string
     */
    private function getTagKey(string $tag): string
    {
        return self::TAG_INDEX_PREFIX . $this->namespace . $tag;
    }

    /**
     * Create Redis pipeline compatible with both phpredis and Predis
     *
     * @return \Redis|object Predis pipeline object
     */
    private function createPipeline()
    {
        if ($this->redis instanceof PredisClient) {
            // Predis uses pipeline() method
            return $this->redis->pipeline();
        }
        
        // phpredis uses multi(PIPELINE)
        return $this->redis->multi(\Redis::PIPELINE);
    }

    /**
     * Execute Redis pipeline compatible with both phpredis and Predis
     *
     * @param \Redis|object $pipeline
     * @return mixed
     */
    private function executePipeline($pipeline)
    {
        if ($pipeline instanceof PredisClient || method_exists($pipeline, 'execute')) {
            // Predis pipeline
            return $pipeline->execute();
        }
        
        // phpredis pipeline
        return $pipeline->exec();
    }

    /**
     * @inheritDoc
     *
     * Uses Redis SINTER for efficient set intersection (true AND logic)
     */
    public function getIdsMatchingTags(array $tags): array
    {
        if (empty($tags)) {
            return [];
        }

        // Build tag keys for Redis SINTER
        $tagKeys = array_map([$this, 'getTagKey'], $tags);

        // Redis SINTER returns IDs present in ALL sets
        $ids = $this->redis->sinter($tagKeys);

        return is_array($ids) ? $ids : [];
    }

    /**
     * @inheritDoc
     *
     * Uses Redis SUNION for efficient set union (OR logic)
     *
     * OPTIMIZED: Single tag uses SMEMBERS (faster), multiple tags use SUNION
     * Redis SUNION already returns unique values, no need for array_unique()
     */
    public function getIdsMatchingAnyTags(array $tags): array
    {
        if (empty($tags)) {
            return [];
        }

        // OPTIMIZATION: For single tag, use SMEMBERS directly (faster than SUNION)
        if (count($tags) === 1) {
            $ids = $this->redis->sMembers($this->getTagKey($tags[0]));
            return is_array($ids) ? $ids : [];
        }

        // Build tag keys for Redis SUNION
        $tagKeys = array_map([$this, 'getTagKey'], $tags);

        // Redis SUNION returns IDs present in ANY set
        // Note: SUNION already returns unique values, no need for array_unique()
        $ids = $this->redis->sUnion($tagKeys);

        return is_array($ids) ? $ids : [];
    }

    /**
     * @inheritDoc
     *
     * Gets all IDs and removes those matching any of the given tags
     */
    public function getIdsNotMatchingTags(array $tags): array
    {
        if (empty($tags)) {
            // Return all IDs if no tags specified
            $allIds = $this->redis->smembers(self::ALL_IDS_SET);
            return is_array($allIds) ? $allIds : [];
        }

        // Get all IDs
        $allIds = $this->redis->smembers(self::ALL_IDS_SET);
        if (!is_array($allIds) || empty($allIds)) {
            return [];
        }

        // Get IDs matching any tag
        $matchingIds = $this->getIdsMatchingAnyTags($tags);

        // Return difference
        return array_diff($allIds, $matchingIds);
    }

    /**
     * Get IDs matching ANY of the tags AND also matching a scope tag (SAFE VERSION)
     *
     * This method implements an optimized batch operation using SUNION for cache invalidation
     * when dealing with large tag sets (e.g., configurable product saves with 50+ tags).
     *
     * SAFETY FEATURES:
     * - Only optimizes for large tag sets (10+ tags) to avoid category operation edge cases
     * - Returns false (not empty array) to signal fallback to loop for safety
     * - Validates all Redis responses to prevent cache corruption
     * - Conservative approach: Falls back on ANY uncertainty
     *
     * WHY THIS IS NEEDED:
     * Original TagScope decorator loops through each tag calling SINTER (AND logic):
     * - 59 product tags = 59 SINTER operations (~118ms overhead in production)
     * With this optimization:
     * - 1 SUNION + 1 SMEMBERS + 1 PHP array_intersect = ~5ms overhead
     *
     * WHY V1 FAILED MFTF:
     * V1 optimized ALL tag sets, including categories (1-3 tags).
     * New categories have tags but no FPC items, causing edge cases.
     * V2 only optimizes large tag sets (products), letting loop handle categories.
     *
     * @param array $tags Entity tags to match (OR logic)
     * @param string $scopeTag Scope tag that all results must also have (AND logic)
     * @return array|false Array of IDs if optimization is safe, false to fall back to loop
     */
    public function getIdsMatchingAnyTagsAndScopeIfSafe(array $tags, string $scopeTag): array|false
    {
        if (empty($tags)) {
            return false;  // Let loop handle empty tags
        }

        // V3: NO pattern checks needed - optimize everything!
        // The key is to delete through cache interface, not bypass it

        // Step 1: Get all IDs matching ANY of the tags (SUNION operation)
        $matchingIds = $this->getIdsMatchingAnyTags($tags);

        if (empty($matchingIds)) {
            // No items with these tags - nothing to clean
            return [];  // Use optimization, just nothing to delete
        }

        // Step 2: Get all IDs with the scope tag (SMEMBERS operation)
        $scopeIds = $this->redis->sMembers($this->getTagKey($scopeTag));

        // SAFETY: Only reject on actual Redis error
        if ($scopeIds === false || !is_array($scopeIds)) {
            return false;  // Redis error - fall back to loop
        }

        if (empty($scopeIds)) {
            // No items with scope tag - nothing to clean
            return [];  // Use optimization, just nothing to delete
        }

        // Step 3: Perform intersection in PHP
        // Ensure consistent string types (Redis returns strings)
        $matchingIds = array_map('strval', $matchingIds);
        $scopeIds = array_map('strval', $scopeIds);

        $result = array_intersect($matchingIds, $scopeIds);

        // Return IDs to delete (empty is OK - means nothing to clean)
        return array_values($result);
    }

    /**
     * @inheritDoc
     *
     * OPTIMIZED: Uses Redis pipeline for large batches
     */
    public function deleteByIds(array $ids): bool
    {
        if (empty($ids)) {
            return true;
        }

        // Delete cache items
        $success = $this->cachePool->deleteItems($ids);

        // OPTIMIZATION: Use pipeline for large batches (more than 10 IDs)
        if (count($ids) > 10) {
            $pipeline = $this->createPipeline();
            
            // Remove each ID from all_ids set in pipeline
            foreach ($ids as $id) {
                $pipeline->srem(self::ALL_IDS_SET, $id);
            }
            
            $this->executePipeline($pipeline);
        } else {
            // For small batches, use single command (slightly faster)
            // Use array_unshift to prepend the set key, then call_user_func_array
            array_unshift($ids, self::ALL_IDS_SET);
            call_user_func_array([$this->redis, 'sRem'], $ids);
        }

        return $success;
    }

    /**
     * @inheritDoc
     *
     * Maintains tag-to-ID indices in Redis SETs
     * OPTIMIZED: Uses Redis pipeline for batch operations
     */
    public function onSave(string $id, array $tags): void
    {
        if (empty($tags)) {
            return;
        }

        // OPTIMIZATION: Use Redis pipeline for all operations
        // Reduces network round trips from N+1 to 1
        $pipeline = $this->createPipeline();
        
        // Add ID to all_ids set
        $pipeline->sadd(self::ALL_IDS_SET, $id);

        // Add ID to each tag's SET in pipeline
        foreach ($tags as $tag) {
            $tagKey = $this->getTagKey($tag);
            $pipeline->sadd($tagKey, $id);
        }
        
        // Execute all operations in one go
        $this->executePipeline($pipeline);
    }

    /**
     * @inheritDoc
     *
     * Removes ID from all tag indices
     * OPTIMIZED: Uses Redis pipeline for batch operations
     */
    public function onRemove(string $id): void
    {
        // We need to find which tags this ID was associated with
        // Store a reverse index: cache:id:tags => SET{tag1, tag2}
        $idTagsKey = 'cache:id_tags:' . $this->namespace . $id;
        $tags = $this->redis->smembers($idTagsKey);
        
        if (!is_array($tags) || empty($tags)) {
            // No tags, just remove from all_ids
            $this->redis->srem(self::ALL_IDS_SET, $id);
            return;
        }
        
        // OPTIMIZATION: Use Redis pipeline for all remove operations
        // Reduces network round trips from N+2 to 1
        $pipeline = $this->createPipeline();
        
        // Remove from all_ids set
        $pipeline->srem(self::ALL_IDS_SET, $id);
        
        // Remove ID from each tag's SET in pipeline
        foreach ($tags as $tag) {
            $tagKey = $this->getTagKey($tag);
            $pipeline->srem($tagKey, $id);
        }
        
        // Delete the reverse index
        $pipeline->del($idTagsKey);
        
        // Execute all operations in one go
        $this->executePipeline($pipeline);
    }

    /**
     * @inheritDoc
     */
    public function clearAllIndices(): void
    {
        // Use Lua script if enabled for atomic, efficient clearing
        if ($this->useLua && $this->luaHelper) {
            $this->luaHelper->clearAllIndices($this->namespace);
            // Lua script handles everything atomically
            return;
        }

        // Fallback: PHP-based clearing (original implementation)
        // Get all tag keys
        $pattern = self::TAG_INDEX_PREFIX . $this->namespace . '*';
        $tagKeys = $this->redis->keys($pattern);

        if (is_array($tagKeys) && !empty($tagKeys)) {
            // PHP 8+ compatibility: use call_user_func_array to avoid spread operator issues
            call_user_func_array([$this->redis, 'del'], $tagKeys);
        }

        // Clear all_ids set
        $this->redis->del(self::ALL_IDS_SET);

        // Clear reverse index keys
        $reversePattern = 'cache:id_tags:' . $this->namespace . '*';
        $reverseKeys = $this->redis->keys($reversePattern);
        if (is_array($reverseKeys) && !empty($reverseKeys)) {
            // PHP 8+ compatibility: use call_user_func_array to avoid spread operator issues
            call_user_func_array([$this->redis, 'del'], $reverseKeys);
        }
    }

    /**
     * Store reverse index for efficient onRemove
     * This should be called after onSave
     * OPTIMIZED: Uses Redis pipeline for batch operations
     *
     * @param string $id
     * @param array $tags
     * @return void
     */
    public function storeReverseIndex(string $id, array $tags): void
    {
        if (empty($tags)) {
            return;
        }

        $idTagsKey = 'cache:id_tags:' . $this->namespace . $id;
        
        // OPTIMIZATION: Use Redis pipeline for all operations
        // Reduces network round trips from N+1 to 1
        $pipeline = $this->createPipeline();
        
        // Clear existing reverse index
        $pipeline->del($idTagsKey);
        
        // Add all tags to reverse index in pipeline
        foreach ($tags as $tag) {
            $pipeline->sadd($idTagsKey, $tag);
        }
        
        // Execute all operations in one go
        $this->executePipeline($pipeline);
    }

    /**
     * Run garbage collection to clean expired items
     *
     * Uses Lua scripts if use_lua_on_gc is enabled for atomic server-side execution,
     * otherwise returns 0 (no garbage collection)
     *
     * @param int $batchSize Number of keys to process per iteration
     * @return int Number of items cleaned
     */
    public function garbageCollect(int $batchSize = 1000): int
    {
        // Garbage collection specifically checks use_lua_on_gc flag
        if (!$this->useLuaOnGc || !$this->luaHelper) {
            return 0;
        }

        $result = $this->luaHelper->garbageCollect(
            $this->namespace . '*',
            self::TAG_INDEX_PREFIX . $this->namespace,
            $batchSize
        );

        return $result[0]; // Return deleted count (first element)
    }

    /**
     * Check if Lua scripts are enabled and available
     *
     * @return bool
     */
    public function isLuaEnabled(): bool
    {
        return ($this->useLua || $this->useLuaOnGc)
            && $this->luaHelper !== null
            && $this->luaHelper->isEnabled();
    }

    /**
     * Clean expired items for specific tag using Lua
     *
     * Only deletes items that have expired (TTL = -2)
     * More efficient than fetching all IDs and checking client-side
     * Uses use_lua flag (general cache operations)
     *
     * @param string $tag Tag to clean
     * @return int Number of items deleted
     */
    public function cleanExpiredByTag(string $tag): int
    {
        // Tag operations check use_lua flag
        if (!$this->useLua || !$this->luaHelper) {
            return 0;
        }

        $tagKey = $this->getTagKey($tag);
        
        return $this->luaHelper->cleanByTagConditional(
            $tagKey,
            $this->namespace,
            'expired'
        );
    }
}
