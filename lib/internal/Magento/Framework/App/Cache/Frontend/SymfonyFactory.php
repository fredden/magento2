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
 */
class SymfonyFactory
{
    /**
     * @var Filesystem
     */
    private Filesystem $filesystem;

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
        $backendType = strtolower($backendType);
        
        switch ($backendType) {
            case 'redis':
            case 'cm_cache_backend_redis':
                $adapter = $this->createRedisAdapter($backendOptions, $namespace, $defaultLifetime);
                break;
                
            case 'memcached':
            case 'libmemcached':
                $adapter = $this->createMemcachedAdapter($backendOptions, $namespace, $defaultLifetime);
                break;
                
            case 'file':
            case 'cm_cache_backend_file':
                $adapter = $this->createFilesystemAdapter($backendOptions, $namespace, $defaultLifetime);
                break;
                
            case 'database':
                $adapter = $this->createDatabaseAdapter($backendOptions, $namespace, $defaultLifetime);
                break;
                
            case 'apc':
            case 'apcu':
                $adapter = $this->createApcuAdapter($namespace, $defaultLifetime);
                break;
                
            case 'two_levels':
            case 'twolevel':
                $adapter = $this->createTwoLevelAdapter($backendOptions, $namespace, $defaultLifetime);
                break;
                
            default:
                // Fallback to filesystem
                $adapter = $this->createFilesystemAdapter($backendOptions, $namespace, $defaultLifetime);
        }

        // Wrap with TagAwareAdapter for tag support
        return new TagAwareAdapter($adapter);
    }

    /**
     * Create Redis cache adapter
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
        // Build Redis DSN
        $host = $options['server'] ?? ($options['host'] ?? '127.0.0.1');
        $port = $options['port'] ?? 6379;
        $password = $options['password'] ?? null;
        $database = $options['database'] ?? 0;
        
        // Format: redis://[pass@]host[:port][/db-index]
        $dsn = 'redis://';
        if ($password) {
            $dsn .= urlencode($password) . '@';
        }
        $dsn .= $host . ':' . $port;
        if ($database) {
            $dsn .= '/' . $database;
        }

        return new RedisAdapter(
            RedisAdapter::createConnection($dsn),
            $namespace,
            $defaultLifetime ?? 0
        );
    }

    /**
     * Create Memcached cache adapter
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
        $servers = [];
        
        if (isset($options['servers'])) {
            // Multiple servers format
            foreach ($options['servers'] as $server) {
                $servers[] = [$server[0] ?? '127.0.0.1', $server[1] ?? 11211];
            }
        } else {
            // Single server format
            $host = $options['server'] ?? ($options['host'] ?? '127.0.0.1');
            $port = $options['port'] ?? 11211;
            $servers[] = [$host, $port];
        }

        return new MemcachedAdapter(
            MemcachedAdapter::createConnection($servers),
            $namespace,
            $defaultLifetime ?? 0
        );
    }

    /**
     * Create Filesystem cache adapter
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
        // Get cache directory
        if (isset($options['cache_dir'])) {
            $cacheDir = $options['cache_dir'];
        } else {
            $directory = $this->filesystem->getDirectoryWrite(DirectoryList::CACHE);
            $cacheDir = $directory->getAbsolutePath();
            $directory->create();
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
        // Build PDO DSN from options
        $dsn = sprintf(
            'mysql:host=%s;dbname=%s;charset=utf8mb4',
            $options['host'] ?? 'localhost',
            $options['dbname'] ?? 'magento'
        );
        
        $username = $options['username'] ?? 'root';
        $password = $options['password'] ?? '';

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

        // Fast cache (APCu or Filesystem)
        if (extension_loaded('apcu') && ini_get('apc.enabled')) {
            $adapters[] = $this->createApcuAdapter($namespace . '_fast', $defaultLifetime);
        } else {
            $fastOptions = $options['fast_backend_options'] ?? [];
            $adapters[] = $this->createFilesystemAdapter($fastOptions, $namespace . '_fast', $defaultLifetime);
        }

        // Persistent cache (Redis or Filesystem)
        $slowOptions = $options['slow_backend_options'] ?? [];
        $slowType = $options['slow_backend'] ?? 'file';
        
        if ($slowType === 'redis' || $slowType === 'Cm_Cache_Backend_Redis') {
            $adapters[] = $this->createRedisAdapter($slowOptions, $namespace . '_slow', $defaultLifetime);
        } else {
            $adapters[] = $this->createFilesystemAdapter($slowOptions, $namespace . '_slow', $defaultLifetime);
        }

        return new ChainAdapter($adapters, $defaultLifetime ?? 0);
    }
}

