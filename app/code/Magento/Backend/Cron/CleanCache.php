<?php
/**
 * Copyright 2015 Adobe
 * All Rights Reserved.
 */
namespace Magento\Backend\Cron;

/**
 * Backend event observer
 */
class CleanCache
{
    /**
     * @var \Magento\Framework\App\Cache\Frontend\Pool
     */
    private $cacheFrontendPool;

    /**
     * @param \Magento\Framework\App\Cache\Frontend\Pool $cacheFrontendPool
     */
    public function __construct(
        \Magento\Framework\App\Cache\Frontend\Pool $cacheFrontendPool
    ) {
        $this->cacheFrontendPool = $cacheFrontendPool;
    }

    /**
     * Cron job method to clean old cache resources
     *
     * @return void
     */
    public function execute()
    {
        /** @var $cacheFrontend \Magento\Framework\Cache\FrontendInterface */
        foreach ($this->cacheFrontendPool as $cacheFrontend) {
            // Clean old/expired cache entries - Symfony cache handles this automatically
            $cacheFrontend->clean(\Zend_Cache::CLEANING_MODE_OLD);
        }
    }
}
