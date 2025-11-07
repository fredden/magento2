<?php
/**
 * Copyright 2013 Adobe
 * All Rights Reserved.
 */
declare(strict_types=1);

namespace Magento\Framework\Cache\Frontend\Adapter;

use Magento\Framework\Cache\CacheConstants;

/**
 * Adapter for Magento -> Zend cache frontend interfaces
 *
 * @deprecated No longer used in production. All cache operations now use Symfony cache adapter.
 * @see \Magento\Framework\Cache\Frontend\Adapter\Symfony
 */
class Zend implements \Magento\Framework\Cache\FrontendInterface
{
    /**
     * Zend cache frontend instance
     *
     * @var \Zend_Cache_Core
     */
    protected $_frontend;

    /**
     * Factory that creates the Zend_Cache_Core instances
     *
     * @var \Closure
     */
    private $frontendFactory;

    /**
     * Process ID that owns the frontend object
     *
     * @var int
     */
    private $pid;

    /**
     * Parent frontends storage to prevent garbage collection
     *
     * @var array
     */
    private $parentFrontends = [];

    /**
     * Constructor
     *
     * @param \Closure $frontendFactory
     */
    public function __construct(\Closure $frontendFactory)
    {
        $this->frontendFactory = $frontendFactory;
        $this->_frontend = $frontendFactory();
        $this->pid = getmypid();
    }

    /**
     * @inheritDoc
     */
    public function test($identifier)
    {
        return $this->getFrontEnd()->test($this->_unifyId($identifier));
    }

    /**
     * @inheritDoc
     */
    public function load($identifier)
    {
        return $this->getFrontEnd()->load($this->_unifyId($identifier));
    }

    /**
     * @inheritDoc
     */
    public function save($data, $identifier, array $tags = [], $lifeTime = null)
    {
        return $this->getFrontEnd()->save($data, $this->_unifyId($identifier), $this->_unifyIds($tags), $lifeTime);
    }

    /**
     * @inheritDoc
     */
    public function remove($identifier)
    {
        return $this->getFrontEnd()->remove($this->_unifyId($identifier));
    }

    /**
     * @inheritDoc
     *
     * @throws \InvalidArgumentException Exception is thrown when non-supported cleaning mode is specified
     * @throws \Zend_Cache_Exception
     */
    public function clean($mode = CacheConstants::CLEANING_MODE_ALL, array $tags = [])
    {
        // Cleaning modes 'old' and 'notMatchingTag' are prohibited as a trade off for decoration reliability
        if (!in_array(
            $mode,
            [
                CacheConstants::CLEANING_MODE_ALL,
                CacheConstants::CLEANING_MODE_MATCHING_TAG,
                CacheConstants::CLEANING_MODE_MATCHING_ANY_TAG
            ]
        )
        ) {
            throw new \InvalidArgumentException(
                "Magento cache frontend does not support the cleaning mode '{$mode}'."
            );
        }
        return $this->getFrontEnd()->clean($mode, $this->_unifyIds($tags));
    }

    /**
     * @inheritDoc
     */
    public function getBackend()
    {
        return $this->getFrontEnd()->getBackend();
    }

    /**
     * @inheritDoc
     */
    public function getLowLevelFrontend()
    {
        return $this->getFrontEnd();
    }

    /**
     * Retrieve single unified identifier (uppercase conversion)
     *
     * @param string $identifier
     * @return string
     */
    protected function _unifyId($identifier)
    {
        return strtoupper($identifier);
    }

    /**
     * Retrieve multiple unified identifiers (uppercase conversion)
     *
     * @param array $ids
     * @return array
     */
    protected function _unifyIds(array $ids)
    {
        foreach ($ids as $key => $value) {
            $ids[$key] = $this->_unifyId($value);
        }
        return $ids;
    }

    /**
     * Get frontend cache adapter for current process
     *
     * Recreates frontend on process fork to prevent shared resources
     *
     * @return \Zend_Cache_Core
     */
    private function getFrontEnd()
    {
        if (getmypid() === $this->pid) {
            return $this->_frontend;
        }
        // Note: We hide the parent process's frontend so that the destructor won't get called on it.
        // If the destructor were called, then the parent process's connection would be disconnected.
        $this->parentFrontends[] = $this->_frontend;
        $frontendFactory = $this->frontendFactory;
        $this->_frontend = $frontendFactory();
        $this->pid = getmypid();
        return $this->_frontend;
    }
}
