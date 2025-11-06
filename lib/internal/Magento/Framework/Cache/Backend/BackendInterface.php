<?php
/**
 * Copyright 2025 Adobe
 * All Rights Reserved.
 */
declare(strict_types=1);

namespace Magento\Framework\Cache\Backend;

use Magento\Framework\Cache\Exception\CacheException;

/**
 * Magento cache backend interface
 *
 * Modern replacement for \Zend_Cache_Backend
 * Defines core cache operations for all backend implementations.
 *
 * @api
 */
interface BackendInterface
{
    /**
     * Test if a cache is available for the given id
     *
     * @param string $id Cache id
     * @return int|false Last modified timestamp of cache entry if it is available, false otherwise
     */
    public function test(string $id);

    /**
     * Load value with given id from cache
     *
     * @param string $id Cache id
     * @param bool $doNotTestCacheValidity If set to true, validity is not tested
     * @return string|false Cached data (string) or false if cache is not available
     */
    public function load(string $id, bool $doNotTestCacheValidity = false);

    /**
     * Save some data in cache
     *
     * @param string $data Data to put in cache (can be another type than string if automatic_serialization is on)
     * @param string $id Cache id (can be an empty string)
     * @param array $tags Array of strings: tags
     * @param int|null $specificLifetime If != null, set a specific lifetime for this cache record (null => infinite lifetime)
     * @return bool True if no problem
     * @throws CacheException
     */
    public function save($data, string $id, array $tags = [], ?int $specificLifetime = null): bool;

    /**
     * Remove a cache record
     *
     * @param string $id Cache id
     * @return bool True if no problem
     */
    public function remove(string $id): bool;

    /**
     * Clean some cache records
     *
     * Available modes are:
     * - 'all' (default)          => remove all cache entries
     * - 'old'                    => remove expired cache entries
     * - 'matchingTag'            => remove entries matching all given tags
     * - 'notMatchingTag'         => remove entries not matching tags
     * - 'matchingAnyTag'         => remove entries matching any given tags
     *
     * @param string $mode Clean mode
     * @param array $tags Array of tags
     * @return bool True if no problem
     * @throws CacheException
     */
    public function clean(string $mode = 'all', array $tags = []): bool;

    /**
     * Set an option
     *
     * @param string $name Option name
     * @param mixed $value Option value
     * @return void
     */
    public function setOption(string $name, $value): void;

    /**
     * Get an option value
     *
     * @param string $name Option name
     * @return mixed Option value or null if not set
     */
    public function getOption(string $name);
}
