<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Magento\Framework\Cache;

use Psr\Log\LoggerInterface;

/**
 * Namespace tag generation for AND logic in tag-based cache invalidation
 * 
 * Generates composite "namespace" tags that represent all possible tag combinations.
 * This enables TRUE AND logic using only OR-based tag invalidation.
 */
trait NamespaceTagGeneratorTrait
{
    /**
     * Namespace prefix for composite tags
     */
    private const NAMESPACE_PREFIX = 'NS_';

    /**
     * Separator for namespace tag combinations
     * Note: Cannot use ":" as it's reserved by Symfony Cache
     */
    private const NAMESPACE_SEPARATOR = '|';

    /**
     * Maximum tags before fallback (prevents combinatorial explosion)
     * 10 tags = 1023 combinations (2^10 - 1)
     */
    private const MAX_TAGS_FOR_FULL_NAMESPACE = 10;

    /**
     * @var LoggerInterface|null
     */
    private ?LoggerInterface $logger = null;

    /**
     * Generate all possible namespace tag combinations
     * 
     * For tags ['A', 'B', 'C'], generates:
     * - Single: NS_A, NS_B, NS_C
     * - Pairs: NS_A|B, NS_A|C, NS_B|C  
     * - Triple: NS_A|B|C
     * 
     * Uses bit manipulation for efficient subset generation.
     *
     * @param array $sortedTags Sorted array of tag names
     * @return array Array of namespace tags
     */
    public function generateNamespaceTags(array $sortedTags): array
    {
        $count = count($sortedTags);
        
        if ($count === 0) {
            return [];
        }

        // Handle single tag (optimization)
        if ($count === 1) {
            return [self::NAMESPACE_PREFIX . $sortedTags[0]];
        }

        // Limit to prevent combinatorial explosion
        if ($count > self::MAX_TAGS_FOR_FULL_NAMESPACE) {
            if ($this->logger) {
                $this->logger->warning('Too many tags for full namespace generation', [
                    'count' => $count,
                    'limit' => self::MAX_TAGS_FOR_FULL_NAMESPACE
                ]);
            }
            // Fallback: just create the full namespace tag
            return [self::NAMESPACE_PREFIX . implode(self::NAMESPACE_SEPARATOR, $sortedTags)];
        }

        $namespaceTags = [];
        
        // Generate all non-empty subsets using bit manipulation
        // For n tags, we have 2^n - 1 non-empty subsets
        $totalCombinations = (1 << $count) - 1;
        
        for ($i = 1; $i <= $totalCombinations; $i++) {
            $combination = [];
            for ($j = 0; $j < $count; $j++) {
                // Check if j-th bit is set
                if ($i & (1 << $j)) {
                    $combination[] = $sortedTags[$j];
                }
            }
            
            // Create namespace tag (already sorted because input tags are sorted)
            $namespaceTags[] = self::NAMESPACE_PREFIX . implode(self::NAMESPACE_SEPARATOR, $combination);
        }
        
        return $namespaceTags;
    }

    /**
     * Create namespace tag for a specific tag combination (for clean operations)
     *
     * @param array $sortedTags Sorted array of tag names
     * @return string Namespace tag representing the combination
     */
    public function createNamespaceTag(array $sortedTags): string
    {
        if (empty($sortedTags)) {
            return '';
        }

        return self::NAMESPACE_PREFIX . implode(self::NAMESPACE_SEPARATOR, $sortedTags);
    }

    /**
     * Set logger for warning messages
     *
     * @param LoggerInterface $logger
     * @return void
     */
    protected function setNamespaceLogger(LoggerInterface $logger): void
    {
        $this->logger = $logger;
    }
}

