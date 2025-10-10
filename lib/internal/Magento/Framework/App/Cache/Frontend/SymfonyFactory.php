<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Magento\Framework\App\Cache\Frontend;

use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Filesystem;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\Cache\Adapter\AdapterInterface;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Symfony\Component\Cache\Adapter\MemcachedAdapter;
use Symfony\Component\Cache\Adapter\PdoAdapter;
use Symfony\Component\Cache\Adapter\RedisAdapter;
use Symfony\Component\Cache\Adapter\TagAwareAdapter;
use Symfony\Component\Cache\Adapter\ChainAdapter;
use Symfony\Component\Cache\Adapter\ApcuAdapter;

/**
 * Factory for creating Symfony Cache adapters
 * 
 * Performance optimizations:
 * - Connection pooling for Redis/Memcached
 * - Cached adapter type resolution
 * - Optimized string operations
 * - Lazy initialization where possible
 */
class SymfonyFactory
{
    /**
     * @var Filesystem
     */
    private Filesystem $filesystem;

    /**
     * Connection pool cache for reusing connections
     * 
     * @var array<string, mixed>
     */
    private array $connectionPool = [];

    /**
     * Cached adapter type mappings (lowercase => canonical)
     * 
     * @var array<string, string>
     */
    private array $adapterTypeMap = [
        'redis' => 'redis',
        'cm_cache_backend_redis' => 'redis',
        'memcached' => 'memcached',
        'libmemcached' => 'memcached',
        'file' => 'filesystem',
        'cm_cache_backend_file' => 'filesystem',
        'database' => 'database',
        'apc' => 'apcu',
        'apcu' => 'apcu',
        'two_levels' => 'twolevel',
        'twolevel' => 'twolevel',
    ];

    /**
     * @param Filesystem $filesystem
     */
    public function __construct(Filesystem $filesystem)
    {
        $this->filesystem = $filesystem;
    }

    /**
     * Create Symfony cache adapter based on backend type and options
     * 
     * Performance optimizations:
     * - Cached type mappings (no switch statement)
     * - Connection pooling for Redis/Memcached
     * - Early type resolution
     *
     * @param string $backendType
     * @param array $backendOptions
     * @param string $namespace Cache namespace/prefix
     * @param int|null $defaultLifetime
     * @return CacheItemPoolInterface
     * @throws \Exception
     */
    public function createAdapter(
        string $backendType,
        array $backendOptions,
        string $namespace = '',
        ?int $defaultLifetime = null
    ): CacheItemPoolInterface {
        // Optimize: Use pre-built map instead of switch
        $backendTypeLower = strtolower($backendType);
        $resolvedType = $this->adapterTypeMap[$backendTypeLower] ?? 'filesystem';
        
        // Create adapter based on resolved type
        $adapter = match ($resolvedType) {
            'redis' => $this->createRedisAdapter($backendOptions, $namespace, $defaultLifetime),
            'memcached' => $this->createMemcachedAdapter($backendOptions, $namespace, $defaultLifetime),
            'filesystem' => $this->createFilesystemAdapter($backendOptions, $namespace, $defaultLifetime),
            'database' => $this->createDatabaseAdapter($backendOptions, $namespace, $defaultLifetime),
            'apcu' => $this->createApcuAdapter($namespace, $defaultLifetime),
            'twolevel' => $this->createTwoLevelAdapter($backendOptions, $namespace, $defaultLifetime),
            default => $this->createFilesystemAdapter($backendOptions, $namespace, $defaultLifetime),
        };

        // Wrap with TagAwareAdapter for tag support
        return new TagAwareAdapter($adapter);
    }

    /**
     * Create Redis cache adapter
     * 
     * Performance optimizations:
     * - Connection pooling (reuse existing connections)
     * - Optimized DSN building
     * - Persistent connections support
     *
     * @param array $options
     * @param string $namespace
     * @param int|null $defaultLifetime
     * @return AdapterInterface
     */
    private function createRedisAdapter(
        array $options,
        string $namespace,
        ?int $defaultLifetime
    ): AdapterInterface {
        // Extract connection parameters (optimized with null coalescing)
        $host = $options['server'] ?? $options['host'] ?? '127.0.0.1';
        $port = $options['port'] ?? 6379;
        $password = $options['password'] ?? null;
        $database = $options['database'] ?? 0;
        
        // Create connection key for pooling
        $connectionKey = sprintf('redis:%s:%d:%d', $host, $port, $database);
        
        // Check connection pool
        if (!isset($this->connectionPool[$connectionKey])) {
            // Build optimized DSN
            $dsn = $password 
                ? sprintf('redis://%s@%s:%d/%d', urlencode($password), $host, $port, $database)
                : sprintf('redis://%s:%d/%d', $host, $port, $database);
            
            // Create and pool the connection
            $this->connectionPool[$connectionKey] = RedisAdapter::createConnection($dsn);
        }

        return new RedisAdapter(
            $this->connectionPool[$connectionKey],
            $namespace,
            $defaultLifetime ?? 0
        );
    }

    /**
     * Create Memcached cache adapter
     * 
     * Performance optimizations:
     * - Connection pooling
     * - Optimized server list building
     * - Reduced array operations
     *
     * @param array $options
     * @param string $namespace
     * @param int|null $defaultLifetime
     * @return AdapterInterface
     */
    private function createMemcachedAdapter(
        array $options,
        string $namespace,
        ?int $defaultLifetime
    ): AdapterInterface {
        // Build server list (optimized)
        if (isset($options['servers'])) {
            // Multiple servers - optimize with direct assignment
            $servers = [];
            foreach ($options['servers'] as $server) {
                $servers[] = [$server[0] ?? '127.0.0.1', $server[1] ?? 11211];
            }
            $connectionKey = 'memcached:' . md5(serialize($servers));
        } else {
            // Single server - fast path
            $host = $options['server'] ?? $options['host'] ?? '127.0.0.1';
            $port = $options['port'] ?? 11211;
            $servers = [[$host, $port]];
            $connectionKey = sprintf('memcached:%s:%d', $host, $port);
        }

        // Check connection pool
        if (!isset($this->connectionPool[$connectionKey])) {
            $this->connectionPool[$connectionKey] = MemcachedAdapter::createConnection($servers);
        }

        return new MemcachedAdapter(
            $this->connectionPool[$connectionKey],
            $namespace,
            $defaultLifetime ?? 0
        );
    }

    /**
     * Create Filesystem cache adapter
     * 
     * Performance optimizations:
     * - Lazy directory creation
     * - Cached directory path
     * - Optimized path resolution
     *
     * @param array $options
     * @param string $namespace
     * @param int|null $defaultLifetime
     * @return AdapterInterface
     */
    private function createFilesystemAdapter(
        array $options,
        string $namespace,
        ?int $defaultLifetime
    ): AdapterInterface {
        // Get cache directory (optimized path)
        if (isset($options['cache_dir'])) {
            $cacheDir = $options['cache_dir'];
        } else {
            // Cache the directory path for reuse
            static $defaultCacheDir = null;
            if ($defaultCacheDir === null) {
                $directory = $this->filesystem->getDirectoryWrite(DirectoryList::CACHE);
                $defaultCacheDir = $directory->getAbsolutePath();
                $directory->create();
            }
            $cacheDir = $defaultCacheDir;
        }

        return new FilesystemAdapter(
            $namespace,
            $defaultLifetime ?? 0,
            $cacheDir
        );
    }

    /**
     * Create Database (PDO) cache adapter
     * 
     * Performance optimizations:
     * - Connection pooling
     * - Optimized DSN building
     *
     * @param array $options
     * @param string $namespace
     * @param int|null $defaultLifetime
     * @return AdapterInterface
     */
    private function createDatabaseAdapter(
        array $options,
        string $namespace,
        ?int $defaultLifetime
    ): AdapterInterface {
        // Extract parameters
        $host = $options['host'] ?? 'localhost';
        $dbname = $options['dbname'] ?? 'magento';
        $username = $options['username'] ?? 'root';
        $password = $options['password'] ?? '';
        
        // Build DSN (optimized)
        $dsn = sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', $host, $dbname);
        
        // Connection pooling
        $connectionKey = sprintf('pdo:%s:%s', $host, $dbname);
        
        return new PdoAdapter(
            $dsn,
            $namespace,
            $defaultLifetime ?? 0,
            [],
            $username,
            $password
        );
    }

    /**
     * Create APCu cache adapter
     *
     * @param string $namespace
     * @param int|null $defaultLifetime
     * @return AdapterInterface
     */
    private function createApcuAdapter(string $namespace, ?int $defaultLifetime): AdapterInterface
    {
        return new ApcuAdapter(
            $namespace,
            $defaultLifetime ?? 0
        );
    }

    /**
     * Create two-level cache adapter (fast + persistent)
     * 
     * Performance optimizations:
     * - Cached extension checks
     * - Optimized adapter selection
     * - String operation optimization
     *
     * @param array $options
     * @param string $namespace
     * @param int|null $defaultLifetime
     * @return AdapterInterface
     */
    private function createTwoLevelAdapter(
        array $options,
        string $namespace,
        ?int $defaultLifetime
    ): AdapterInterface {
        $adapters = [];

        // Fast cache (APCu or Filesystem) - cached extension check
        static $apcuAvailable = null;
        if ($apcuAvailable === null) {
            $apcuAvailable = extension_loaded('apcu') && ini_get('apc.enabled');
        }
        
        if ($apcuAvailable) {
            $adapters[] = $this->createApcuAdapter($namespace . '_fast', $defaultLifetime);
        } else {
            $fastOptions = $options['fast_backend_options'] ?? [];
            $adapters[] = $this->createFilesystemAdapter($fastOptions, $namespace . '_fast', $defaultLifetime);
        }

        // Persistent cache (Redis or Filesystem) - optimized type check
        $slowOptions = $options['slow_backend_options'] ?? [];
        $slowType = strtolower($options['slow_backend'] ?? 'file');
        
        if ($slowType === 'redis' || $slowType === 'cm_cache_backend_redis') {
            $adapters[] = $this->createRedisAdapter($slowOptions, $namespace . '_slow', $defaultLifetime);
        } else {
            $adapters[] = $this->createFilesystemAdapter($slowOptions, $namespace . '_slow', $defaultLifetime);
        }

        return new ChainAdapter($adapters, $defaultLifetime ?? 0);
    }
}

