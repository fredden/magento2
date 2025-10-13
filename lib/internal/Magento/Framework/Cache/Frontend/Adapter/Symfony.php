<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Magento\Framework\Cache\Frontend\Adapter;

use Magento\Framework\Cache\CacheConstants;
use Magento\Framework\Cache\CacheOperationsTrait;
use Magento\Framework\Cache\FrontendInterface;
use Magento\Framework\Cache\NamespaceTagGeneratorTrait;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Cache\Adapter\TagAwareAdapterInterface;

/**
 * Symfony Cache adapter for Magento cache frontend interface
 *
 * Uses shared traits for common functionality:
 * - CacheOperationsTrait: ID and tag processing
 * - NamespaceTagGeneratorTrait: Namespace generation for AND logic
 *
 * Namespace Strategy for AND Logic:
 * - Creates composite "namespace" tags that represent tag combinations
 * - For tags ['A', 'B', 'C'], creates namespace tags for all subsets
 * - MATCHING_TAG with ['A', 'B'] uses namespace tag 'NS_A|B'
 * - Uses Symfony's native invalidateTags() with smart composite tags
 * - Achieves TRUE AND logic without custom tag-to-ID mappings
 *
 * Performance optimizations:
 * - Shared cache operations via traits
 * - Cached identifier cleaning
 * - Reduced instanceof checks
 * - Minimal PID checking overhead
 */
class Symfony implements FrontendInterface
{
    use CacheOperationsTrait;
    use NamespaceTagGeneratorTrait;

    /**
     * Namespace prefix for composite tags
     */
    private const NAMESPACE_PREFIX = 'NS_';

    /**
     * Separator for namespace tag combinations
     */
    private const NAMESPACE_SEPARATOR = '|';

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
     * Keep references to parent cache pools to prevent destruction
     *
     * @var array
     */
    private array $parentCachePools = [];

    /**
     * Whether the cache supports tag-aware operations (cached)
     *
     * @var bool|null
     */
    private ?bool $isTagAware = null;

    /**
     * Frontend identifier for cache isolation
     * Used to prefix all tags (similar to cache_id_prefix)
     *
     * @var string
     */
    private string $frontendIdentifier;

    /**
     * Cached prefix string (frontendIdentifier + '_')
     * Avoids repeated string concatenation
     *
     * @var string
     */
    private string $cachedPrefix;

    /**
     * Constructor
     *
     * @param CacheItemPoolInterface $cache
     * @param \Closure|null $cacheFactory Factory to recreate cache pool after fork
     * @param string $frontendIdentifier Unique identifier for this frontend instance
     * @param LoggerInterface|null $logger Logger for debugging
     */
    public function __construct(
        CacheItemPoolInterface $cache,
        ?\Closure $cacheFactory = null,
        string $frontendIdentifier = '',
        ?LoggerInterface $logger = null
    ) {
        $this->cache = $cache;
        $this->cacheFactory = $cacheFactory;
        $this->pid = getmypid();
        $this->frontendIdentifier = $frontendIdentifier;
        $this->cachedPrefix = $frontendIdentifier ? $frontendIdentifier . '_' : '';
        
        if ($logger) {
            $this->setNamespaceLogger($logger);
        }
    }

    /**
     * Make and return a cache id (mimics Zend's _id() pattern)
     *
     * @param string|null $cacheId Cache id
     * @return string|null
     */
    protected function _id($cacheId)
    {
        if ($cacheId === null) {
            return null;
        }

        // Use trait for cleaning
        $cleanedId = $this->cleanCacheIdentifier($cacheId);
        
        // Symfony uses frontendIdentifier for prefixing (not cache_id_prefix)
        // The prefix is applied per-tag in _tagsWithNamespace, not on IDs
        return $cleanedId;
    }

    /**
     * @inheritdoc
     */
    public function test($identifier)
    {
        $item = $this->getCache()->getItem($this->_id($identifier));
        return $item->isHit();
    }

    /**
     * @inheritdoc
     */
    public function load($identifier)
    {
        $item = $this->getCache()->getItem($this->_id($identifier));
        return $item->isHit() ? $item->get() : false;
    }

    /**
     * @inheritdoc
     * 
     * Namespace strategy:
     * - Creates individual tags for OR matching
     * - Creates composite namespace tags for AND matching
     * - Generates all tag combinations for subset matching support
     */
    public function save($data, $identifier, array $tags = [], $lifeTime = null)
    {
        $cache = $this->getCache();
        // Use _id() for consistency with Zend pattern
        $cleanedId = $this->_id($identifier);
        $item = $cache->getItem($cleanedId);
        $item->set($data);

        // Set expiration time
        if ($lifeTime !== null && $lifeTime !== false) {
            $item->expiresAfter((int)$lifeTime);
        }

        // Handle tags if cache supports it
        if ($this->isTagAware() && !empty($tags)) {
            // Use _tags() for consistency with Zend pattern, but add namespace support
            $processedTags = $this->_tagsWithNamespace($tags);
            
            // Use Symfony's native tagging
            $item->tag($processedTags);
        }

        return $cache->save($item);
    }

    /**
     * @inheritdoc
     */
    public function remove($identifier)
    {
        // Use _id() for consistency with Zend pattern
        return $this->getCache()->deleteItem($this->_id($identifier));
    }

    /**
     * Prepare tags with namespace support (extends _tags() from Core)
     *
     * Mimics Zend's _tags() behavior but adds namespace tags for AND logic:
     * 1. First applies _tags() to normalize and prefix tags (like Zend)
     * 2. Then generates namespace tags for all combinations
     *
     * Performance optimizations:
     * - Pre-cached prefix string
     * - Single loop for both individual and namespace tags
     * - Array flip+keys for deduplication (faster than array_unique)
     *
     * @param array $tags
     * @return array
     */
    protected function _tagsWithNamespace(array $tags): array
    {
        // Normalize and sort first
        $normalizedTags = $this->normalizeTags($tags);
        $tagCount = count($normalizedTags);
        
        // Generate namespace tags once
        $namespaceTags = $this->generateNamespaceTags($normalizedTags);
        
        // Pre-allocate array with estimated size
        $allTags = [];
        $prefix = $this->cachedPrefix;
        
        // Add individual tags with cached prefix
        for ($i = 0; $i < $tagCount; $i++) {
            $allTags[$prefix . $this->cleanCacheIdentifier($normalizedTags[$i])] = true;
        }
        
        // Add namespace tags with cached prefix (array_keys is faster than foreach)
        foreach ($namespaceTags as $nsTag) {
            $allTags[$prefix . $nsTag] = true;
        }
        
        // Return unique keys (faster than array_unique on values)
        return array_keys($allTags);
    }

    /**
     * @inheritdoc
     * 
     * Pure Symfony approach with namespace strategy for AND logic
     *
     * Performance optimizations:
     * - match expression (faster than switch, fewer opcodes)
     * - Early return for non-tag modes
     * - Cache pool fetched only when needed
     * - isTagAware cached result
     */
    public function clean($mode = CacheConstants::CLEANING_MODE_ALL, array $tags = [])
    {
        // Fast path: Early return for modes that don't need cache pool
        return match ($mode) {
            CacheConstants::CLEANING_MODE_OLD, 'old' => true,
            CacheConstants::CLEANING_MODE_NOT_MATCHING_TAG, 'notMatchingTag' => true,
            
            // Modes that need cache pool
            CacheConstants::CLEANING_MODE_ALL, 'all' => $this->getCache()->clear(),
            
            CacheConstants::CLEANING_MODE_MATCHING_TAG, 'matchingTag' => 
                $this->cleanMatchingTag($this->getCache(), $tags, $this->isTagAware()),
            
            CacheConstants::CLEANING_MODE_MATCHING_ANY_TAG, 'matchingAnyTag' => 
                $this->cleanMatchingAnyTag($this->getCache(), $tags, $this->isTagAware()),
            
            default => false,
        };
    }

    /**
     * Get low level frontend (returns the underlying cache pool)
     *
     * @return CacheItemPoolInterface
     */
    public function getLowLevelFrontend()
    {
        return $this->getCache();
    }

    /**
     * Get backend that bypasses frontend logic
     * 
     * Returns a backend wrapper that provides operations without:
     * - Tag prefixing
     * - Frontend decorators
     * - Frontend-specific transformations
     * 
     * This is essential for operations like non-application cache that should
     * not be affected by frontend-level operations like FlushSystem.
     *
     * @return SymfonyBackendWrapper Backend wrapper with save/load/clean/clear methods
     */
    public function getBackend()
    {
        return new SymfonyBackendWrapper($this);
    }

    /**
     * Normalize tags (public access for backend wrapper)
     *
     * Performance optimizations:
     * - array_flip for deduplication (O(n) vs O(n log n))
     * - array_keys to get sorted result
     *
     * @param array $tags
     * @return array
     */
    public function normalizeTags(array $tags): array
    {
        if (empty($tags)) {
            return [];
        }
        
        // Deduplicate using flip (faster than array_unique)
        $unique = array_keys(array_flip($tags));
        sort($unique);
        return $unique;
    }

    /**
     * Build all tags for save operation (public access for backend wrapper)
     *
     * Performance optimizations:
     * - Pre-cached prefix string
     * - Conditional logic outside loop
     * - Array flip+keys for deduplication
     *
     * @param array $normalizedTags Sorted, deduplicated tags
     * @param bool $withPrefix Whether to prefix tags with frontendIdentifier
     * @return array
     */
    public function buildTagsForSave(array $normalizedTags, bool $withPrefix = true): array
    {
        $tagCount = count($normalizedTags);
        $namespaceTags = $this->generateNamespaceTags($normalizedTags);
        $allTags = [];
        
        if ($withPrefix) {
            $prefix = $this->cachedPrefix;
            
            // Add individual tags with prefix
            for ($i = 0; $i < $tagCount; $i++) {
                $allTags[$prefix . $this->cleanCacheIdentifier($normalizedTags[$i])] = true;
            }
            
            // Add namespace tags with prefix
            foreach ($namespaceTags as $nsTag) {
                $allTags[$prefix . $nsTag] = true;
            }
        } else {
            // Add individual tags without prefix
            for ($i = 0; $i < $tagCount; $i++) {
                $allTags[$this->cleanCacheIdentifier($normalizedTags[$i])] = true;
            }
            
            // Add namespace tags without prefix
            foreach ($namespaceTags as $nsTag) {
                $allTags[$nsTag] = true;
            }
        }
        
        // Return unique keys
        return array_keys($allTags);
    }

    /**
     * Create namespace tag (public access for backend wrapper)
     * Exposes trait method publicly for backend wrapper
     *
     * @param array $sortedTags
     * @return string
     */
    public function createNamespaceTag(array $sortedTags): string
    {
        if (empty($sortedTags)) {
            return '';
        }

        return self::NAMESPACE_PREFIX . implode(self::NAMESPACE_SEPARATOR, $sortedTags);
    }

    /**
     * Get cache pool (public access for backend wrapper)
     *
     * @return CacheItemPoolInterface
     */
    public function getCache(): CacheItemPoolInterface
    {
        // Handle process forking
        $currentPid = getmypid();
        if ($currentPid !== $this->pid) {
            if ($this->cacheFactory !== null) {
                // Store old pool to prevent destruction
                $this->parentCachePools[] = $this->cache;
                
                // Create new pool for forked process
                $this->cache = ($this->cacheFactory)();
                $this->pid = $currentPid;
                
                // Reset tag-aware check for new pool
                $this->isTagAware = null;
            }
        }
        
        return $this->cache;
    }

    /**
     * Check if cache supports tag-aware operations (cached)
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
     * Clean cache entries matching ALL given tags (AND logic)
     *
     * Performance optimizations:
     * - Pre-cached prefix string
     * - Single concatenation
     *
     * @param CacheItemPoolInterface $cache
     * @param array $tags
     * @param bool $isTagAware
     * @return bool
     */
    private function cleanMatchingTag(CacheItemPoolInterface $cache, array $tags, bool $isTagAware): bool
    {
        if (empty($tags) || !$isTagAware) {
            return true;
        }
        
        // Normalize tags
        $normalizedTags = $this->normalizeTags($tags);
        
        // Create namespace tag for this exact combination with cached prefix
        $namespaceTag = $this->createNamespaceTag($normalizedTags);
        $prefixedNamespaceTag = $this->cachedPrefix . $namespaceTag;
        
        // Invalidate using the namespace tag - achieves TRUE AND logic!
        return $cache->invalidateTags([$prefixedNamespaceTag]);
    }

    /**
     * Clean cache entries matching ANY given tags (OR logic)
     *
     * Performance optimizations:
     * - Pre-cached prefix string
     * - For loop with count cache
     * - Direct array assignment
     *
     * @param CacheItemPoolInterface $cache
     * @param array $tags
     * @param bool $isTagAware
     * @return bool
     */
    private function cleanMatchingAnyTag(CacheItemPoolInterface $cache, array $tags, bool $isTagAware): bool
    {
        if (empty($tags) || !$isTagAware) {
            return true;
        }

        // Normalize tags
        $normalizedTags = $this->normalizeTags($tags);
        $tagCount = count($normalizedTags);
        
        // Prefix tags with cached prefix
        $prefixedTags = [];
        $prefix = $this->cachedPrefix;
        
        for ($i = 0; $i < $tagCount; $i++) {
            $prefixedTags[] = $prefix . $this->cleanCacheIdentifier($normalizedTags[$i]);
        }
        
        // Use Symfony's OR logic
        return $cache->invalidateTags($prefixedTags);
    }
}
