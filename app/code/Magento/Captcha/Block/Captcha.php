<?php
/**
 * Copyright 2012 Adobe
 * All Rights Reserved.
 */

namespace Magento\Captcha\Block;

/**
 * Captcha block
 *
 * @api
 * @since 100.0.2
 */
class Captcha extends \Magento\Framework\View\Element\Template
{
    /**
     * @var \Magento\Captcha\Helper\Data
     */
    protected $_captchaData = null;

    /**
     * @param \Magento\Framework\View\Element\Template\Context $context
     * @param \Magento\Captcha\Helper\Data $captchaData
     * @param array $data
     */
    public function __construct(
        \Magento\Framework\View\Element\Template\Context $context,
        \Magento\Captcha\Helper\Data $captchaData,
        array $data = []
    ) {
        $this->_captchaData = $captchaData;
        parent::__construct($context, $data);
        $this->_isScopePrivate = true;
    }

    /**
     * Renders captcha HTML (if required)
     *
     * @return string
     */
    protected function _toHtml()
    {
        $blockPath = $this->_captchaData->getCaptcha($this->getFormId())->getBlockName();
        $block = $this->getLayout()->createBlock($blockPath);
        $block->setData($this->getData());
        return $block->toHtml();
    }
}
