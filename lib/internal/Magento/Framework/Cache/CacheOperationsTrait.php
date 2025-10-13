<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Magento\Framework\Cache;

/**
 * Common cache operations shared across implementations
 * 
 * Provides optimized identifier and tag processing
 */
trait CacheOperationsTrait
{
    /**
     * Cache for cleaned identifiers (performance optimization)
     *
     * @var array
     */
    private array $cleanedIdentifierCache = [];

    /**
     * Maximum size of identifier cache to prevent memory bloat
     */
    private const IDENTIFIER_CACHE_MAX_SIZE = 1000;

    /**
     * Clean and normalize cache identifier
     *
     * Performance optimizations:
     * - Cached results for repeated calls
     * - Single regex operation
     * - Early returns for null/empty
     *
     * @param string|null $identifier
     * @return string|null
     */
    protected function cleanCacheIdentifier(?string $identifier): ?string
    {
        if ($identifier === null || $identifier === '') {
            return $identifier;
        }

        // Check cache first
        if (isset($this->cleanedIdentifierCache[$identifier])) {
            return $this->cleanedIdentifierCache[$identifier];
        }

        // Normalize: dots to underscores
        $cleaned = str_replace('.', '__', $identifier);
        
        // Remove invalid characters (PSR-6 reserved: {}()/\@:)
        $cleaned = preg_replace('/[^a-zA-Z0-9_]/', '_', $cleaned);

        // Cache the result (with size limit)
        if (count($this->cleanedIdentifierCache) < self::IDENTIFIER_CACHE_MAX_SIZE) {
            $this->cleanedIdentifierCache[$identifier] = $cleaned;
        }

        return $cleaned;
    }

    /**
     * Clean and normalize multiple tags
     *
     * @param array $tags
     * @return array
     */
    protected function cleanTags(array $tags): array
    {
        $cleaned = [];
        foreach ($tags as $tag) {
            $cleanedTag = $this->cleanCacheIdentifier($tag);
            if ($cleanedTag !== null && $cleanedTag !== '') {
                $cleaned[] = $cleanedTag;
            }
        }
        return $cleaned;
    }

    /**
     * Deduplicate and sort tags for consistent processing
     *
     * @param array $tags
     * @return array
     */
    protected function normalizeTags(array $tags): array
    {
        $unique = array_values(array_unique($tags));
        sort($unique);
        return $unique;
    }

    /**
     * Check if array is empty (including nested empty arrays)
     *
     * @param array $array
     * @return bool
     */
    protected function isArrayEmpty(array $array): bool
    {
        return empty(array_filter($array));
    }
}

