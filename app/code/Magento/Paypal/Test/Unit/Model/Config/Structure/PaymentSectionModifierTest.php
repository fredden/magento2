<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Magento\Paypal\Test\Unit\Model\Config\Structure;

use Magento\Paypal\Model\Config\Structure\PaymentSectionModifier;
use PHPUnit\Framework\TestCase;

class PaymentSectionModifierTest extends TestCase
{
    private static $specialGroups = [
        'account',
        'recommended_solutions',
        'other_paypal_payment_solutions',
        'other_payment_methods',
    ];

    /**
     * @param string $case
     * @param array $structure
     * @dataProvider caseProvider
     */
    public function testSpecialGroupsPresent($case, $structure)
    {
        $modifier = new PaymentSectionModifier();
        $modifiedStructure = $modifier->modify($structure);
        $presentSpecialGroups = array_intersect(
            self::$specialGroups,
            array_keys($modifiedStructure)
        );

        $this->assertEquals(
            self::$specialGroups,
            $presentSpecialGroups,
            sprintf('All special groups must be present in %s case', $case)
        );
    }

    /**
     * @param string $case
     * @param array $structure
     * @dataProvider caseProvider
     */
    public function testOnlySpecialGroupsPresent($case, $structure)
    {
        $modifier = new PaymentSectionModifier();
        $modifiedStructure = $modifier->modify($structure);
        $presentNotSpecialGroups = array_diff(
            array_keys($modifiedStructure),
            self::$specialGroups
        );

        $this->assertEquals(
            [],
            $presentNotSpecialGroups,
            sprintf('Only special groups should be present at top level in "%s" case', $case)
        );
    }

    /**
     * @param string $case
     * @param array $structure
     * @dataProvider caseProvider
     */
    public function testGroupsNotRemovedAfterModification($case, $structure)
    {
        $modifier = new PaymentSectionModifier();
        $modifiedStructure = $modifier->modify($structure);

        $removedGroups = array_diff(
            $this->fetchAllAvailableGroups($structure),
            $this->fetchAllAvailableGroups($modifiedStructure)
        );

        $this->assertEquals(
            [],
            $removedGroups,
            sprintf('Groups should not be removed after modification in "%s" case', $case)
        );
    }

    public function testMovedToTargetSpecialGroup()
    {
        $structure = [
            'some_payment_method1' => [
                'id' => 'some_payment_method1',
                'displayIn' => 'recommended_solutions',
            ],
            'some_group' => [
                'id' => 'some_group',
                'children' => [
                    'some_payment_method2' => [
                        'id' => 'some_payment_method2',
                        'displayIn' => 'recommended_solutions'
                    ],
                    'some_payment_method3' => [
                        'id' => 'some_payment_method3',
                        'displayIn' => 'other_payment_methods'
                    ],
                    'some_payment_method4' => [
                        'id' => 'some_payment_method4',
                        'displayIn' => 'recommended_solutions'
                    ],
                    'some_payment_method5' => [
                        'id' => 'some_payment_method5',
                    ],
                ]
            ],
        ];

        $modifier = new PaymentSectionModifier();
        $modifiedStructure = $modifier->modify($structure);

        $this->assertEquals(
            [
                'account' => [],
                'recommended_solutions' => [
                    'children' => [
                        'some_payment_method1' => [
                            'id' => 'some_payment_method1',
                        ],
                        'some_payment_method2' => [
                            'id' => 'some_payment_method2',
                        ],
                        'some_payment_method4' => [
                            'id' => 'some_payment_method4',
                        ],
                    ],
                ],
                'other_paypal_payment_solutions' => [],
                'other_payment_methods' => [
                    'children' => [
                        'some_payment_method3' => [
                            'id' => 'some_payment_method3',
                        ],
                        'some_group' => [
                            'id' => 'some_group',
                            'children' => [
                                'some_payment_method5' => [
                                    'id' => 'some_payment_method5',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            $modifiedStructure,
            'Some group is not moved correctly'
        );
    }

    /**
     * This helper method walks recursively through configuration structure and
     * collect available configuration groups
     *
     * @param array $structure
     * @return array Sorted list of group identifiers
     */
    private function fetchAllAvailableGroups($structure)
    {
        $availableGroups = [];
        foreach ($structure as $group => $data) {
            $availableGroups[] = [$group];
            if (isset($data['children'])) {
                $availableGroups[] = $this->fetchAllAvailableGroups($data['children']);
            }
        }
        $availableGroups = array_merge([], ...$availableGroups);
        $availableGroups = array_values(array_unique($availableGroups));
        sort($availableGroups);
        return $availableGroups;
    }

    /**
     * @return mixed
     */
    public static function caseProvider()
    {
        return include __DIR__ . '/_files/payment_section_structure_variations.php';
    }
}
