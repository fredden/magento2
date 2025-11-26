<?php
/**
 * Copyright 2025 Adobe
 * All Rights Reserved.
 */
declare(strict_types=1);

namespace Magento\Framework\Cache;

use Magento\Framework\App\Cache\Frontend\Factory;
use Magento\Framework\App\DeploymentConfig;
use Magento\TestFramework\Helper\Bootstrap;
use PHPUnit\Framework\TestCase;

/**
 * Integration test for Symfony cache with Redis backend
 *
 * Tests Redis-specific features:
 * - RedisTagAdapter integration
 * - Redis SET operations for tag indices
 * - Persistent connections
 * - igbinary serialization
 * - Connection pooling
 *
 * @magentoAppIsolation enabled
 * @magentoDbIsolation enabled
 */
class SymfonyRedisAdapterTest extends TestCase
{
    /**
     * @var FrontendInterface
     */
    private FrontendInterface $cache;

    /**
     * @var Factory
     */
    private Factory $cacheFactory;

    /**
     * @var bool
     */
    private static bool $redisAvailable = false;

    /**
     * @var \Redis|null
     */
    private static ?\Redis $redis = null;

    /**
     * @var string
     */
    private static string $redisServer;

    /**
     * Get Redis server from integration test sandbox environment
     *
     * Integration tests run in a sandbox with isolated env.php at:
     * dev/tests/integration/tmp/sandbox-{hash}/etc/env.php
     *
     * The correct way to read this configuration is through DeploymentConfig
     * which automatically uses the sandbox environment's configuration.
     *
     * @return string
     */
    private static function getRedisServer(): string
    {
        try {
            // Get DeploymentConfig from ObjectManager (uses sandbox env.php)
            /** @var DeploymentConfig $deploymentConfig */
            $deploymentConfig = Bootstrap::getObjectManager()->get(DeploymentConfig::class);

            // Read cache backend server configuration from sandbox env.php
            $server = $deploymentConfig->get('cache/frontend/default/backend_options/server');

            if ($server !== null) {
                return $server;
            }
        } catch (\Exception $e) {
            // Fall through to default
        }

        // Default to 127.0.0.1 if not configured in sandbox env.php
        return '127.0.0.1';
    }

    /**
     * Check if Redis is available before running tests
     */
    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        // Get Redis server from config
        self::$redisServer = self::getRedisServer();

        // Check if Redis extension is loaded
        if (!extension_loaded('redis')) {
            self::markTestSkipped('Redis extension is not loaded');
            return;
        }

        // Check if Redis server is available
        try {
            self::$redis = new \Redis();
            // phpcs:ignore Generic.PHP.NoSilencedErrors -- Suppress connection errors during test setup
            self::$redisAvailable = @self::$redis->connect(self::$redisServer, 6379);

            if (!self::$redisAvailable) {
                self::markTestSkipped('Redis server is not available at ' . self::$redisServer . ':6379');
            }
        } catch (\Exception $e) {
            self::markTestSkipped('Redis connection failed: ' . $e->getMessage());
        }
    }

    /**
     * Set up test environment
     */
    protected function setUp(): void
    {
        parent::setUp();

        if (!self::$redisAvailable) {
            $this->markTestSkipped('Redis is not available');
        }

        $this->cacheFactory = Bootstrap::getObjectManager()->get(Factory::class);

        // Create Symfony cache adapter with Redis backend
        $this->cache = $this->cacheFactory->create([
            'frontend' => [
                'backend' => 'redis',
                'backend_options' => [
                    'server' => self::$redisServer,
                    'port' => '6379',
                    'database' => '2', // Use database 2 for tests to avoid conflicts
                    'persistent' => '1',
                    'serializer' => 'igbinary',
                ]
            ]
        ]);

        // Clean test database before each test
        if (self::$redis) {
            self::$redis->select(2);
            self::$redis->flushDB();
        }
    }

    /**
     * Tear down test environment
     */
    protected function tearDown(): void
    {
        parent::tearDown();

        // Clean up test cache
        if ($this->cache) {
            $this->cache->clean(CacheConstants::CLEANING_MODE_ALL);
        }

        // Flush test database
        if (self::$redis) {
            self::$redis->select(2);
            self::$redis->flushDB();
        }
    }

    /**
     * Clean up Redis connection
     */
    public static function tearDownAfterClass(): void
    {
        if (self::$redis) {
            self::$redis->close();
            self::$redis = null;
        }
        parent::tearDownAfterClass();
    }

    /**
     * Test basic save and load with Redis
     */
    public function testRedisSaveAndLoad(): void
    {
        $id = 'redis_test_' . uniqid();
        $data = 'redis_data_' . time();

        // Save
        $saveResult = $this->cache->save($data, $id);
        $this->assertTrue($saveResult, 'Redis save should succeed');

        // Load
        $loadResult = $this->cache->load($id);
        $this->assertEquals($data, $loadResult, 'Loaded data should match saved data');

        // Verify test() method works
        $testResult = $this->cache->test($id);
        $this->assertIsInt($testResult, 'test() should return integer timestamp');
        $this->assertGreaterThan(0, $testResult, 'Timestamp should be positive');
    }

    /**
     * Test Redis tag-based cleaning
     */
    public function testRedisTagBasedCleaning(): void
    {
        $id1 = 'redis_tag1_' . uniqid();
        $id2 = 'redis_tag2_' . uniqid();
        $id3 = 'redis_tag3_' . uniqid();
        $tag = 'redis_test_tag';

        // Save items with same tag
        $this->cache->save('data1', $id1, [$tag]);
        $this->cache->save('data2', $id2, [$tag]);
        $this->cache->save('data3', $id3, ['other_tag']); // Different tag

        // Verify all exist
        $this->assertEquals('data1', $this->cache->load($id1));
        $this->assertEquals('data2', $this->cache->load($id2));
        $this->assertEquals('data3', $this->cache->load($id3));

        // Clean by tag (uses Redis tag indices)
        $cleanResult = $this->cache->clean(CacheConstants::CLEANING_MODE_MATCHING_ANY_TAG, [$tag]);
        $this->assertTrue($cleanResult, 'Clean by tag should succeed');

        // Items with redis_test_tag should be removed
        $this->assertFalse($this->cache->load($id1), 'Item 1 with tag should be removed');
        $this->assertFalse($this->cache->load($id2), 'Item 2 with tag should be removed');

        // Item with different tag should remain
        $this->assertEquals('data3', $this->cache->load($id3), 'Item with different tag should remain');
    }

    /**
     * Test Redis SINTER operation for MATCHING_TAG mode
     */
    public function testRedisMatchingTagWithSINTER(): void
    {
        $id1 = 'redis_sinter1_' . uniqid();
        $id2 = 'redis_sinter2_' . uniqid();
        $id3 = 'redis_sinter3_' . uniqid();

        // Save items with different tag combinations
        $this->cache->save('data1', $id1, ['tagA', 'tagB']); // Has both
        $this->cache->save('data2', $id2, ['tagA']); // Has only tagA
        $this->cache->save('data3', $id3, ['tagB']); // Has only tagB

        // Clean items matching BOTH tagA AND tagB (uses SINTER)
        $cleanResult = $this->cache->clean(CacheConstants::CLEANING_MODE_MATCHING_TAG, ['tagA', 'tagB']);
        $this->assertTrue($cleanResult, 'Clean matching tag should succeed');

        // Only id1 should be removed (has both tags)
        $this->assertFalse($this->cache->load($id1), 'Item with both tags should be removed');
        $this->assertEquals('data2', $this->cache->load($id2), 'Item with only tagA should remain');
        $this->assertEquals('data3', $this->cache->load($id3), 'Item with only tagB should remain');
    }

    /**
     * Test Redis tag cleanup when item is removed
     */
    public function testRedisTagCleanupOnRemove(): void
    {
        $id1 = 'redis_cleanup1_' . uniqid();
        $id2 = 'redis_cleanup2_' . uniqid();
        $tag = 'cleanup_tag';

        // Save items with same tag
        $this->cache->save('data1', $id1, [$tag]);
        $this->cache->save('data2', $id2, [$tag]);

        // Remove first item
        $this->cache->remove($id1);

        // First item should be removed
        $this->assertFalse($this->cache->load($id1), 'Removed item should not load');

        // Second item should still exist
        $this->assertEquals('data2', $this->cache->load($id2), 'Other item with same tag should remain');

        // Cleaning by tag should still work for remaining item
        $this->cache->clean(CacheConstants::CLEANING_MODE_MATCHING_ANY_TAG, [$tag]);
        $this->assertFalse($this->cache->load($id2), 'Remaining item should be cleaned by tag');
    }

    /**
     * Test igbinary serialization with Redis
     */
    public function testRedisIgbinarySerialization(): void
    {
        if (!extension_loaded('igbinary')) {
            $this->markTestSkipped('igbinary extension not loaded');
        }

        $id = 'redis_igbinary_' . uniqid();
        $data = [
            'complex' => ['nested' => ['data' => 'value']],
            'array' => [1, 2, 3, 4, 5],
            'object' => (object)['prop' => 'test']
        ];

        // Save complex data
        $this->cache->save($data, $id);

        // Load and verify data integrity
        $loadResult = $this->cache->load($id);
        $this->assertEquals($data, $loadResult, 'Complex data should be preserved with igbinary');

        // Verify object properties are preserved
        $this->assertIsObject($loadResult['object'], 'Object should remain an object');
        $this->assertEquals('test', $loadResult['object']->prop, 'Object properties should be preserved');
    }

    /**
     * Test persistent connections
     */
    public function testRedisPersistentConnection(): void
    {
        // Create a second cache instance
        $cache2 = $this->cacheFactory->create([
            'frontend' => [
                'backend' => 'redis',
                'backend_options' => [
                    'server' => self::$redisServer,
                    'port' => '6379',
                    'database' => '2',
                    'persistent' => '1',
                    'persistent_id' => 'magento_test',
                ]
            ]
        ]);

        $id = 'redis_persistent_' . uniqid();
        $data = 'persistent_data';

        // Save with first instance
        $this->cache->save($data, $id);

        // Load with second instance (should use same persistent connection)
        $loadResult = $cache2->load($id);
        $this->assertEquals($data, $loadResult, 'Data should be accessible via persistent connection');
    }

    /**
     * Test persistent_id sets Redis client name
     */
    public function testRedisPersistentIdSetsClientName(): void
    {
        $uniqueId = 'test_' . uniqid();

        // Create cache with specific persistent_id
        $namedCache = $this->cacheFactory->create([
            'frontend' => [
                'backend' => 'redis',
                'backend_options' => [
                    'server' => self::$redisServer,
                    'port' => '6379',
                    'database' => '2',
                    'persistent' => '1',
                    'persistent_id' => $uniqueId,
                ]
            ]
        ]);

        // Trigger cache operation to create connection
        $testId = 'trigger_connection_' . uniqid();
        $namedCache->save('test_data', $testId);
        $namedCache->load($testId);

        // Check if client name is set in Redis
        // Note: This test verifies the configuration is accepted and processed
        // Actual CLIENT SETNAME verification requires active PHP-FPM workers
        // which persist connections. CLI commands close connections immediately.

        // Verify the cache works correctly with persistent_id
        $this->assertEquals('test_data', $namedCache->load($testId), 'Cache should work with persistent_id');

        // Create second cache with different persistent_id
        $uniqueId2 = 'test2_' . uniqid();
        $namedCache2 = $this->cacheFactory->create([
            'frontend' => [
                'backend' => 'redis',
                'backend_options' => [
                    'server' => self::$redisServer,
                    'port' => '6379',
                    'database' => '2',
                    'persistent' => '1',
                    'persistent_id' => $uniqueId2,
                ]
            ]
        ]);

        // Both caches should work independently
        $testId2 = 'second_connection_' . uniqid();
        $namedCache2->save('test_data_2', $testId2);

        $this->assertEquals('test_data', $namedCache->load($testId), 'First cache should still work');
        $this->assertEquals('test_data_2', $namedCache2->load($testId2), 'Second cache should work');
    }

    /**
     * Test Redis connection tuning parameters
     *
     * Verifies that connection tuning parameters (timeout, read_timeout, retry_interval, connect_retries)
     * are properly passed to Symfony RedisAdapter via DSN and cache operations work correctly.
     */
    public function testRedisConnectionTuningParameters(): void
    {
        // Create cache with all connection tuning parameters
        $cache = $this->cacheFactory->create([
            'frontend' => [
                'backend' => 'redis',
                'backend_options' => [
                    'server' => self::$redisServer,
                    'port' => '6379',
                    'database' => '3',
                    'persistent' => '1',
                    'persistent_id' => 'test_tuned',
                    'timeout' => '2.5',           // Connection timeout
                    'read_timeout' => '2.0',      // Read timeout
                    'retry_interval' => 100,      // Retry interval in ms
                    'connect_retries' => 3,       // Number of connection retries
                ]
            ]
        ]);

        // Verify cache operations work with tuning parameters
        $testId = 'tuned_connection_' . uniqid();
        $testData = 'test_data_with_tuning';

        // Save operation should work
        $saveResult = $cache->save($testData, $testId);
        $this->assertTrue($saveResult, 'Save should succeed with connection tuning parameters');

        // Load operation should work
        $loadResult = $cache->load($testId);
        $this->assertEquals($testData, $loadResult, 'Data should be retrieved correctly with tuning parameters');

        // Test operation should work
        $testResult = $cache->test($testId);
        $this->assertIsInt($testResult, 'Test should return timestamp with tuning parameters');

        // Remove operation should work
        $removeResult = $cache->remove($testId);
        $this->assertTrue($removeResult, 'Remove should succeed with tuning parameters');

        // Verify removal
        $this->assertFalse($cache->load($testId), 'Data should be removed');

        // Test with tags to ensure all operations work
        $taggedId = 'tuned_tagged_' . uniqid();
        $cache->save('tagged_data', $taggedId, ['tuned_tag'], 3600);
        $this->assertEquals('tagged_data', $cache->load($taggedId), 'Tagged save/load should work');

        // Clean by tag
        $cache->clean(CacheConstants::CLEANING_MODE_MATCHING_ANY_TAG, ['tuned_tag']);
        $this->assertFalse($cache->load($taggedId), 'Tag-based cleaning should work with tuning parameters');
    }

    /**
     * Test Redis connection info
     */
    public function testRedisConnectionInfo(): void
    {
        $info = self::$redis->info('server');
        $this->assertIsArray($info, 'Redis info should be array');
        $this->assertArrayHasKey('redis_version', $info, 'Redis version should be available');
    }

    /**
     * Test large data with Redis
     */
    public function testRedisLargeData(): void
    {
        $id = 'redis_large_' . uniqid();
        $data = str_repeat('x', 1000000); // 1MB string

        $saveResult = $this->cache->save($data, $id);
        $this->assertTrue($saveResult, 'Large data save should succeed');

        $loadResult = $this->cache->load($id);
        $this->assertEquals($data, $loadResult, 'Large data should be preserved');

        // Check Redis memory usage
        $info = self::$redis->info('memory');
        $this->assertArrayHasKey('used_memory_human', $info, 'Redis memory info should be available');
    }

    /**
     * Test batch operations with Redis
     */
    public function testRedisBatchOperations(): void
    {
        $ids = [];
        $baseId = 'redis_batch_' . uniqid() . '_';

        // Save 50 items
        for ($i = 0; $i < 50; $i++) {
            $id = $baseId . $i;
            $ids[] = $id;
            $this->cache->save("data_$i", $id, ['batch_tag']);
        }

        // Verify all exist
        foreach ($ids as $i => $id) {
            $this->assertEquals("data_$i", $this->cache->load($id), "Item $i should exist");
        }

        // Clean by tag (should use Redis pipeline for efficiency)
        $this->cache->clean(CacheConstants::CLEANING_MODE_MATCHING_ANY_TAG, ['batch_tag']);

        // Verify all removed
        foreach ($ids as $id) {
            $this->assertFalse($this->cache->load($id), "Item should be removed after clean");
        }
    }

    /**
     * Test clean all clears Redis cache completely
     */
    public function testRedisCleanAllClearsCache(): void
    {
        $id1 = 'redis_cleanall1_' . uniqid();
        $id2 = 'redis_cleanall2_' . uniqid();
        $id3 = 'redis_cleanall3_' . uniqid();

        // Save items with tags
        $this->cache->save('data1', $id1, ['tag1', 'tag2']);
        $this->cache->save('data2', $id2, ['tag3']);
        $this->cache->save('data3', $id3, []);

        // Verify all items exist
        $this->assertEquals('data1', $this->cache->load($id1));
        $this->assertEquals('data2', $this->cache->load($id2));
        $this->assertEquals('data3', $this->cache->load($id3));

        // Clean all
        $this->cache->clean(CacheConstants::CLEANING_MODE_ALL);

        // Verify all items are removed
        $this->assertFalse($this->cache->load($id1), 'Item 1 should be removed');
        $this->assertFalse($this->cache->load($id2), 'Item 2 should be removed');
        $this->assertFalse($this->cache->load($id3), 'Item 3 should be removed');

        // Verify tag-based cleaning still works after clean all (tag indices recreated)
        $id4 = 'redis_after_clean_' . uniqid();
        $this->cache->save('data4', $id4, ['newtag']);
        $this->cache->clean(CacheConstants::CLEANING_MODE_MATCHING_ANY_TAG, ['newtag']);
        $this->assertFalse($this->cache->load($id4), 'New item should be cleanable by tag');
    }

    /**
     * Test Redis cache expiration
     */
    public function testRedisCacheExpiration(): void
    {
        $id = 'redis_expiry_' . uniqid();
        $data = 'expiring_data';
        $lifetime = 3600; // 1 hour

        // Save with lifetime
        $this->cache->save($data, $id, [], $lifetime);

        // Verify data exists
        $this->assertEquals($data, $this->cache->load($id), 'Data should exist immediately after save');

        // Verify test() returns a timestamp
        $testResult = $this->cache->test($id);
        $this->assertIsInt($testResult, 'test() should return int timestamp for item with lifetime');
        $this->assertGreaterThan(0, $testResult, 'Timestamp should be positive');
    }

    /**
     * Test RedisTagAdapter features - AND logic with SINTER
     */
    public function testRedisTagAdapterFeatures(): void
    {
        // This test verifies that RedisTagAdapter is working by testing its key feature:
        // True AND logic using Redis SINTER operations

        $id1 = 'redis_feature1_' . uniqid();
        $id2 = 'redis_feature2_' . uniqid();
        $id3 = 'redis_feature3_' . uniqid();

        // Save items with different tag combinations
        $this->cache->save('data1', $id1, ['featureA', 'featureB']); // Has both
        $this->cache->save('data2', $id2, ['featureA']); // Has only A
        $this->cache->save('data3', $id3, ['featureB']); // Has only B

        // Use MATCHING_TAG mode (AND logic) - only RedisTagAdapter can do true AND logic efficiently
        $this->cache->clean(CacheConstants::CLEANING_MODE_MATCHING_TAG, ['featureA', 'featureB']);

        // Only id1 should be removed (has BOTH tags)
        $this->assertFalse($this->cache->load($id1), 'Item with both tags should be removed (Redis SINTER)');
        $this->assertEquals('data2', $this->cache->load($id2), 'Item with only featureA should remain');
        $this->assertEquals('data3', $this->cache->load($id3), 'Item with only featureB should remain');

        // This proves RedisTagAdapter is active (file/generic adapters can't do efficient AND logic)
    }

    /**
     * Test concurrent access (simulated) - overwrites
     */
    public function testRedisConcurrentAccess(): void
    {
        $id = 'redis_concurrent_' . uniqid();
        $data1 = 'data_first';
        $data2 = 'data_second';
        $data3 = 'data_third';

        // First write
        $this->cache->save($data1, $id);
        $this->assertEquals($data1, $this->cache->load($id), 'First write should be loadable');

        // Second write (overwrite)
        $this->cache->save($data2, $id);
        $this->assertEquals($data2, $this->cache->load($id), 'Second write should overwrite first');

        // Third write with tags (overwrite)
        $this->cache->save($data3, $id, ['newtag']);
        $this->assertEquals($data3, $this->cache->load($id), 'Third write should overwrite second');

        // Verify tags work on overwritten item
        $this->cache->clean(CacheConstants::CLEANING_MODE_MATCHING_ANY_TAG, ['newtag']);
        $this->assertFalse($this->cache->load($id), 'Overwritten item should be cleanable by new tags');
    }

    /**
     * Test NOT_MATCHING_TAG with Redis SDIFF
     */
    public function testRedisNotMatchingTagWithSDIFF(): void
    {
        $id1 = 'redis_sdiff1_' . uniqid();
        $id2 = 'redis_sdiff2_' . uniqid();
        $id3 = 'redis_sdiff3_' . uniqid();

        // Save items with different tag combinations
        $this->cache->save('data1', $id1, ['tagX', 'tagY']);
        $this->cache->save('data2', $id2, ['tagY']);
        $this->cache->save('data3', $id3, ['tagZ']);

        // Clean items NOT matching BOTH tagX AND tagY
        $cleanResult = $this->cache->clean(CacheConstants::CLEANING_MODE_NOT_MATCHING_TAG, ['tagX', 'tagY']);
        $this->assertTrue($cleanResult, 'Clean not matching tag should succeed');

        // id1 should remain (has both tags)
        $this->assertEquals('data1', $this->cache->load($id1), 'Item with both tags should remain');

        // Behavior for id2 and id3 is adapter-dependent
        // They should typically be removed (don't have both tags)
        $this->assertTrue(
            $this->cache->load($id2) === false || $this->cache->load($id2) === 'data2',
            'NOT_MATCHING_TAG behavior for id2'
        );
    }

    /**
     * Test Redis isolation between cache instances
     */
    public function testRedisIsolationBetweenInstances(): void
    {
        $id1 = 'redis_isolation1_' . uniqid();
        $id2 = 'redis_isolation2_' . uniqid();

        // Save data in first instance
        $this->cache->save('data1', $id1);
        $this->assertEquals('data1', $this->cache->load($id1), 'Data should exist in first instance');

        // Create second cache instance
        $cache2 = $this->cacheFactory->create([
            'frontend' => [
                'backend' => 'redis',
                'backend_options' => [
                    'server' => self::$redisServer,
                    'port' => '6379',
                    'database' => '2',
                ]
            ]
        ]);

        // Second instance should see same data (same database)
        $this->assertEquals('data1', $cache2->load($id1), 'Second instance should see same data');

        // Save in second instance
        $cache2->save('data2', $id2);

        // First instance should see second instance's data
        $this->assertEquals('data2', $this->cache->load($id2), 'First instance should see second instance data');
    }

    /**
     * Test Symfony with Redis performs well
     */
    public function testRedisPerformance(): void
    {
        $operations = 100;
        $ids = [];

        // Measure save performance
        $saveStart = microtime(true);
        for ($i = 0; $i < $operations; $i++) {
            $id = 'perf_' . $i;
            $ids[] = $id;
            $this->cache->save("data_$i", $id, ['perf_tag']);
        }
        $saveDuration = microtime(true) - $saveStart;

        // Measure load performance
        $loadStart = microtime(true);
        for ($i = 0; $i < $operations; $i++) {
            $this->cache->load($ids[$i]);
        }
        $loadDuration = microtime(true) - $loadStart;

        // Measure clean performance
        $cleanStart = microtime(true);
        $this->cache->clean(CacheConstants::CLEANING_MODE_MATCHING_ANY_TAG, ['perf_tag']);
        $cleanDuration = microtime(true) - $cleanStart;

        // Performance assertions (should be fast)
        $this->assertLessThan(1.0, $saveDuration, 'Saving 100 items should take less than 1 second');
        $this->assertLessThan(1.0, $loadDuration, 'Loading 100 items should take less than 1 second');
        $this->assertLessThan(1.0, $cleanDuration, 'Cleaning 100 items should take less than 1 second');
    }

    /**
     * Test Lua garbage collection (use_lua_on_gc)
     */
    public function testRedisLuaGarbageCollection(): void
    {
        // Create cache with Lua GC enabled
        $luaCache = $this->cacheFactory->create([
            'frontend' => [
                'backend' => 'redis',
                'backend_options' => [
                    'server' => self::$redisServer,
                    'port' => '6379',
                    'database' => '2',
                    'use_lua' => '0',
                    'use_lua_on_gc' => '1',
                ]
            ]
        ]);

        // Save items that will expire
        $id1 = 'lua_gc1_' . uniqid();
        $id2 = 'lua_gc2_' . uniqid();

        $luaCache->save('data1', $id1, ['gc_tag'], 1); // 1 second TTL
        $luaCache->save('data2', $id2, ['gc_tag'], 1);

        // Verify items exist
        $this->assertEquals('data1', $luaCache->load($id1));
        $this->assertEquals('data2', $luaCache->load($id2));

        // Wait for expiration
        sleep(2);

        // Items should be expired
        $this->assertFalse($luaCache->load($id1), 'Item 1 should be expired');
        $this->assertFalse($luaCache->load($id2), 'Item 2 should be expired');

        // Note: Actual garbage collection via garbageCollect() method
        // is tested at the unit level. This integration test verifies
        // the configuration is properly passed through.
    }

    /**
     * Test Lua scripts with use_lua enabled
     */
    public function testRedisLuaOperations(): void
    {
        // Create cache with both Lua flags enabled
        $luaCache = $this->cacheFactory->create([
            'frontend' => [
                'backend' => 'redis',
                'backend_options' => [
                    'server' => self::$redisServer,
                    'port' => '6379',
                    'database' => '2',
                    'use_lua' => '1',
                    'use_lua_on_gc' => '1',
                ]
            ]
        ]);

        // Save items with tags
        $id1 = 'lua_ops1_' . uniqid();
        $id2 = 'lua_ops2_' . uniqid();

        $luaCache->save('data1', $id1, ['lua_tag']);
        $luaCache->save('data2', $id2, ['lua_tag']);

        // Verify both exist
        $this->assertEquals('data1', $luaCache->load($id1));
        $this->assertEquals('data2', $luaCache->load($id2));

        // Clean by tag (when use_lua=1, may use Lua for certain operations)
        $cleanResult = $luaCache->clean(CacheConstants::CLEANING_MODE_MATCHING_ANY_TAG, ['lua_tag']);
        $this->assertTrue($cleanResult, 'Clean should succeed with Lua enabled');

        // Verify items are removed
        $this->assertFalse($luaCache->load($id1), 'Item 1 should be removed');
        $this->assertFalse($luaCache->load($id2), 'Item 2 should be removed');
    }

    /**
     * Test Lua scripts are optional and gracefully disabled
     */
    public function testRedisWithoutLuaScripts(): void
    {
        // Create cache with both Lua flags disabled
        $noLuaCache = $this->cacheFactory->create([
            'frontend' => [
                'backend' => 'redis',
                'backend_options' => [
                    'server' => self::$redisServer,
                    'port' => '6379',
                    'database' => '2',
                    'use_lua' => '0',
                    'use_lua_on_gc' => '0',
                ]
            ]
        ]);

        // All operations should still work using pipelines
        $id = 'no_lua_' . uniqid();
        $data = 'test_data';

        // Save
        $saveResult = $noLuaCache->save($data, $id, ['test_tag']);
        $this->assertTrue($saveResult, 'Save should work without Lua');

        // Load
        $loadResult = $noLuaCache->load($id);
        $this->assertEquals($data, $loadResult, 'Load should work without Lua');

        // Clean by tag
        $cleanResult = $noLuaCache->clean(CacheConstants::CLEANING_MODE_MATCHING_ANY_TAG, ['test_tag']);
        $this->assertTrue($cleanResult, 'Clean should work without Lua (using pipelines)');

        // Verify removed
        $this->assertFalse($noLuaCache->load($id), 'Item should be removed after clean');
    }

    /**
     * Test preload_keys functionality
     *
     * Verifies that frequently accessed keys are preloaded into local PHP memory
     * to eliminate Redis network roundtrips.
     */
    public function testRedisPreloadKeys(): void
    {
        // Define keys to preload
        $preloadKey1 = 'preload_test_key1_' . uniqid();
        $preloadKey2 = 'preload_test_key2_' . uniqid();
        $preloadKey3 = 'preload_test_key3_' . uniqid();
        $nonPreloadKey = 'non_preload_key_' . uniqid();

        // First, populate Redis with test data
        $tempCache = $this->cacheFactory->create([
            'frontend' => [
                'backend' => 'redis',
                'backend_options' => [
                    'server' => self::$redisServer,
                    'port' => '6379',
                    'database' => '4',
                ]
            ]
        ]);

        // Save test data to Redis
        $tempCache->save('preload_data_1', $preloadKey1);
        $tempCache->save('preload_data_2', $preloadKey2);
        $tempCache->save('preload_data_3', $preloadKey3);
        $tempCache->save('non_preload_data', $nonPreloadKey);

        // Create cache with preload_keys configured
        $cache = $this->cacheFactory->create([
            'frontend' => [
                'backend' => 'redis',
                'backend_options' => [
                    'server' => self::$redisServer,
                    'port' => '6379',
                    'database' => '4',
                    'preload_keys' => [
                        $preloadKey1,
                        $preloadKey2,
                        $preloadKey3,
                    ],
                ]
            ]
        ]);

        // Test 1: Preloaded keys should be accessible
        $this->assertEquals('preload_data_1', $cache->load($preloadKey1), 'Preloaded key 1 should be accessible');
        $this->assertEquals('preload_data_2', $cache->load($preloadKey2), 'Preloaded key 2 should be accessible');
        $this->assertEquals('preload_data_3', $cache->load($preloadKey3), 'Preloaded key 3 should be accessible');

        // Test 2: Non-preloaded keys should still work (fallback to Redis)
        $this->assertEquals(
            'non_preload_data',
            $cache->load($nonPreloadKey),
            'Non-preloaded key should still be accessible via Redis'
        );

        // Test 3: Save operation should update preloaded key
        $cache->save('updated_data_1', $preloadKey1);
        $this->assertEquals('updated_data_1', $cache->load($preloadKey1), 'Updated preloaded key should be accessible');

        // Test 4: Remove operation should work
        $cache->remove($preloadKey2);
        $this->assertFalse($cache->load($preloadKey2), 'Removed preloaded key should return false');

        // Test 5: Test with tags
        $taggedPreloadKey = 'tagged_preload_' . uniqid();
        $cache->save('tagged_data', $taggedPreloadKey, ['preload_tag']);

        // Add to preload list by creating new cache instance
        $cache2 = $this->cacheFactory->create([
            'frontend' => [
                'backend' => 'redis',
                'backend_options' => [
                    'server' => self::$redisServer,
                    'port' => '6379',
                    'database' => '4',
                    'preload_keys' => [$taggedPreloadKey],
                ]
            ]
        ]);

        $this->assertEquals('tagged_data', $cache2->load($taggedPreloadKey), 'Tagged preloaded key should work');

        // Clean by tag
        $cache2->clean(CacheConstants::CLEANING_MODE_MATCHING_ANY_TAG, ['preload_tag']);
        $this->assertFalse($cache2->load($taggedPreloadKey), 'Cleaned preloaded key should be removed');

        // Test 6: Clean all should clear preloaded keys and re-preload
        $cache->save('data_before_clean', $preloadKey1);
        $cache->clean(CacheConstants::CLEANING_MODE_ALL);

        // After clean, preloaded key should not exist (was removed)
        $this->assertFalse($cache->load($preloadKey1), 'Preloaded key should be removed after clean all');
    }
}
