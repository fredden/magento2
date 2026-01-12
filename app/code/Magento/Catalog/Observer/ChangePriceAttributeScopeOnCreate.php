<?php
/**
 * Copyright 2025 Adobe
 * All Rights Reserved.
 */
declare(strict_types=1);

namespace Magento\Catalog\Observer;

use Magento\Catalog\Api\Data\ProductAttributeInterface;
use Magento\Catalog\Helper\Data;
use Magento\Framework\Event\Observer as EventObserver;
use Magento\Framework\Event\ObserverInterface;
use Magento\Store\Model\Store;

/**
 * Observer is responsible for changing scope for new price attributes in system
 * depending on 'Catalog Price Scope' configuration parameter
 */
class ChangePriceAttributeScopeOnCreate implements ObserverInterface
{
    /**
     * @param Data $catalogData
     */
    public function __construct(
        private Data $catalogData
    ) {
    }

    /**
     * Change scope for price attribute when create
     *
     * @param   EventObserver $observer
     * @return  $this
     */
    public function execute(EventObserver $observer)
    {
        $attribute = $observer->getEvent()->getAttribute();

        // Only set scope if attribute is new, is a price type, and scope hasn't been explicitly set
        if (empty($attribute->getId()) && $attribute->getFrontendInput() == 'price' && $attribute->getScope() == 'store') {
            $scope = $this->catalogData->getPriceScope();
            $scope = ($scope == Store::PRICE_SCOPE_WEBSITE)
                ? ProductAttributeInterface::SCOPE_WEBSITE_TEXT
                : ProductAttributeInterface::SCOPE_GLOBAL_TEXT;
            $attribute->setScope($scope);
        }
        return $this;
    }
}
