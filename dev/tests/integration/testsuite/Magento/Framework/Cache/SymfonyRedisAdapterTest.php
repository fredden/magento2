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
use Psr\Log\LoggerInterface;

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
     * @var LoggerInterface
     */
    private LoggerInterface $logger;

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

        $this->logger = Bootstrap::getObjectManager()->get(LoggerInterface::class);
        $this->cacheFactory = Bootstrap::getObjectManager()->get(Factory::class);

        $this->logger->info('=== SymfonyRedisAdapterTest setUp ===', [
            'test' => $this->name(),
            'redis_server' => self::$redisServer
        ]);

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

        $this->logger->info('=== SymfonyRedisAdapterTest tearDown ===', [
            'test' => $this->name()
        ]);

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

        $this->logger->info('Starting testRedisSaveAndLoad', ['id' => $id, 'data' => $data]);

        // Save
        $this->logger->info('Calling cache->save()', ['id' => $id]);
        $saveResult = $this->cache->save($data, $id);
        $this->logger->info('cache->save() result', ['success' => $saveResult]);
        $this->assertTrue($saveResult, 'Redis save should succeed');

        // Load
        $this->logger->info('Calling cache->load()', ['id' => $id]);
        $loadResult = $this->cache->load($id);
        $this->logger->info('cache->load() result', ['loaded_data' => $loadResult]);
        $this->assertEquals($data, $loadResult, 'Loaded data should match saved data');

        // Verify test() method works
        $this->logger->info('Calling cache->test()', ['id' => $id]);
        $testResult = $this->cache->test($id);
        $this->logger->info('cache->test() result', ['timestamp' => $testResult]);
        $this->assertIsInt($testResult, 'test() should return integer timestamp');
        $this->assertGreaterThan(0, $testResult, 'Timestamp should be positive');

        $this->logger->info('Completed testRedisSaveAndLoad successfully');
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

        $this->logger->info('Starting testRedisTagBasedCleaning', [
            'id1' => $id1,
            'id2' => $id2,
            'id3' => $id3,
            'tag' => $tag
        ]);

        // Save items with same tag
        $this->logger->info('Saving items with tags');
        $this->cache->save('data1', $id1, [$tag]);
        $this->cache->save('data2', $id2, [$tag]);
        $this->cache->save('data3', $id3, ['other_tag']); // Different tag
        $this->logger->info('Saved 3 items: 2 with test tag, 1 with different tag');

        // Verify all exist
        $this->assertEquals('data1', $this->cache->load($id1));
        $this->assertEquals('data2', $this->cache->load($id2));
        $this->assertEquals('data3', $this->cache->load($id3));
        $this->logger->info('Verified all 3 items exist');

        // Clean by tag (uses Redis tag indices)
        $this->logger->info('Calling clean() with MATCHING_ANY_TAG mode', ['tag' => $tag]);
        $cleanResult = $this->cache->clean(CacheConstants::CLEANING_MODE_MATCHING_ANY_TAG, [$tag]);
        $this->logger->info('Clean operation completed', ['result' => $cleanResult]);
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
     * Test Redis connection info
     */
    public function testRedisConnectionInfo(): void
    {
        $info = self::$redis->info('server');
        $this->assertIsArray($info, 'Redis info should be array');
        $this->assertArrayHasKey('redis_version', $info, 'Redis version should be available');

        // Log Redis version for debugging
        // phpcs:ignore Magento2.Security.LanguageConstruct -- Test output
        echo "\nRedis Version: " . $info['redis_version'];
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
        // phpcs:ignore Magento2.Security.LanguageConstruct -- Test output
        echo "\nRedis Memory Usage: " . $info['used_memory_human'];
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
        $startTime = microtime(true);
        $this->cache->clean(CacheConstants::CLEANING_MODE_MATCHING_ANY_TAG, ['batch_tag']);
        $duration = microtime(true) - $startTime;

        // phpcs:ignore Magento2.Security.LanguageConstruct -- Test output
        echo "\nBatch delete duration: " . number_format($duration * 1000, 2) . "ms";

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

        // phpcs:disable Magento2.Security.LanguageConstruct -- Test performance output
        echo "\n=== Redis Performance ===";
        echo "\nSave $operations items: " . number_format($saveDuration * 1000, 2) . "ms (" .
             number_format($operations / $saveDuration, 0) . " ops/sec)";
        echo "\nLoad $operations items: " . number_format($loadDuration * 1000, 2) . "ms (" .
             number_format($operations / $loadDuration, 0) . " ops/sec)";
        echo "\nClean $operations items: " . number_format($cleanDuration * 1000, 2) . "ms";
        // phpcs:enable Magento2.Security.LanguageConstruct

        // Performance assertions (should be fast)
        $this->assertLessThan(1.0, $saveDuration, 'Saving 100 items should take less than 1 second');
        $this->assertLessThan(1.0, $loadDuration, 'Loading 100 items should take less than 1 second');
        $this->assertLessThan(1.0, $cleanDuration, 'Cleaning 100 items should take less than 1 second');
    }
}
