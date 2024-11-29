<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\NewRelicReporting\Model;

use Throwable;

/**
 * Wrapper for New Relic functions
 *
 * @codeCoverageIgnore
 */
class NewRelicWrapper
{
    private const NEWRELIC_APPNAME = 'newrelic.appname';
    private const NEWRELIC_AUTO_INSTRUMENT = 'newrelic.browser_monitoring.auto_instrument';

    /**
     * Wrapper for 'newrelic_add_custom_parameter' function
     *
     * @param string $param
     * @param string|int $value
     * @return bool
     */
    public function addCustomParameter($param, $value)
    {
        if ($this->isExtensionInstalled()) {
            newrelic_add_custom_parameter($param, $value);
            return true;
        }
        return false;
    }

    /**
     * Wrapper for 'newrelic_notice_error' function
     *
     * @param Throwable $exception
     * @return void
     */
    public function reportError(Throwable $exception)
    {
        if ($this->isExtensionInstalled()) {
            newrelic_notice_error($exception->getMessage(), $exception);
        }
    }

    /**
     * Wrapper for 'newrelic_set_appname'
     *
     * @param string $appName
     * @return void
     */
    public function setAppName(string $appName)
    {
        if ($this->isExtensionInstalled()) {
            newrelic_set_appname($appName);
        }
    }

    /**
     * Wrapper for 'newrelic_name_transaction'
     *
     * @param string $transactionName
     * @return void
     */
    public function setTransactionName(string $transactionName): void
    {
        if ($this->isExtensionInstalled()) {
            newrelic_name_transaction($transactionName);
        }
    }

    /**
     * Wrapper to start background transaction
     *
     * @return void
     */
    public function startBackgroundTransaction()
    {
        if ($this->isExtensionInstalled()) {
            newrelic_start_transaction(ini_get(self::NEWRELIC_APPNAME));
            newrelic_background_job();
        }
    }

    /**
     * Wrapper for 'newrelic_end_transaction'
     *
     * @param bool $ignore
     * @return void
     */
    public function endTransaction($ignore = false)
    {
        if ($this->isExtensionInstalled()) {
            newrelic_end_transaction($ignore);
        }
    }

    /**
     * Checks whether newrelic-php5 agent is installed
     *
     * @return bool
     */
    public function isExtensionInstalled()
    {
        return extension_loaded('newrelic');
    }

    /**
     * Checks whether automatic injection of the browser monitoring is enabled
     *
     * @return bool
     */
    public function isAutoInstrumentEnabled(): bool
    {
        return $this->isExtensionInstalled() && ini_get(self::NEWRELIC_AUTO_INSTRUMENT);
    }

    /**
     * Wrapper for 'newrelic_disable_autorum'
     *
     * @return bool|null
     */
    public function disableAutorum(): ?bool
    {
        if (!$this->isExtensionInstalled()) {
            return null;
        }

        return newrelic_disable_autorum();
    }

    /**
     * Wrapper for 'newrelic_get_browser_timing_header'
     *
     * @param bool $includeTags
     * @return string|null
     */
    public function getBrowserTimingHeader(bool $includeTags = true): ?string
    {
        if (!$this->isExtensionInstalled()) {
            return null;
        }

        return newrelic_get_browser_timing_header($includeTags);
    }

    /**
     * Wrapper for 'newrelic_get_browser_timing_footer'
     *
     * @param bool $includeTags
     * @return string|null
     */
    public function getBrowserTimingFooter(bool $includeTags = true): ?string
    {
        if (!$this->isExtensionInstalled()) {
            return null;
        }

        return newrelic_get_browser_timing_footer($includeTags);
    }
}
