<?php
/**
 * Copyright 2012 Adobe
 * All Rights Reserved.
 */

/**
 * Captcha image model
 */
namespace Magento\Captcha\Model\Config;

class Mode implements \Magento\Framework\Option\ArrayInterface
{
    /**
     * Get options for captcha mode selection field
     *
     * @return array
     */
    public function toOptionArray()
    {
        return [
            ['label' => __('Always'), 'value' => \Magento\Captcha\Helper\Data::MODE_ALWAYS],
            [
                'label' => __('After number of attempts to login'),
                'value' => \Magento\Captcha\Helper\Data::MODE_AFTER_FAIL
            ]
        ];
    }
}
