<?php
/**
 * Copyright 2025 Adobe
 * All Rights Reserved.
 */

use Magento\Framework\App\ResourceConnection;
use Magento\TestFramework\Helper\Bootstrap;
use Magento\UrlRewrite\Model\OptionProvider;
use Magento\UrlRewrite\Model\ResourceModel\UrlRewrite as UrlRewriteResource;
use Magento\UrlRewrite\Model\UrlRewrite;

/** @var \Magento\Framework\ObjectManagerInterface $objectManager */
$objectManager = Bootstrap::getObjectManager();

/** @var UrlRewriteResource $rewriteResource */
$rewriteResource = $objectManager->create(UrlRewriteResource::class);

/** @var ResourceConnection $resource */
$resource = $objectManager->get(ResourceConnection::class);
$connection = $resource->getConnection();

$storeId = 1;

// Test data with 4-byte UTF-8 characters (emojis)
$testData = [
    [
        'entity_type' => 'custom',
        'request_path' => 'search/🔎/products',
        'target_path' => 'catalog/search/results',
        'redirect_type' => OptionProvider::PERMANENT,
        'store_id' => $storeId,
        'description' => 'Search with magnifying glass emoji'
    ],
    [
        'entity_type' => 'custom',
        'request_path' => 'celebrate/🎉',
        'target_path' => 'cms/party',
        'redirect_type' => OptionProvider::PERMANENT,
        'store_id' => $storeId,
        'description' => 'Party popper emoji'
    ],
    [
        'entity_type' => 'custom',
        'request_path' => 'emoji/😀/happy',
        'target_path' => 'cms/happiness',
        'redirect_type' => OptionProvider::PERMANENT,
        'store_id' => $storeId,
        'description' => 'Grinning face emoji'
    ],
    [
        'entity_type' => 'custom',
        'request_path' => 'home/🏠',
        'target_path' => 'cms/index/index',
        'redirect_type' => OptionProvider::PERMANENT,
        'store_id' => $storeId,
        'description' => 'House emoji'
    ],
    [
        'entity_type' => 'custom',
        'request_path' => 'math/𝕳𝖊𝖑𝖑𝖔',
        'target_path' => 'cms/math/hello',
        'redirect_type' => OptionProvider::PERMANENT,
        'store_id' => $storeId,
        'description' => 'Mathematical alphanumeric symbols'
    ],
    [
        'entity_type' => 'custom',
        'request_path' => 'special/café',
        'target_path' => 'cms/cafe',
        'redirect_type' => OptionProvider::PERMANENT,
        'store_id' => $storeId,
        'description' => 'Accented characters (3-byte UTF-8)'
    ],
    [
        'entity_type' => 'custom',
        'request_path' => 'chinese/你好',
        'target_path' => 'cms/hello',
        'redirect_type' => OptionProvider::PERMANENT,
        'store_id' => $storeId,
        'description' => 'Chinese characters (3-byte UTF-8)'
    ],
];

// Insert test data
foreach ($testData as $data) {
    $rewrite = $objectManager->create(UrlRewrite::class);
    $rewrite->setEntityType($data['entity_type'])
        ->setRequestPath($data['request_path'])
        ->setTargetPath($data['target_path'])
        ->setRedirectType($data['redirect_type'])
        ->setStoreId($data['store_id'])
        ->setDescription($data['description']);
    $rewriteResource->save($rewrite);
}

