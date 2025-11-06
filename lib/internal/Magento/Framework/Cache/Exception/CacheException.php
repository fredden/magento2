<?php
/**
 * Copyright 2025 Adobe
 * All Rights Reserved.
 */
declare(strict_types=1);

namespace Magento\Framework\Cache\Exception;

use Magento\Framework\Exception\LocalizedException;

/**
 * Cache exception - Symfony-compatible alternative to Zend_Cache::throwException()
 *
 * This exception replaces the legacy Zend_Cache::throwException() static method
 * with a modern exception class compatible with Symfony Cache and PSR standards.
 *
 * Usage:
 * Instead of: Zend_Cache::throwException('Error message');
 * Use:        throw new CacheException(__('Error message'));
 *
 * @api
 */
class CacheException extends LocalizedException
{
}

