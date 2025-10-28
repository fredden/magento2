<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Magento\Framework\Cache\Frontend\Adapter\Helper;

use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\Cache\Adapter\RedisAdapter;
use Symfony\Component\Cache\Adapter\TagAwareAdapter;

/**
 * Redis-specific adapter helper
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
class RedisAdapterHelper implements AdapterHelperInterface
{
    private const TAG_INDEX_PREFIX = 'cache:tags:';
    private const ALL_IDS_SET = 'cache:all_ids';

    private \Redis $redis;
    private string $namespace;
    private CacheItemPoolInterface $cachePool;

    /**
     * @param CacheItemPoolInterface $cachePool
     * @param string $namespace Cache namespace/prefix
     */
    public function __construct(CacheItemPoolInterface $cachePool, string $namespace = '')
    {
        $this->cachePool = $cachePool;
        $this->namespace = $namespace;
        $this->redis = $this->extractRedisClient($cachePool);
    }

    /**
     * Extract Redis client from Symfony cache adapter
     * 
     * @param CacheItemPoolInterface $cachePool
     * @return \Redis
     * @throws \RuntimeException If Redis client cannot be extracted
     */
    private function extractRedisClient(CacheItemPoolInterface $cachePool): \Redis
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

            if ($redis instanceof \Redis || $redis instanceof \RedisCluster) {
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
     * {@inheritdoc}
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
        $ids = $this->redis->sInter($tagKeys);

        return is_array($ids) ? $ids : [];
    }

    /**
     * {@inheritdoc}
     * 
     * Uses Redis SUNION for efficient set union (OR logic)
     */
    public function getIdsMatchingAnyTags(array $tags): array
    {
        if (empty($tags)) {
            return [];
        }

        // Build tag keys for Redis SUNION
        $tagKeys = array_map([$this, 'getTagKey'], $tags);

        // Redis SUNION returns IDs present in ANY set
        $ids = $this->redis->sUnion($tagKeys);

        return is_array($ids) ? array_unique($ids) : [];
    }

    /**
     * {@inheritdoc}
     * 
     * Gets all IDs and removes those matching any of the given tags
     */
    public function getIdsNotMatchingTags(array $tags): array
    {
        if (empty($tags)) {
            // Return all IDs if no tags specified
            $allIds = $this->redis->sMembers(self::ALL_IDS_SET);
            return is_array($allIds) ? $allIds : [];
        }

        // Get all IDs
        $allIds = $this->redis->sMembers(self::ALL_IDS_SET);
        if (!is_array($allIds) || empty($allIds)) {
            return [];
        }

        // Get IDs matching any tag
        $matchingIds = $this->getIdsMatchingAnyTags($tags);

        // Return difference
        return array_diff($allIds, $matchingIds);
    }

    /**
     * {@inheritdoc}
     */
    public function deleteByIds(array $ids): bool
    {
        if (empty($ids)) {
            return true;
        }

        // Delete cache items
        $success = $this->cachePool->deleteItems($ids);

        // Remove IDs from all_ids set - batch operation for better performance
        if (!empty($ids)) {
            // Use array_unshift to prepend the set key, then call_user_func_array for batch operation
            array_unshift($ids, self::ALL_IDS_SET);
            call_user_func_array([$this->redis, 'sRem'], $ids);
        }

        return $success;
    }

    /**
     * {@inheritdoc}
     * 
     * Maintains tag-to-ID indices in Redis SETs
     */
    public function onSave(string $id, array $tags): void
    {
        if (empty($tags)) {
            return;
        }

        // Add ID to all_ids set
        $this->redis->sAdd(self::ALL_IDS_SET, $id);

        // Add ID to each tag's SET
        foreach ($tags as $tag) {
            $tagKey = $this->getTagKey($tag);
            $this->redis->sAdd($tagKey, $id);
        }
    }

    /**
     * {@inheritdoc}
     * 
     * Removes ID from all tag indices
     */
    public function onRemove(string $id): void
    {
        // Remove from all_ids set
        $this->redis->sRem(self::ALL_IDS_SET, $id);

        // We need to find which tags this ID was associated with
        // This is expensive, so we'll use a different approach:
        // Store a reverse index: cache:id:tags => SET{tag1, tag2}
        
        $idTagsKey = 'cache:id_tags:' . $this->namespace . $id;
        $tags = $this->redis->sMembers($idTagsKey);
        
        if (is_array($tags) && !empty($tags)) {
            // Remove ID from each tag's SET
            foreach ($tags as $tag) {
                $tagKey = $this->getTagKey($tag);
                $this->redis->sRem($tagKey, $id);
            }
            
            // Delete the reverse index
            $this->redis->del($idTagsKey);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function clearAllIndices(): void
    {
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
        
        // Clear existing reverse index
        $this->redis->del($idTagsKey);
        
        // Store new reverse index - PHP 8+ compatibility
        if (!empty($tags)) {
            // Add each tag individually to avoid spread operator issues
            foreach ($tags as $tag) {
                $this->redis->sAdd($idTagsKey, $tag);
            }
        }
    }
}

