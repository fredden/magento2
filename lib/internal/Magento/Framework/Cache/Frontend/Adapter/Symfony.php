<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Magento\Framework\Cache\Frontend\Adapter;

use Magento\Framework\Cache\FrontendInterface;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Cache\Adapter\TagAwareAdapterInterface;

/**
 * Symfony Cache adapter for Magento cache frontend interface
 *
 * Namespace Strategy for AND Logic:
 * - Creates composite "namespace" tags that represent tag combinations
 * - For tags ['A', 'B', 'C'], creates composite tag: 'NS:A:B:C' (sorted)
 * - MATCHING_TAG with ['A', 'B'] searches for items with namespace containing both
 * - Uses Symfony's native invalidateTags() with smart composite tags
 * - Achieves TRUE AND logic without custom tag-to-ID mappings
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
     * Namespace prefix for composite tags
     */
    public const NAMESPACE_PREFIX = 'NS_';

    /**
     * Separator for namespace tag combinations
     * Note: Cannot use ":" as it's reserved by Symfony Cache
     */
    public const NAMESPACE_SEPARATOR = '|';
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
     * @var LoggerInterface|null
     */
    private ?LoggerInterface $logger;

    /**
     * Constructor
     *
     * @param CacheItemPoolInterface $cache
     * @param \Closure|null $cacheFactory Factory to recreate cache pool after fork
     * @param string|null $frontendIdentifier Unique identifier for this frontend (for cache isolation)
     * @param LoggerInterface|null $logger PSR logger for debugging
     */
    public function __construct(
        CacheItemPoolInterface $cache,
        ?\Closure $cacheFactory = null,
        ?string $frontendIdentifier = null,
        ?LoggerInterface $logger = null
    ) {
        $this->cache = $cache;
        $this->cacheFactory = $cacheFactory;
        $this->pid = getmypid();
        $this->frontendIdentifier = $frontendIdentifier ?: 'frontend_' . spl_object_hash($this);
        $this->logger = $logger;
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
     * Namespace strategy:
     * - Creates individual tags for OR matching
     * - Creates composite namespace tags for AND matching
     * - Generates all tag combinations (up to reasonable limit)
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
            // Deduplicate and sort tags
            $uniqueTags = array_values(array_unique($tags));
            sort($uniqueTags); // Sort for consistent namespace generation

            // Prefix individual tags with frontendIdentifier
            $allTags = [];
            foreach ($uniqueTags as $tag) {
                $cleanedTag = $this->cleanIdentifier($tag);
                $allTags[] = $this->frontendIdentifier . '_' . $cleanedTag;
            }

            // Create namespace tags for all combinations (for AND matching)
            // For tags ['A', 'B', 'C'], create: NS:A, NS:B, NS:C, NS:A:B, NS:A:C, NS:B:C, NS:A:B:C
            // This allows MATCHING_TAG with any subset to find matching items
            $namespaceTags = $this->generateNamespaceTags($uniqueTags);
            foreach ($namespaceTags as $nsTag) {
                $allTags[] = $this->frontendIdentifier . '_' . $nsTag;
            }

            // Use Symfony's native tagging with both individual and namespace tags
            $item->tag(array_unique($allTags));
        }

        return $cache->save($item);
    }

    /**
     * Generate all possible namespace tag combinations
     *
     * For tags ['A', 'B', 'C'], generates:
     * - Single: NS:A, NS:B, NS:C
     * - Pairs: NS:A:B, NS:A:C, NS:B:C
     * - Triple: NS:A:B:C
     *
     * @param array $tags Sorted array of tag names
     * @return array Array of namespace tags
     */
    private function generateNamespaceTags(array $tags): array
    {
        $count = count($tags);

        // Limit to prevent combinatorial explosion (max 10 tags = 1023 combinations)
        if ($count > 10) {
            $this->logger->warning('Too many tags for namespace generation', [
                'count' => $count,
                'tags' => $tags
            ]);
            // Fallback: just create the full namespace tag
            return [self::NAMESPACE_PREFIX . implode(self::NAMESPACE_SEPARATOR, $tags)];
        }

        $namespaceTags = [];

        // Generate all non-empty subsets using bit manipulation
        // For n tags, we have 2^n - 1 non-empty subsets
        $totalCombinations = (1 << $count) - 1;

        for ($i = 1; $i <= $totalCombinations; $i++) {
            $combination = [];
            for ($j = 0; $j < $count; $j++) {
                // Check if j-th bit is set
                if ($i & (1 << $j)) {
                    $combination[] = $tags[$j];
                }
            }

            // Create namespace tag (already sorted because tags are sorted)
            $namespaceTags[] = self::NAMESPACE_PREFIX . implode(self::NAMESPACE_SEPARATOR, $combination);
        }

        return $namespaceTags;
    }

    /**
     * Public wrapper for namespace tag generation (used by backend wrapper)
     *
     * @param array $tags Sorted array of tag names
     * @return array Array of namespace tags
     */
    public function generateNamespaceTagsPublic(array $tags): array
    {
        return $this->generateNamespaceTags($tags);
    }


    /**
     * @inheritdoc
     */
    public function remove($identifier)
    {
        $cleanedId = $this->cleanIdentifier($identifier);

        // Note: We don't clean up tag indices here because:
        // 1. We don't know which tags the item had
        // 2. Tag indices are cleaned up during invalidateTags()
        // 3. Stale entries in tag indices are harmless (ID won't exist)

        return $this->getCache()->deleteItem($cleanedId);
    }

    /**
     * @inheritdoc
     *
     * Pure Symfony approach:
     * - Uses native PSR-6 invalidateTags()
     * - Filters decorator tags same as save()
     * - Simple and fast
     */
    public function clean($mode = \Zend_Cache::CLEANING_MODE_ALL, array $tags = [])
    {
        $cache = $this->getCache();
        $isTagAware = $this->isTagAware();

        switch ($mode) {
            case \Zend_Cache::CLEANING_MODE_ALL:
            case 'all':
                // Clear all cache
                return $cache->clear();

            case \Zend_Cache::CLEANING_MODE_MATCHING_TAG:
            case 'matchingTag':
                // Early return if no tags
                if (empty($tags)) {
                    return true;
                }

                if (!$isTagAware) {
                    return true;
                }

                // Deduplicate and sort tags (same as save())
                $uniqueTags = array_values(array_unique($tags));
                sort($uniqueTags);

                // NAMESPACE STRATEGY: Create the namespace tag for this exact combination
                // For tags ['A', 'B'], create 'NS_A|B'
                // This will match items saved with those tags (or superset) due to our save() logic
                $namespaceTag = self::NAMESPACE_PREFIX . implode(self::NAMESPACE_SEPARATOR, $uniqueTags);
                $prefixedNamespaceTag = $this->frontendIdentifier . '_' . $namespaceTag;

                // Invalidate using the namespace tag - achieves TRUE AND logic!
                return $cache->invalidateTags([$prefixedNamespaceTag]);

            case \Zend_Cache::CLEANING_MODE_MATCHING_ANY_TAG:
            case 'matchingAnyTag':
                // Early return if no tags
                if (empty($tags)) {
                    return true;
                }

                if (!$isTagAware) {
                    return true;
                }

                // Deduplicate and prefix tags (same as save())
                $uniqueTags = array_values(array_unique($tags));

                $cleanTags = [];
                foreach ($uniqueTags as $tag) {
                    $cleanedTag = $this->cleanIdentifier($tag);
                    $cleanTags[] = $this->frontendIdentifier . '_' . $cleanedTag;
                }

                // Both MATCHING_TAG and MATCHING_ANY_TAG use OR logic in Symfony
                return $cache->invalidateTags($cleanTags);

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
             * Uses namespace strategy for AND matching
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

                // Use namespace strategy (same as frontend, but without prefix)
                if ($cache instanceof \Symfony\Component\Cache\Adapter\TagAwareAdapterInterface && !empty($tags)) {
                    $uniqueTags = array_values(array_unique($tags));
                    sort($uniqueTags);

                    // Individual tags
                    $allTags = [];
                    foreach ($uniqueTags as $tag) {
                        $allTags[] = $this->adapter->cleanIdentifier($tag);
                    }

                    // Namespace tags for AND matching (no prefix on backend)
                    $namespaceTags = $this->adapter->generateNamespaceTagsPublic($uniqueTags);
                    foreach ($namespaceTags as $nsTag) {
                        $allTags[] = $nsTag;
                    }

                    $item->tag(array_unique($allTags));
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
             * Uses namespace strategy for AND matching
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
                        // Use namespace strategy (same as frontend, without prefix)
                        $uniqueTags = array_values(array_unique($tags));
                        sort($uniqueTags);

                        $namespaceTag = Symfony::NAMESPACE_PREFIX . implode(Symfony::NAMESPACE_SEPARATOR, $uniqueTags);
                        return $cache->invalidateTags([$namespaceTag]);

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

