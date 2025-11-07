<?php
/**
 * Copyright 2025 Adobe
 * All Rights Reserved.
 */
declare(strict_types=1);

namespace Magento\Framework\Cache\Frontend\Adapter\Symfony;

use Magento\Framework\Cache\CacheConstants;
use Magento\Framework\Cache\Frontend\Adapter\Helper\AdapterHelperInterface;

/**
 * Low-level backend wrapper for Symfony cache adapter
 *
 * Provides backend-level methods for tag operations and cache cleaning
 * Used by tests and utilities that need direct backend access
 */
class LowLevelBackend
{
    /**
     * @var AdapterHelperInterface
     */
    private AdapterHelperInterface $helper;

    /**
     * @param AdapterHelperInterface $helper
     */
    public function __construct(AdapterHelperInterface $helper)
    {
        $this->helper = $helper;
    }

    /**
     * Get IDs matching tags
     *
     * @param array $tags
     * @return array
     */
    public function getIdsMatchingTags(array $tags): array
    {
        // Get IDs from helper (uses backend-specific logic)
        if (method_exists($this->helper, 'getIdsMatchingTags')) {
            return $this->helper->getIdsMatchingTags($tags);
        }
        return [];
    }

    /**
     * Clean cache entries
     *
     * @param string $mode
     * @param array $tags
     * @return bool
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function clean($mode = CacheConstants::CLEANING_MODE_ALL, array $tags = []): bool
    {
        // Backend clean is handled by helper
        if ($mode === CacheConstants::CLEANING_MODE_ALL) {
            if (method_exists($this->helper, 'clearAllTagIndices')) {
                $this->helper->clearAllTagIndices();
            }
        }
        return true;
    }
}
