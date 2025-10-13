<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Magento\Framework\Cache;

/**
 * Cache cleaning mode constants
 * 
 * Defines standard cache cleaning modes for all cache implementations
 */
class CacheConstants
{
    /**
     * Remove all cache entries
     */
    public const CLEANING_MODE_ALL = 'all';

    /**
     * Remove cache entries matching ALL given tags (AND logic)
     */
    public const CLEANING_MODE_MATCHING_TAG = 'matchingTag';

    /**
     * Remove cache entries matching ANY given tags (OR logic)
     */
    public const CLEANING_MODE_MATCHING_ANY_TAG = 'matchingAnyTag';

    /**
     * Remove cache entries NOT matching one of the given tags
     */
    public const CLEANING_MODE_NOT_MATCHING_TAG = 'notMatchingTag';

    /**
     * Remove expired cache entries
     */
    public const CLEANING_MODE_OLD = 'old';
}

