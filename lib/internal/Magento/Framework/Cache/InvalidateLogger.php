<?php
/**
 * Cache configuration model. Provides cache configuration data to the application
 *
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Framework\Cache;

use Magento\Framework\App\Request\Http as HttpRequest;
use Psr\Log\LoggerInterface as Logger;

/**
 * Invalidate logger cache.
 */
class InvalidateLogger
{
    /**
     * @var HttpRequest
     */
    private $request;

    /**
     * @var Logger
     */
    private $logger;

    /**
     * Static flag to ensure env config is logged only once
     *
     * @var bool
     */
    private static $envConfigLogged = false;

    /**
     * @param HttpRequest $request
     * @param Logger $logger
     */
    public function __construct(HttpRequest $request, Logger $logger)
    {
        $this->request = $request;
        $this->logger = $logger;
    }

    /**
     * Logger invalidate cache
     *
     * @param mixed $invalidateInfo
     * @return void
     */
    public function execute($invalidateInfo)
    {
        // Log env.php cache configuration once
        if (!self::$envConfigLogged) {
            $this->logEnvCacheConfig();
            self::$envConfigLogged = true;
        }

        $this->logger->debug('cache_invalidate: ', $this->makeParams($invalidateInfo));
    }

    /**
     * Log env.php cache configuration (one time only)
     *
     * @return void
     */
    private function logEnvCacheConfig()
    {
        try {
            // phpcs:ignore Magento2.Security.IncludeFile.FoundIncludeFile
            $envConfig = include BP . '/app/etc/env.php';
            $cacheConfig = $envConfig['cache'] ?? [];
            $sessionConfig = $envConfig['session'] ?? [];

            // Check if igbinary extension is loaded
            $igbinaryActive = extension_loaded('igbinary');
            $igbinaryVersion = $igbinaryActive ? phpversion('igbinary') : 'N/A';

            $this->logger->debug(
                'ENV.PHP Cache Configuration (logged once per request lifecycle):',
                [
                    'cache_config' => $cacheConfig,
                    'session_config' => $sessionConfig,
                    'igbinary_active' => $igbinaryActive,
                    'igbinary_version' => $igbinaryVersion,
                    'timestamp' => date('Y-m-d H:i:s')
                ]
            );
        } catch (\Exception $e) {
            // Silently fail if unable to read env.php
            $this->logger->debug('Unable to read env.php cache config: ' . $e->getMessage());
        }
    }

    /**
     * Make extra data to logger message
     *
     * @param mixed $invalidateInfo
     * @return array
     */
    private function makeParams($invalidateInfo)
    {
        $method = $this->request->getMethod();
        $url = $this->request->getUriString();
        return compact('method', 'url', 'invalidateInfo');
    }

    /**
     * Log critical
     *
     * @param string $message
     * @param mixed $params
     * @return void
     */
    public function critical($message, $params)
    {
        $this->logger->critical($message, $this->makeParams($params));
    }

    /**
     * Log warning
     *
     * @param string $message
     * @param mixed $params
     * @return void
     */
    public function warning($message, $params)
    {
        $this->logger->warning($message, $this->makeParams($params));
    }
}
