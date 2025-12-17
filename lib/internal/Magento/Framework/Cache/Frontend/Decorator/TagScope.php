<?php
/**
 * Copyright 2011 Adobe
 * All Rights Reserved.
 */
declare(strict_types=1);

namespace Magento\Framework\Cache\Frontend\Decorator;

use Magento\Framework\Cache\CacheConstants;

/**
 * Cache frontend decorator that limits the cleaning scope within a tag
 *
 * @api
 * @since 100.0.2
 */
class TagScope extends \Magento\Framework\Cache\Frontend\Decorator\Bare
{
    /**
     * Tag to associate cache entries with
     *
     * @var string
     */
    private $_tag;

    /**
     * @param \Magento\Framework\Cache\FrontendInterface $frontend
     * @param string $tag Cache tag name
     */
    public function __construct(\Magento\Framework\Cache\FrontendInterface $frontend, $tag)
    {
        parent::__construct($frontend);
        $this->_tag = $tag;
    }

    /**
     * Retrieve cache tag name
     *
     * @return string
     */
    public function getTag()
    {
        return $this->_tag;
    }

    /**
     * @inheritDoc
     *
     * Enforce marking with a tag
     */
    public function save($data, $identifier, array $tags = [], $lifeTime = null)
    {
        $tags[] = $this->getTag();
        return parent::save($data, $identifier, $tags, $lifeTime);
    }

    /**
     * @inheritDoc
     *
     * Limit the cleaning scope within a tag
     */
    public function clean($mode = CacheConstants::CLEANING_MODE_ALL, array $tags = [])
    {
        if ($mode == CacheConstants::CLEANING_MODE_MATCHING_ANY_TAG) {
            // OPTIMIZATION: Check if underlying frontend supports optimized scope cleaning
            // This avoids looping through each tag (N×SINTER) and instead uses SUNION + filter (2 calls)
            $frontend = $this->_getFrontend();

            // Unwrap decorators to get the actual implementation
            if (method_exists($frontend, 'getLowLevelFrontend')) {
                $frontend = $frontend->getLowLevelFrontend();
            }

            // Check if it's Symfony cache (either direct or LowLevelFrontend wrapper)
            $adapter = null;

            if ($frontend instanceof \Magento\Framework\Cache\Frontend\Adapter\Symfony) {
                $adapter = $frontend->getAdapter();
            } elseif ($frontend instanceof \Magento\Framework\Cache\Frontend\Adapter\Symfony\LowLevelFrontend) {
                // LowLevelFrontend has direct access to adapter via reflection or public method
                // Check if there's a public getAdapter method
                if (method_exists($frontend, 'getAdapter')) {
                    $adapter = $frontend->getAdapter();
                } else {
                    // Access via reflection if needed
                    try {
                        $reflection = new \ReflectionClass($frontend);
                        if ($reflection->hasProperty('adapter')) {
                            $property = $reflection->getProperty('adapter');
                            $property->setAccessible(true);
                            $adapter = $property->getValue($frontend);
                        }
                    } catch (\Exception $e) {
                        // Reflection failed, fall back to loop
                        $adapter = null;
                    }
                }
            }

            // Check if adapter supports the optimized method
            if ($adapter && method_exists($adapter, 'getIdsMatchingAnyTagsAndScope')) {
                // Use optimized path: Get IDs matching (any tag) AND (scope tag)
                $ids = $adapter->getIdsMatchingAnyTagsAndScope($tags, $this->getTag());

                if (!empty($ids)) {
                    // Delete the matching IDs
                    return $adapter->deleteByIds($ids);
                }

                return true;
            }

            // Fallback to original implementation (N×SINTER)
            $result = false;
            foreach ($tags as $tag) {
                if (parent::clean(CacheConstants::CLEANING_MODE_MATCHING_TAG, [$tag, $this->getTag()])) {
                    $result = true;
                }
            }
            return $result;
        } else {
            if ($mode == CacheConstants::CLEANING_MODE_ALL) {
                $mode = CacheConstants::CLEANING_MODE_MATCHING_TAG;
                $tags = [$this->getTag()];
            } else {
                $tags[] = $this->getTag();
            }
            $result = parent::clean($mode, $tags);
        }
        return $result;
    }
}
