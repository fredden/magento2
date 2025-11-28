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
use Magento\Framework\Cache\Frontend\Adapter\SymfonyAdapters\FilesystemTagAdapter;
use Magento\Framework\Cache\Frontend\Adapter\SymfonyAdapters\GenericTagAdapter;
use Magento\Framework\Cache\Frontend\Adapter\SymfonyAdapters\RedisTagAdapter;
use Magento\Framework\Cache\Frontend\Adapter\SymfonyAdapters\TagAdapterInterface;
use Magento\Framework\Filesystem;
use Magento\Framework\Serialize\Serializer\Serialize;
use Predis\Client as PredisClient;
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
     * @param array $backendOptions
     * @return TagAdapterInterface
     */
    public function createTagAdapter(
        string $backendType,
        CacheItemPoolInterface $cachePool,
        string $namespace = '',
        bool $isPageCache = false,
        array $backendOptions = []
    ): TagAdapterInterface {
        // Resolve backend type
        $backendTypeLower = strtolower($backendType);
        $resolvedType = $this->adapterTypeMap[$backendTypeLower] ?? 'filesystem';

        // Check if Lua scripts are enabled (separate flags for different operations)
        $useLua = !empty($backendOptions['use_lua']) && $backendOptions['use_lua'] === '1';
        $useLuaOnGc = !empty($backendOptions['use_lua_on_gc']) && $backendOptions['use_lua_on_gc'] === '1';

        // Create appropriate tag adapter with fallback to GenericTagAdapter
        try {
            return match ($resolvedType) {
                'redis' => new RedisTagAdapter(
                    $cachePool,
                    $namespace,
                    $useLua,
                    $useLuaOnGc
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
     * Create Redis cache adapter with automatic fallback support
     *
     * Connection Strategy:
     * 1. Try phpredis extension (fastest - native C extension)
     * 2. Fallback to Predis library (slower but no extension required)
     * 3. Throw exception if neither available
     *
     * Performance optimizations:
     * - Connection pooling (reuse existing connections)
     * - Optimized DSN building
     * - Persistent connections support (phpredis only)
     * - igbinary serializer support (70% faster, 58% smaller)
     * - Connection tuning (timeout, read_timeout, retry_interval, connect_retries)
     *
     * Supported options:
     * - server/host: Redis server hostname (default: 127.0.0.1)
     * - port: Redis port (default: 6379)
     * - password: Redis password (optional)
     * - database: Redis database number (default: 0)
     * - persistent: Enable persistent connections (default: true, phpredis only)
     * - persistent_id: Persistent connection identifier (optional, phpredis only)
     * - serializer: Serializer to use (igbinary, php, default: php)
     * - timeout: Connection timeout in seconds (optional, e.g. 2.5)
     * - read_timeout: Read timeout in seconds (optional, e.g. 2.0)
     * - retry_interval: Retry interval in milliseconds (optional, e.g. 100, phpredis only)
     * - connect_retries: Number of connection retries (optional, e.g. 3, phpredis only)
     *
     * @param array $options Connection and configuration options
     * @param string $namespace Cache key namespace/prefix
     * @param int|null $defaultLifetime Default cache lifetime in seconds
     * @return AdapterInterface
     * @throws \RuntimeException If neither phpredis nor Predis is available
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     * @SuppressWarnings(PHPMD.NPathComplexity)
     * @SuppressWarnings(PHPMD.ExcessiveParameterList)
     */
    private function createRedisAdapter(
        array $options,
        string $namespace,
        ?int $defaultLifetime
    ): AdapterInterface {
        // Extract connection parameters (optimized with null coalescing)
        $host = $options['server'] ?? $options['host'] ?? '127.0.0.1';
        $port = (int)($options['port'] ?? 6379);
        $password = $options['password'] ?? null;
        $database = (int)($options['database'] ?? 0);
        $serializer = $options['serializer'] ?? null;

        // Persistent connection support (15-30% performance gain)
        $persistent = $options['persistent'] ?? true;  // Enable by default
        $persistentId = $options['persistent_id'] ?? null;

        // Connection tuning parameters (cast to proper types from string config)
        $timeout = isset($options['timeout']) ? (float)$options['timeout'] : null;
        $readTimeout = isset($options['read_timeout']) ? (float)$options['read_timeout'] : null;
        $retryInterval = isset($options['retry_interval']) ? (int)$options['retry_interval'] : null;
        $connectRetries = isset($options['connect_retries']) ? (int)$options['connect_retries'] : null;

        // Create connection key for pooling
        $connectionKey = sprintf('redis:%s:%d:%d', $host, $port, $database);

        // Check connection pool
        if (!isset($this->connectionPool[$connectionKey])) {
            // Check if phpredis extension is available
            if (extension_loaded('redis')) {
                // Use phpredis (native C extension - fastest)
                $this->connectionPool[$connectionKey] = $this->createPhpRedisConnection(
                    $host,
                    $port,
                    $password,
                    $database,
                    $persistent,
                    $persistentId,
                    $timeout,
                    $readTimeout,
                    $retryInterval,
                    $connectRetries
                );
            } elseif (class_exists(PredisClient::class)) {
                // Fallback to Predis (pure PHP - slower but no extension required)
                $this->connectionPool[$connectionKey] = $this->createPredisConnection(
                    $host,
                    $port,
                    $password,
                    $database,
                    $persistent,
                    $timeout,
                    $readTimeout
                );
            } else {
                throw new \RuntimeException(
                    'Redis cache requires either phpredis extension or predis/predis library. ' .
                    'Install phpredis extension (recommended) or run: composer require predis/predis'
                );
            }
        }

        // Set client name every time (even for pooled connections)
        // This ensures persistent connections get named correctly
        if ($persistentId) {
            $this->setRedisClientName($this->connectionPool[$connectionKey], $persistentId);
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
     * Create phpredis connection (native C extension)
     *
     * This is the recommended and fastest option for Redis connectivity.
     *
     * @param string $host
     * @param int $port
     * @param string|null $password
     * @param int $database
     * @param bool $persistent
     * @param string|null $persistentId
     * @param float|null $timeout
     * @param float|null $readTimeout
     * @param int|null $retryInterval
     * @param int|null $connectRetries
     * @return \Redis|\RedisCluster|\Relay\Relay
     * @SuppressWarnings(PHPMD.ExcessiveParameterList)
     */
    private function createPhpRedisConnection(
        string $host,
        int $port,
        ?string $password,
        int $database,
        bool $persistent,
        ?string $persistentId,
        ?float $timeout,
        ?float $readTimeout,
        ?int $retryInterval,
        ?int $connectRetries
    ) {
        // Build optimized DSN with all connection parameters
        $dsnParams = [];

        // Add persistent connection parameters
        if ($persistent) {
            $dsnParams[] = 'persistent=1';
            if ($persistentId) {
                $dsnParams[] = 'persistent_id=' . urlencode($persistentId);
            }
        }

        // Add connection timeout parameters
        if ($timeout !== null) {
            $dsnParams[] = 'timeout=' . $timeout;
        }

        if ($readTimeout !== null) {
            $dsnParams[] = 'read_timeout=' . $readTimeout;
        }

        // Add retry parameters
        if ($retryInterval !== null) {
            $dsnParams[] = 'retry_interval=' . $retryInterval;
        }

        if ($connectRetries !== null) {
            $dsnParams[] = 'connect_retries=' . $connectRetries;
        }

        // Build base DSN
        $baseDsn = $password
            ? sprintf('redis://%s@%s:%d/%d', urlencode($password), $host, $port, $database)
            : sprintf('redis://%s:%d/%d', $host, $port, $database);

        // Append DSN parameters
        $dsn = $dsnParams ? $baseDsn . '?' . implode('&', $dsnParams) : $baseDsn;

        // Create and return the connection using Symfony's factory
        return RedisAdapter::createConnection($dsn);
    }

    /**
     * Create Predis connection (pure PHP fallback)
     *
     * This is a fallback when phpredis extension is not available.
     * Performance is ~2-3x slower than phpredis, but requires no PHP extensions.
     *
     * @param string $host
     * @param int $port
     * @param string|null $password
     * @param int $database
     * @param bool $persistent
     * @param float|null $timeout
     * @param float|null $readTimeout
     * @return PredisClient
     */
    private function createPredisConnection(
        string $host,
        int $port,
        ?string $password,
        int $database,
        bool $persistent,
        ?float $timeout,
        ?float $readTimeout
    ): PredisClient {
        $connectionParams = [
            'scheme' => 'tcp',
            'host' => $host,
            'port' => $port,
            'database' => $database,
        ];

        // Add optional parameters
        if ($password) {
            $connectionParams['password'] = $password;
        }

        if ($timeout !== null) {
            $connectionParams['timeout'] = $timeout;
        }

        if ($readTimeout !== null) {
            $connectionParams['read_write_timeout'] = $readTimeout;
        }

        // Note: Predis doesn't support true persistent connections like phpredis
        // The 'persistent' option creates a connection string but doesn't actually persist
        // For true persistent connections, use phpredis extension
        if ($persistent) {
            $connectionParams['persistent'] = true;
        }

        $options = [];

        return new PredisClient($connectionParams, $options);
    }

    /**
     * Set Redis client name for better monitoring and debugging
     *
     * @param mixed $connection Redis connection (phpredis, RedisCluster, Relay, or Predis)
     * @param string $clientName Name to set for the client
     * @return void
     */
    private function setRedisClientName($connection, string $clientName): void
    {
        try {
            // Set Redis client name for better monitoring
            if ($connection instanceof \Redis) {
                // phpredis
                $connection->client('SETNAME', $clientName);
                // phpcs:disable Magento2.CodeAnalysis.EmptyBlock
            } elseif ($connection instanceof \RedisCluster) {
                // Intentional no-op: RedisCluster doesn't support CLIENT SETNAME
                // phpcs:enable Magento2.CodeAnalysis.EmptyBlock
            } elseif ($connection instanceof PredisClient) {
                // Predis
                $connection->client('SETNAME', $clientName);
            } elseif (method_exists($connection, 'client')) {
                // Relay or other compatible implementations
                $connection->client('SETNAME', $clientName);
            }
            // phpcs:ignore Magento2.CodeAnalysis.EmptyBlock
        } catch (\Exception $e) {
            // Intentional no-op: Client name is for monitoring only, failures are non-critical
        }
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

        // Add igbinary marshaller support for file cache (70% faster, 58% smaller)
        $serializer = $options['serializer'] ?? null;
        $marshaller = $this->createMarshaller($serializer);

        return new FilesystemAdapter(
            $namespace,
            $defaultLifetime ?? 0,
            $cacheDir,
            $marshaller
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

        if ($slowType === 'redis') {
            $adapters[] = $this->createRedisAdapter($slowOptions, $namespace . '_slow', $defaultLifetime);
        } else {
            $adapters[] = $this->createFilesystemAdapter($slowOptions, $namespace . '_slow', $defaultLifetime);
        }

        return new ChainAdapter($adapters, $defaultLifetime ?? 0);
    }
}
