<?php
/**
 * Copyright 2025 Adobe
 * All Rights Reserved.
 */

namespace Magento\Backend\Cron;

use Magento\Framework\App\Cache\TypeListInterface;

class RefreshInvalidatedCaches
{
    /**
     * @param TypeListInterface $typeList
     */
    public function __construct(
        private readonly TypeListInterface $typeList,
    ) {
        $this->typeList = $typeList;
    }

    /**
     * Entry point for cronjob 'backend_refresh_invalidated_caches'
     */
    public function execute(): void
    {
        foreach ($this->typeList->getInvalidated() as $cache) {
            $this->typeList->cleanType($cache->getId());
        }
    }
}
