<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Magento\Framework\Cache\Frontend\Adapter\Helper;

use Psr\Cache\CacheItemPoolInterface;

/**
 * Filesystem-specific adapter helper
 * 
 * Implements tag-to-ID index management using filesystem, similar to Colin Mollenhour's
 * Cm_Cache_Backend_File implementation. This enables true AND logic for MATCHING_TAG mode
 * using PHP array_intersect operation.
 * 
 * Storage structure:
 * - Cache items: var/cache/symfony/items/id
 * - Tag indices: var/cache/symfony/tags/tagname (one ID per line)
 * 
 * Example:
 * - Item 'config_1' with tags ['config', 'eav']
 * - Files:
 *   - var/cache/symfony/items/config_1
 *   - var/cache/symfony/tags/config:
 *       config_1
 *       config_2
 *   - var/cache/symfony/tags/eav:
 *       config_1
 *       eav_1
 * 
 * MATCHING_TAG(['config', 'eav']):
 * - Read var/cache/symfony/tags/config => ['config_1', 'config_2']
 * - Read var/cache/symfony/tags/eav => ['config_1', 'eav_1']
 * - array_intersect() => ['config_1']  ← Only IDs in BOTH (true AND logic)
 */
class FilesystemAdapterHelper implements AdapterHelperInterface
{
    private CacheItemPoolInterface $cachePool;
    private string $tagDirectory;

    /**
     * @param CacheItemPoolInterface $cachePool
     * @param string $tagDirectory Directory to store tag index files
     */
    public function __construct(CacheItemPoolInterface $cachePool, string $tagDirectory)
    {
        $this->cachePool = $cachePool;
        $this->tagDirectory = rtrim($tagDirectory, '/') . '/tags/';
        
        // Ensure tag directory exists
        if (!is_dir($this->tagDirectory)) {
            mkdir($this->tagDirectory, 0770, true);
        }
    }

    /**
     * Get tag file path
     * 
     * @param string $tag
     * @return string
     */
    private function getTagFile(string $tag): string
    {
        return $this->tagDirectory . $tag;
    }

    /**
     * Read IDs from a tag file
     * 
     * @param string $tag
     * @return array
     */
    private function getTagIds(string $tag): array
    {
        $file = $this->getTagFile($tag);
        
        if (!file_exists($file)) {
            return [];
        }

        $content = @file_get_contents($file);
        if ($content === false || $content === '') {
            return [];
        }

        // IDs are stored one per line
        $ids = trim(substr($content, 0, strrpos($content, "\n") ?: strlen($content)));
        return $ids !== '' ? explode("\n", $ids) : [];
    }

    /**
     * Write IDs to a tag file
     * 
     * @param string $tag
     * @param array $ids
     * @return void
     */
    private function setTagIds(string $tag, array $ids): void
    {
        $file = $this->getTagFile($tag);
        
        if (empty($ids)) {
            // Remove tag file if no IDs
            @unlink($file);
            return;
        }

        // Write IDs, one per line, with trailing newline
        $content = implode("\n", $ids) . "\n";
        file_put_contents($file, $content, LOCK_EX);
    }

    /**
     * Add ID to a tag file
     * 
     * @param string $tag
     * @param string $id
     * @return void
     */
    private function addIdToTag(string $tag, string $id): void
    {
        $ids = $this->getTagIds($tag);
        if (!in_array($id, $ids, true)) {
            $ids[] = $id;
            $this->setTagIds($tag, $ids);
        }
    }

    /**
     * Remove ID from a tag file
     * 
     * @param string $tag
     * @param string $id
     * @return void
     */
    private function removeIdFromTag(string $tag, string $id): void
    {
        $ids = $this->getTagIds($tag);
        $key = array_search($id, $ids, true);
        
        if ($key !== false) {
            unset($ids[$key]);
            $this->setTagIds($tag, array_values($ids));
        }
    }

    /**
     * {@inheritdoc}
     * 
     * Uses array_intersect for true AND logic (similar to Colin Mollenhour's File backend)
     */
    public function getIdsMatchingTags(array $tags): array
    {
        if (empty($tags)) {
            return [];
        }

        // Get IDs for first tag
        $tag = array_shift($tags);
        $ids = $this->getTagIds($tag);

        // Intersect with remaining tags (AND logic)
        foreach ($tags as $tag) {
            if (empty($ids)) {
                break; // Early termination optimization
            }
            $ids = array_intersect($ids, $this->getTagIds($tag));
        }

        return array_values(array_unique($ids));
    }

    /**
     * {@inheritdoc}
     * 
     * Uses array_merge for OR logic
     */
    public function getIdsMatchingAnyTags(array $tags): array
    {
        if (empty($tags)) {
            return [];
        }

        $ids = [];
        foreach ($tags as $tag) {
            $ids = array_merge($ids, $this->getTagIds($tag));
        }

        return array_values(array_unique($ids));
    }

    /**
     * {@inheritdoc}
     * 
     * Gets all IDs and removes those matching any of the given tags
     */
    public function getIdsNotMatchingTags(array $tags): array
    {
        if (empty($tags)) {
            // Return all IDs
            return $this->getAllIds();
        }

        // Get all IDs
        $allIds = $this->getAllIds();

        // Get IDs matching any tag
        $matchingIds = $this->getIdsMatchingAnyTags($tags);

        // Return difference
        return array_values(array_diff($allIds, $matchingIds));
    }

    /**
     * Get all cache IDs from all tag files
     * 
     * @return array
     */
    private function getAllIds(): array
    {
        $allIds = [];
        $tagFiles = glob($this->tagDirectory . '*');
        
        if ($tagFiles === false) {
            return [];
        }

        foreach ($tagFiles as $file) {
            if (is_file($file)) {
                $tag = basename($file);
                $ids = $this->getTagIds($tag);
                $allIds = array_merge($allIds, $ids);
            }
        }

        return array_values(array_unique($allIds));
    }

    /**
     * {@inheritdoc}
     */
    public function deleteByIds(array $ids): bool
    {
        if (empty($ids)) {
            return true;
        }

        return $this->cachePool->deleteItems($ids);
    }

    /**
     * {@inheritdoc}
     * 
     * Maintains tag-to-ID indices in filesystem
     */
    public function onSave(string $id, array $tags): void
    {
        if (empty($tags)) {
            return;
        }

        // Add ID to each tag file
        foreach ($tags as $tag) {
            $this->addIdToTag($tag, $id);
        }
    }

    /**
     * {@inheritdoc}
     * 
     * Removes ID from all tag files
     */
    public function onRemove(string $id): void
    {
        // We need to scan all tag files and remove this ID
        $tagFiles = glob($this->tagDirectory . '*');
        
        if ($tagFiles === false) {
            return;
        }

        foreach ($tagFiles as $file) {
            if (is_file($file)) {
                $tag = basename($file);
                $this->removeIdFromTag($tag, $id);
            }
        }
    }

    /**
     * {@inheritdoc}
     */
    public function clearAllIndices(): void
    {
        // Remove all tag files
        $tagFiles = glob($this->tagDirectory . '*');
        
        if ($tagFiles === false) {
            return;
        }

        foreach ($tagFiles as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
    }
}

