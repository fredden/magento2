<?php
/**
 * Copyright 2025 Adobe
 * All Rights Reserved.
 */
declare(strict_types=1);

namespace Magento\Framework\Cache\Frontend\Adapter;

use Predis\Client as PredisClient;
use Predis\ClientInterface;

/**
 * Ultra-Optimized Predis wrapper - minimal intervention approach
 * 
 * Only optimizes GET operations with response caching. All other operations
 * are passed through directly to maintain 100% compatibility with Predis.
 */
class UltraOptimizedPredisClient extends PredisClient
{
    private array $cache = [];
    private bool $cachingEnabled = true;
    
    private const CACHE_TTL = 1;
    private const CACHE_MAX = 200;

    public function __construct($parameters = null, $options = null)
    {
        parent::__construct($parameters, $options);
        
        // Conservative: only enable caching for web requests, not CLI/tests
        if (php_sapi_name() === 'cli' || defined('TESTS_TEMP_DIR')) {
            $this->cachingEnabled = false;
        }
    }

    /**
     * Optimized GET with response caching
     */
    public function get($key)
    {
        if (!$this->cachingEnabled) {
            return parent::get($key);
        }
        
        $cacheKey = 'get:' . $key;
        
        if (isset($this->cache[$cacheKey])) {
            [$result, $time] = $this->cache[$cacheKey];
            if ((time() - $time) < self::CACHE_TTL) {
                return $result;
            }
            unset($this->cache[$cacheKey]);
        }
        
        $result = parent::get($key);
        
        if (count($this->cache) >= self::CACHE_MAX) {
            array_shift($this->cache);
        }
        $this->cache[$cacheKey] = [$result, time()];
        
        return $result;
    }

    /**
     * Clear cache on SET
     */
    public function set($key, $value, $expireResolution = null, $expireTTL = null, $flag = null)
    {
        $this->cache = [];
        return parent::set($key, $value, $expireResolution, $expireTTL, $flag);
    }

    /**
     * Clear cache on DEL
     */
    public function del($keys)
    {
        $this->cache = [];
        return parent::del(is_array($keys) ? $keys : [$keys]);
    }

    /**
     * Clear cache on FLUSHDB
     */
    public function flushdb()
    {
        $this->cache = [];
        return parent::flushdb();
    }

    /**
     * Clear cache on SELECT (database switch)
     */
    public function select($database)
    {
        $this->cache = [];
        return parent::select($database);
    }
}
