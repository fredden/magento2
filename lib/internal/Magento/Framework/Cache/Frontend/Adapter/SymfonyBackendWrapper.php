<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Magento\Framework\Cache\Frontend\Adapter;

use Magento\Framework\Cache\CacheConstants;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\Cache\Adapter\TagAwareAdapterInterface;

/**
 * Backend wrapper for Symfony cache adapter
 * 
 * Provides backend-level operations that bypass frontend logic:
 * - No tag prefixing with frontendIdentifier
 * - No frontend decorators
 * - No frontend-specific transformations
 * 
 * This is essential for operations like non-application cache that should
 * not be affected by frontend-level operations like FlushSystem.
 */
class SymfonyBackendWrapper
{
    /**
     * @var Symfony
     */
    private Symfony $adapter;

    /**
     * Constructor
     *
     * @param Symfony $adapter
     */
    public function __construct(Symfony $adapter)
    {
        $this->adapter = $adapter;
    }

    /**
     * Get cache pool
     *
     * @return CacheItemPoolInterface
     */
    private function getCache(): CacheItemPoolInterface
    {
        return $this->adapter->getCache();
    }

    /**
     * Clean identifier (helper for backend operations)
     *
     * @param string|null $id
     * @return string|null
     */
    private function cleanIdentifier(?string $id): ?string
    {
        if ($id === null) {
            return null;
        }
        // Directly clean without prefix (backend level)
        return str_replace('.', '__', preg_replace('/[^a-zA-Z0-9_]/', '_', $id));
    }

    /**
     * Save without prefixing tags (backend-level save)
     * 
     * Uses cleaned ID (no prefix) and namespace tags (no prefix)
     *
     * @param mixed $data
     * @param string $id
     * @param array $tags
     * @param int|null $specificLifetime
     * @return bool
     */
    public function save($data, $id, $tags = [], $specificLifetime = null)
    {
        $cache = $this->getCache();
        // Clean ID but don't prefix (backend level)
        $cleanedId = $this->cleanIdentifier($id);
        $item = $cache->getItem($cleanedId);
        $item->set($data);

        if ($specificLifetime !== null && $specificLifetime !== false) {
            $item->expiresAfter((int)$specificLifetime);
        }

        // Use namespace strategy (no prefix for backend)
        if ($cache instanceof TagAwareAdapterInterface && !empty($tags)) {
            $normalizedTags = $this->adapter->normalizeTags($tags);

            // Add individual tags (no prefix)
            $allTags = [];
            foreach ($normalizedTags as $tag) {
                $allTags[] = $this->cleanIdentifier($tag);
            }

            // Add namespace tags (no prefix)
            $namespaceTags = $this->adapter->generateNamespaceTags($normalizedTags);
            foreach ($namespaceTags as $nsTag) {
                $allTags[] = $nsTag;
            }

            $item->tag(array_unique($allTags));
        }

        return $cache->save($item);
    }

    /**
     * Load directly by ID
     *
     * @param string $id
     * @return mixed|false
     */
    public function load($id)
    {
        return $this->adapter->load($id);
    }

    /**
     * Clean without prefixing tags (backend-level clean)
     *
     * Performance: Uses match expression (faster than switch)
     *
     * @param string $mode
     * @param array $tags
     * @return bool
     */
    public function clean($mode = CacheConstants::CLEANING_MODE_ALL, array $tags = [])
    {
        return match ($mode) {
            CacheConstants::CLEANING_MODE_ALL, 'all' => 
                $this->getCache()->clear(),
            
            CacheConstants::CLEANING_MODE_OLD, 'old' => true,
            
            CacheConstants::CLEANING_MODE_MATCHING_TAG, 'matchingTag' => 
                $this->cleanMatchingTagBackend($tags),
            
            CacheConstants::CLEANING_MODE_NOT_MATCHING_TAG, 'notMatchingTag',
            CacheConstants::CLEANING_MODE_MATCHING_ANY_TAG, 'matchingAnyTag' => true,
            
            default => false,
        };
    }

    /**
     * Clean matching tag (backend-level, no prefix)
     *
     * @param array $tags
     * @return bool
     */
    private function cleanMatchingTagBackend(array $tags): bool
    {
        $cache = $this->getCache();
        
        if (empty($tags) || !($cache instanceof TagAwareAdapterInterface)) {
            return true;
        }

        // Use namespace strategy (no prefix)
        $normalizedTags = $this->adapter->normalizeTags($tags);
        $namespaceTag = $this->adapter->createNamespaceTag($normalizedTags);

        return $cache->invalidateTags([$namespaceTag]);
    }

    /**
     * Clear entire backend
     *
     * @return bool
     */
    public function clear()
    {
        return $this->getCache()->clear();
    }
}

