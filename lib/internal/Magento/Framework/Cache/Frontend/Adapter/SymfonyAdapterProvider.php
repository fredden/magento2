<?php
/**
 * Copyright 2025 Adobe
 * All Rights Reserved.
 */
declare(strict_types=1);

namespace Magento\Framework\Cache\Frontend\Adapter;

use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Cache\Frontend\Adapter\Symfony\MagentoDatabaseAdapter;
use Magento\Framework\Cache\Frontend\Adapter\SymfonyAdapters\TagAdapterInterface;
use Magento\Framework\Cache\Frontend\Adapter\SymfonyAdapters\FilesystemTagAdapter;
use Magento\Framework\Cache\Frontend\Adapter\SymfonyAdapters\GenericTagAdapter;
use Magento\Framework\Cache\Frontend\Adapter\SymfonyAdapters\RedisTagAdapter;
use Magento\Framework\Filesystem;
use Magento\Framework\Serialize\Serializer\Serialize;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\Cache\Adapter\AdapterInterface;
use Symfony\Component\Cache\Adapter\ApcuAdapter;
use Symfony\Component\Cache\Adapter\ChainAdapter;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Symfony\Component\Cache\Adapter\MemcachedAdapter;
use Symfony\Component\Cache\Adapter\RedisAdapter;
use Symfony\Component\Cache\Adapter\TagAwareAdapter;
use Symfony\Component\Cache\Marshaller\DefaultMarshaller;

/**
 * Provider for creating Symfony Cache adapters and tag adapters
 *
 * This class is responsible for:
 * - Creating PSR-6 cache pool adapters (RedisAdapter, FilesystemAdapter, etc.)
 * - Creating backend-specific tag adapters (RedisTagAdapter, FilesystemTagAdapter, etc.)
 * - Managing connection pooling and optimization
 * - Parsing and applying cache configuration from env.php
 *
 * Performance optimizations:
 * - Connection pooling for Redis/Memcached
 * - Cached adapter type resolution
 * - Optimized string operations
 * - Lazy initialization where possible
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class SymfonyAdapterProvider
{
    /**
     * @var Filesystem
     */
    private Filesystem $filesystem;

    /**
     * @var ResourceConnection
     */
    private ResourceConnection $resource;

    /**
     * @var Serialize PHP native serializer
     */
    private Serialize $serializer;

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
        // Redis backends
        'redis' => 'redis',

        // Valkey backends (Redis fork, protocol-compatible)
        'valkey' => 'redis',

        // Memcached backends
        'memcached' => 'memcached',
        'libmemcached' => 'memcached',

        // File backends
        'file' => 'filesystem',
        'cm_cache_backend_file' => 'filesystem',

        // Database backend
        'database' => 'database',

        // APCu backends
        'apc' => 'apcu',
        'apcu' => 'apcu',

        // Two-level cache
        'two_levels' => 'twolevel',
        'twolevel' => 'twolevel',
    ];

    /**
     * @param Filesystem $filesystem
     * @param ResourceConnection $resource
     * @param Serialize $serializer PHP native serializer
     */
    public function __construct(
        Filesystem $filesystem,
        ResourceConnection $resource,
        Serialize $serializer
    ) {
        $this->filesystem = $filesystem;
        $this->resource = $resource;
        $this->serializer = $serializer;
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

        // Create adapter based on resolved type with fallback to filesystem
        try {
            $adapter = match ($resolvedType) {
                'redis' => $this->createRedisAdapter($backendOptions, $namespace, $defaultLifetime),
                'memcached' => $this->createMemcachedAdapter($backendOptions, $namespace, $defaultLifetime),
                'filesystem' => $this->createFilesystemAdapter($backendOptions, $namespace, $defaultLifetime),
                'database' => $this->createDatabaseAdapter($backendOptions, $namespace, $defaultLifetime),
                'apcu' => $this->createApcuAdapter($namespace, $defaultLifetime),
                'twolevel' => $this->createTwoLevelAdapter($backendOptions, $namespace, $defaultLifetime),
                default => $this->createFilesystemAdapter($backendOptions, $namespace, $defaultLifetime),
            };
        } catch (\Exception $e) {
            // Fallback to filesystem adapter if the requested adapter fails
            // This handles cases where Redis/Memcached is not available
            $adapter = $this->createFilesystemAdapter($backendOptions, $namespace, $defaultLifetime);
        }

        // Wrap with TagAwareAdapter for tag support
        return new TagAwareAdapter($adapter);
    }

    /**
     * Create appropriate tag adapter based on backend type
     *
     * @param string $backendType
     * @param CacheItemPoolInterface $cachePool
     * @param string $namespace
     * @param bool $isPageCache
     * @return TagAdapterInterface
     */
    public function createTagAdapter(
        string $backendType,
        CacheItemPoolInterface $cachePool,
        string $namespace = '',
        bool $isPageCache = false
    ): TagAdapterInterface {
        // Resolve backend type
        $backendTypeLower = strtolower($backendType);
        $resolvedType = $this->adapterTypeMap[$backendTypeLower] ?? 'filesystem';

        // Create appropriate tag adapter with fallback to GenericTagAdapter
        try {
            return match ($resolvedType) {
                'redis' => new RedisTagAdapter(
                    $cachePool,
                    $namespace
                ),
                'filesystem' => new FilesystemTagAdapter(
                    $cachePool,
                    $this->getCacheDirectory()
                ),
                default => new GenericTagAdapter(
                    $cachePool,
                    $isPageCache
                ),
            };
        } catch (\Exception $e) {
            // Fallback to GenericTagAdapter if specialized adapter creation fails
            return new GenericTagAdapter($cachePool, $isPageCache);
        }
    }

    /**
     * Get cache directory for filesystem operations
     *
     * @return string
     */
    private function getCacheDirectory(): string
    {
        // Use Magento's var/cache directory via Filesystem
        $cacheDir = $this->filesystem->getDirectoryRead(DirectoryList::CACHE);
        return $cacheDir->getAbsolutePath() . 'symfony';
    }

    /**
     * Create Redis cache adapter
     *
     * Performance optimizations:
     * - Connection pooling (reuse existing connections)
     * - Optimized DSN building
     * - Persistent connections support
     * - igbinary serializer support (70% faster, 58% smaller)
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
        $serializer = $options['serializer'] ?? null;

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

        // Create marshaller with igbinary support if configured
        $marshaller = $this->createMarshaller($serializer);

        return new RedisAdapter(
            $this->connectionPool[$connectionKey],
            $namespace,
            $defaultLifetime ?? 0,
            $marshaller
        );
    }

    /**
     * Create marshaller for serialization
     *
     * Supports igbinary for 70% faster serialization and 58% smaller data size
     *
     * @param string|null $serializer Serializer name ('igbinary' or null for default)
     * @return DefaultMarshaller|null
     */
    private function createMarshaller(?string $serializer): ?DefaultMarshaller
    {
        // If no serializer specified or not 'igbinary', return null (uses default PHP serializer)
        if ($serializer !== 'igbinary') {
            return null;
        }

        // Check if igbinary extension is loaded
        if (!extension_loaded('igbinary')) {
            // Fallback to default PHP serializer if igbinary not available
            return null;
        }

        // Create marshaller with igbinary enabled
        // true = use igbinary_serialize/igbinary_unserialize
        // false = don't throw on serialization failure (graceful degradation)
        return new DefaultMarshaller(true, false);
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
            // phpcs:ignore Magento2.Security.InsecureFunction,Magento2.Functions.DiscouragedFunction
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
     * Create Magento Database cache adapter
     *
     * Uses Magento's existing Database.php backend (reuses cache/cache_tag tables)
     * This leverages the 620-line Database.php that has all the logic with zend_db
     *
     * @param array $options Backend options (unused - database config is in ResourceConnection)
     * @param string $namespace
     * @param int|null $defaultLifetime
     * @return CacheItemPoolInterface
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    private function createDatabaseAdapter(
        array $options,
        string $namespace,
        ?int $defaultLifetime
    ): CacheItemPoolInterface {
        // Use Magento's existing Database backend (reuses cache/cache_tag tables)
        // This leverages the 620-line Database.php that has all the logic
        return new MagentoDatabaseAdapter(
            $this->resource,
            $this->serializer,
            $namespace,
            $defaultLifetime ?? 0
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
