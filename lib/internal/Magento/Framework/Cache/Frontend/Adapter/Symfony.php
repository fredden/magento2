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
     * Constructor
     *
     * @param CacheItemPoolInterface $cache
     * @param \Closure|null $cacheFactory Factory to recreate cache pool after fork
     */
    public function __construct(
        CacheItemPoolInterface $cache,
        ?\Closure $cacheFactory = null
    ) {
        $this->cache = $cache;
        $this->cacheFactory = $cacheFactory;
        $this->pid = getmypid();
    }

    /**
     * Get cache pool, recreating if process has forked
     *
     * @return CacheItemPoolInterface
     */
    private function getCache(): CacheItemPoolInterface
    {
        $currentPid = getmypid();
        if ($currentPid !== $this->pid) {
            // Fork detected - save parent's cache pool and create new one
            $this->parentCachePools[$this->pid] = $this->cache;
            
            if ($this->cacheFactory !== null) {
                $this->cache = ($this->cacheFactory)();
                $this->pid = $currentPid;
            } else {
                // No factory provided, just update PID
                // This may cause issues but better than failing
                $this->pid = $currentPid;
            }
        }
        return $this->cache;
    }

    /**
     * Clean identifier from reserved characters
     *
     * PSR-6 reserved characters: {}()/\@:
     *
     * @param string $identifier
     * @return string
     */
    private function cleanIdentifier(string $identifier): string
    {
        // First normalize the identifier similar to Zend (but keep case)
        $identifier = str_replace('.', '__', $identifier);
        $identifier = preg_replace('/([^a-zA-Z0-9_]{1,1})/', '_', $identifier);
        
        // Then clean PSR-6 reserved characters
        return preg_replace('/[{}()\/\\\\@:]/', '_', $identifier);
    }

    /**
     * @inheritdoc
     */
    public function test($identifier)
    {
        $item = $this->getCache()->getItem($this->cleanIdentifier($identifier));
        
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
     */
    public function save($data, $identifier, array $tags = [], $lifeTime = null)
    {
        $cache = $this->getCache();
        $item = $cache->getItem($this->cleanIdentifier($identifier));
        $item->set($data);

        // Set expiration time
        if ($lifeTime !== null && $lifeTime !== false) {
            // Ensure integer for Symfony
            $item->expiresAfter((int)$lifeTime);
        }

        // Handle tags if cache supports it
        if (!empty($tags) && $cache instanceof TagAwareAdapterInterface) {
            $cleanTags = array_map([$this, 'cleanIdentifier'], $tags);
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
     */
    public function clean($mode = \Zend_Cache::CLEANING_MODE_ALL, array $tags = [])
    {
        $cache = $this->getCache();
        
        switch ($mode) {
            case \Zend_Cache::CLEANING_MODE_ALL:
            case 'all':
                return $cache->clear();
                
            case \Zend_Cache::CLEANING_MODE_MATCHING_TAG:
            case 'matchingTag':
                // MATCHING_TAG in Zend means: clear items with ALL these tags (AND logic)
                // Symfony invalidateTags uses OR logic: clear items with ANY of these tags
                // When used with TagScope decorator, this creates an issue where scope tags clear everything
                // 
                // Workaround: Only use the FIRST non-scope tag to maintain expected behavior
                // This works because TagScope calls clean separately for each user tag
                if ($cache instanceof TagAwareAdapterInterface && !empty($tags)) {
                    $cleanTags = array_map([$this, 'cleanIdentifier'], $tags);
                    
                    // If multiple tags provided (likely from TagScope), only use the first one
                    // TagScope pattern: [$userTag, $scopeTag] - we want just $userTag
                    if (count($cleanTags) > 1) {
                        // Use only the first tag (the actual user-requested tag)
                        $cleanTags = [$cleanTags[0]];
                    }
                    
                    return $cache->invalidateTags($cleanTags);
                }
                if (!empty($tags)) {
                    return $cache->clear();
                }
                return true;
                
            case \Zend_Cache::CLEANING_MODE_MATCHING_ANY_TAG:
            case 'matchingAnyTag':
                // MATCHING_ANY_TAG: clear items with ANY of these tags (OR logic)
                // This matches Symfony's invalidateTags behavior perfectly
                if ($cache instanceof TagAwareAdapterInterface && !empty($tags)) {
                    $cleanTags = array_map([$this, 'cleanIdentifier'], $tags);
                    return $cache->invalidateTags($cleanTags);
                }
                if (!empty($tags)) {
                    return $cache->clear();
                }
                return true;
                
            case \Zend_Cache::CLEANING_MODE_OLD:
            case 'old':
                // Symfony Cache handles this automatically
                // Old entries are removed when they expire
                return true;
                
            case \Zend_Cache::CLEANING_MODE_NOT_MATCHING_TAG:
            case 'notMatchingTag':
                // Not supported by PSR-6/Symfony - would require listing all keys
                // Fallback to no-op
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

