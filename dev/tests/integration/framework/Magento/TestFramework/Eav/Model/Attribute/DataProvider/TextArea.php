<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Magento\TestFramework\Eav\Model\Attribute\DataProvider;

use Magento\Eav\Model\Entity\Attribute\ScopedAttributeInterface;
use Magento\Store\Model\Store;

/**
 * Product attribute data for attribute with text area input type.
 */
class TextArea extends AbstractBaseAttributeData
{
    /**
     * @inheritdoc
     */
    public static function getAttributeData(): array
    {
        if(isset(static::$defaultAttributePostData['serialized_options_arr'])) {
            unset(static::$defaultAttributePostData['serialized_options_arr']);
        }
        return array_replace_recursive(
            parent::getAttributeData(),
            [
                "{static::getFrontendInput()}_with_default_value" => [
                    [
                        'default_value_text' => '',
                        'default_value_textarea' => 'Default attribute value',
                    ],
                ],
            ]
        );
    }

    /**
     * @inheritdoc
     */
    public static function getUpdateProvider(): array
    {
        if(isset(static::$defaultAttributePostData['serialized_options_arr'])) {
            unset(static::$defaultAttributePostData['serialized_options_arr']);
        }
        $frontendInput = static::getFrontendInput();
        return array_replace_recursive(
            parent::getUpdateProvider(),
            [
                "{$frontendInput}_other_attribute_code" => [
                    'postData' => [
                        'attribute_code' => 'text_attribute_update',
                    ],
                    'expectedData' => [
                        'attribute_code' => 'text_attribute',
                    ],
                ],
                "{$frontendInput}_change_frontend_input" => [
                    'postData' => [
                        'frontend_input' => 'texteditor',
                    ],
                    'expectedData' => [
                        'frontend_input' => 'textarea',
                        'is_wysiwyg_enabled' => '1'
                    ],
                ],
            ]
        );
    }

    /**
     * @inheritdoc
     */
    protected static function getFrontendInput(): string
    {
        return 'textarea';
    }

    /**
     * @inheritdoc
     */
    protected static function getUpdatePostData(): array
    {
        return [
            'frontend_label' => [
                Store::DEFAULT_STORE_ID => 'Text Attribute Update',
            ],
            'frontend_input' => 'textarea',
            'is_required' => '1',
            'is_global' => ScopedAttributeInterface::SCOPE_WEBSITE,
            'default_value_textarea' => 'Text Attribute Default',
            'is_unique' => '1',
            'is_used_in_grid' => '1',
            'is_visible_in_grid' => '1',
            'is_filterable_in_grid' => '1',
            'is_searchable' => '1',
            'search_weight' => '2',
            'is_visible_in_advanced_search' => '1',
            'is_comparable' => '1',
            'is_used_for_promo_rules' => '1',
            'is_html_allowed_on_front' => '1',
            'is_visible_on_front' => '1',
            'used_in_product_listing' => '1',
        ];
    }

    /**
     * @inheritdoc
     */
    protected static function getUpdateExpectedData(): array
    {
        $updatePostData = static::getUpdatePostData();
        unset($updatePostData['default_value_textarea']);
        return array_merge(
            $updatePostData,
            [
                'frontend_label' => 'Text Attribute Update',
                'attribute_code' => 'text_attribute',
                'default_value' => 'Text Attribute Default',
                'frontend_class' => null,
                'is_filterable' => '0',
                'is_filterable_in_search' => '0',
                'position' => '0',
                'used_for_sort_by' => '0',
                'is_user_defined' => '1',
                'backend_type' => 'text',
                'is_wysiwyg_enabled' => '0',
            ]
        );
    }
}
