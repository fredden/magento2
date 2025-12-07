<?php
/**
 * Copyright 2025 Adobe
 * All Rights Reserved.
 */

use Magento\Framework\App\ResourceConnection;
use Magento\TestFramework\Helper\Bootstrap;
use Magento\UrlRewrite\Service\V1\Data\UrlRewrite;

/** @var \Magento\Framework\ObjectManagerInterface $objectManager */
$objectManager = Bootstrap::getObjectManager();

/** @var ResourceConnection $resource */
$resource = $objectManager->get(ResourceConnection::class);
$connection = $resource->getConnection();

// Delete test URL rewrites with 4-byte UTF-8 characters
$requestPaths = [
    'search/🔎/products',
    'celebrate/🎉',
    'emoji/😀/happy',
    'home/🏠',
    'math/𝕳𝖊𝖑𝖑𝖔',
    'special/café',
    'chinese/你好',
];

$connection->delete(
    $resource->getTableName('url_rewrite'),
    [
        UrlRewrite::REQUEST_PATH . ' IN (?)' => $requestPaths
    ]
);

