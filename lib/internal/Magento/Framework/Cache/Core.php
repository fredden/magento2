<?php
/**
 * Copyright 2011 Adobe
 * All Rights Reserved.
 */
namespace Magento\Framework\Cache;

use Magento\Framework\Cache\Backend\Redis;
use Zend_Cache;
use Zend_Cache_Exception;

/**
 * Legacy cache core for backward compatibility
 *
 * Performance optimizations:
 * - Cached ID cleaning
 * - Cached backend type check
 * - Optimized regex operations
 *
 * @deprecated No longer used in production. All cache operations now use Symfony cache adapter.
 * @see \Magento\Framework\Cache\Frontend\Adapter\Symfony
 */
class Core extends \Zend_Cache_Core
{
    /**
     * Available options
     *
     * ====> (array) backend_decorators :
     * - array of decorators to decorate cache backend. Each element of this array should contain:
     * -- 'class' - concrete decorator, descendant of \Magento\Framework\Cache\Backend\Decorator\AbstractDecorator
     * -- 'options' - optional array of specific decorator options
     * @var array
     */
    protected $_specificOptions = ['backend_decorators' => [], 'disable_save' => false];

    /**
     * Cache for cleaned IDs (performance optimization)
     *
     * @var array
     */
    private array $cleanedIds = [];

    /**
     * Cached check if backend is Redis
     *
     * @var bool|null
     */
    private ?bool $isRedisBackend = null;

    /**
     * Make and return a cache id
     *
     * Checks 'cache_id_prefix' and returns new id with prefix or simply the id if null
     *
     * Performance optimizations:
     * - Cached ID cleaning results
     * - Optimized regex (single operation)
     * - Early returns
     *
     * @param  string $cacheId Cache id
     * @return string Cache id (with or without prefix)
     */
    protected function _id($cacheId)
    {
        if ($cacheId === null) {
            return null;
        }

        // Check cache first
        if (isset($this->cleanedIds[$cacheId])) {
            return $this->cleanedIds[$cacheId];
        }

        $original = $cacheId;
        
        // Optimize: Single operation for dot replacement
        $cacheId = str_replace('.', '__', $cacheId);
        
        // Optimize: Single regex operation
        $cacheId = preg_replace('/[^a-zA-Z0-9_]/', '_', $cacheId);
        
        // Add prefix if configured
        if (isset($this->_options['cache_id_prefix'])) {
            $cacheId = $this->_options['cache_id_prefix'] . $cacheId;
        }

        // Cache the result (limit to 1000 entries)
        if (count($this->cleanedIds) < 1000) {
            $this->cleanedIds[$original] = $cacheId;
        }

        return $cacheId;
    }

    /**
     * Prepare tags
     *
     * @param string[] $tags
     * @return string[]
     */
    protected function _tags($tags)
    {
        foreach ($tags as $key => $tag) {
            $tags[$key] = $this->_id($tag);
        }
        return $tags;
    }

    /**
     * @inheritDoc
     */
    public function save($data, $cacheId = null, $tags = [], $specificLifetime = false, $priority = 8)
    {
        if ($this->getOption('disable_save')) {
            return true;
        }
        $tags = $this->_tags($tags);
        return parent::save($data, $cacheId, $tags, $specificLifetime, $priority);
    }

    /**
     * Clean cache entries
     *
     * Available modes are :
     * 'all' (default)  => remove all cache entries ($tags is not used)
     * 'old'            => remove too old cache entries ($tags is not used)
     * 'matchingTag'    => remove cache entries matching all given tags
     *                     ($tags can be an array of strings or a single string)
     * 'notMatchingTag' => remove cache entries not matching one of the given tags
     *                     ($tags can be an array of strings or a single string)
     * 'matchingAnyTag' => remove cache entries matching any given tags
     *                     ($tags can be an array of strings or a single string)
     *
     * @param string $mode
     * @param string[] $tags
     * @throws \Zend_Cache_Exception
     * @return bool True if ok
     */
    public function clean($mode = 'all', $tags = [])
    {
        $tags = $this->_tags($tags);
        return parent::clean($mode, $tags);
    }

    /**
     * Return an array of stored cache ids which match given tags
     *
     * In case of multiple tags, a logical AND is made between tags
     *
     * @param string[] $tags array of tags
     * @return string[] array of matching cache ids (string)
     */
    public function getIdsMatchingTags($tags = [])
    {
        $tags = $this->_tags($tags);
        return parent::getIdsMatchingTags($tags);
    }

    /**
     * Return an array of stored cache ids which don't match given tags
     *
     * In case of multiple tags, a logical OR is made between tags
     *
     * @param string[] $tags array of tags
     * @return string[] array of not matching cache ids (string)
     */
    public function getIdsNotMatchingTags($tags = [])
    {
        $tags = $this->_tags($tags);
        return parent::getIdsNotMatchingTags($tags);
    }

    /**
     * Validate a cache id or a tag (security, reliable filenames, reserved prefixes...)
     *
     * Throw an exception if a problem is found
     *
     * Performance optimization:
     * - Cached backend type check (instanceof is expensive)
     *
     * @param  string $string Cache id or tag
     * @throws Zend_Cache_Exception
     * @return void
     */
    protected function _validateIdOrTag($string)
    {
        // Cache the instanceof check (performance optimization)
        if ($this->isRedisBackend === null) {
            $this->isRedisBackend = $this->_backend instanceof Redis;
        }

        if ($this->isRedisBackend) {
            if (!is_string($string)) {
                Zend_Cache::throwException('Invalid id or tag : must be a string');
            }
            if (strpos($string, 'internal-') === 0) {
                Zend_Cache::throwException('"internal-*" ids or tags are reserved');
            }
            if (!preg_match('~^[a-zA-Z0-9_{}]+$~D', $string)) {
                Zend_Cache::throwException("Invalid id or tag '$string' : must use only [a-zA-Z0-9_{}]");
            }

            return;
        }

        parent::_validateIdOrTag($string);
    }

    /**
     * Set the backend
     *
     * @param  \Zend_Cache_Backend $backendObject
     * @return void
     */
    public function setBackend(\Zend_Cache_Backend $backendObject)
    {
        $backendObject = $this->_decorateBackend($backendObject);
        parent::setBackend($backendObject);
        
        // Reset cached backend type check
        $this->isRedisBackend = null;
    }

    /**
     * Decorate cache backend with additional functionality
     *
     * @param \Zend_Cache_Backend $backendObject
     * @return \Zend_Cache_Backend
     */
    protected function _decorateBackend(\Zend_Cache_Backend $backendObject)
    {
        if (!is_array($this->_specificOptions['backend_decorators'])) {
            \Zend_Cache::throwException("'backend_decorator' option should be an array");
        }

        foreach ($this->_specificOptions['backend_decorators'] as $decoratorName => $decoratorOptions) {
            if (!is_array($decoratorOptions) || !array_key_exists('class', $decoratorOptions)) {
                \Zend_Cache::throwException(
                    "Concrete decorator options in '" . $decoratorName . "' should be an array containing 'class' key"
                );
            }
            $classOptions = array_key_exists('options', $decoratorOptions) ? $decoratorOptions['options'] : [];
            $classOptions['concrete_backend'] = $backendObject;

            if (!class_exists($decoratorOptions['class'])) {
                \Zend_Cache::throwException(
                    "Class '" . $decoratorOptions['class'] . "' specified in '" . $decoratorName . "' does not exist"
                );
            }

            $backendObject = new $decoratorOptions['class']($classOptions);
            if (!$backendObject instanceof \Magento\Framework\Cache\Backend\Decorator\AbstractDecorator) {
                \Zend_Cache::throwException(
                    "Decorator in '" .
                    $decoratorName .
                    "' should extend \Magento\Framework\Cache\Backend\Decorator\AbstractDecorator"
                );
            }
        }

        return $backendObject;
    }

    /**
     * Disable show internals with var_dump
     *
     * @see https://www.php.net/manual/en/language.oop5.magic.php#object.debuginfo
     * @return array
     */
    public function __debugInfo()
    {
        return [];
    }
}
