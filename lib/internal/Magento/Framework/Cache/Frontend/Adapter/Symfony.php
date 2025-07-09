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
 */
class Symfony implements FrontendInterface
{
    private CacheItemPoolInterface $cache;

    /**
     * Symfony constructor.
     *
     * @param CacheItemPoolInterface $cache
     */
    public function __construct(CacheItemPoolInterface $cache)
    {
        $this->cache = $cache;
    }

    /**
     * Clean identifier from reserved characters.
     *
     * @param string $identifier
     * @return string
     */
    private function cleanIdentifier(string $identifier): string
    {
        // PSR-6 reserved characters: {}()/\@:
        return preg_replace('/[{}()\/\\\\@:]/', '_', $identifier);
    }

    /**
     * {@inheritdoc}
     */
    public function test($identifier)
    {
        $item = $this->cache->getItem($this->cleanIdentifier($identifier));
        return $item->isHit() ? ($item->getMetadata()['mtime'] ?? time()) : false;
    }

    /**
     * {@inheritdoc}
     */
    public function load($identifier)
    {
        $item = $this->cache->getItem($this->cleanIdentifier($identifier));
        return $item->isHit() ? $item->get() : false;
    }

    /**
     * {@inheritdoc}
     */
    public function save($data, $identifier, $tags = [], $specificLifetime = false)
    {
        $item = $this->cache->getItem($this->cleanIdentifier($identifier));
        $item->set($data);

        if ($specificLifetime !== false) {
            $item->expiresAfter($specificLifetime);
        }

        if ($this->cache instanceof TagAwareAdapterInterface && !empty($tags)) {
            $item->tag(array_map([$this, 'cleanIdentifier'], $tags));
        }

        return $this->cache->save($item);
    }

    /**
     * {@inheritdoc}
     */
    public function remove($identifier)
    {
        return $this->cache->deleteItem($this->cleanIdentifier($identifier));
    }

    /**
     * {@inheritdoc}
     */
    public function clean($mode = FrontendInterface::CLEANING_MODE_ALL, array $tags = [])
    {
        $cleanedTags = array_map([$this, 'cleanIdentifier'], $tags);
        switch ($mode) {
            case FrontendInterface::CLEANING_MODE_ALL:
                return $this->cache->clear();
            case FrontendInterface::CLEANING_MODE_MATCHING_TAG:
            case FrontendInterface::CLEANING_MODE_MATCHING_ANY_TAG:
                if ($this->cache instanceof TagAwareAdapterInterface && !empty($cleanedTags)) {
                    return $this->cache->invalidateTags($cleanedTags);
                }
                if (!empty($cleanedTags)) {
                    return $this->cache->clear();
                }
                return true;
            default:
                return false;
        }
    }

    /**
     * {@inheritdoc}
     */
    public function getBackend()
    {
        return $this->cache;
    }

    /**
     * {@inheritdoc}
     */
    public function getLowLevelFrontend()
    {
        return $this->cache;
    }
}
