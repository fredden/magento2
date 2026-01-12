<?php

use Rector\Config\RectorConfig;
use Rector\PHPUnit\AnnotationsToAttributes\Rector\ClassMethod\DataProviderAnnotationToAttributeRector;
use Rector\PHPUnit\AnnotationsToAttributes\Rector\Class_\TicketAnnotationToAttributeRector;
use Rector\PHPUnit\AnnotationsToAttributes\Rector\Class_\CoversAnnotationWithValueToAttributeRector;
use Rector\PHPUnit\AnnotationsToAttributes\Rector\ClassMethod\DependsAnnotationWithValueToAttributeRector;
use Rector\PHPUnit\AnnotationsToAttributes\Rector\ClassMethod\TestWithAnnotationToAttributeRector;
use Rector\PHPUnit\PHPUnit100\Rector\Class_\PublicDataProviderClassMethodRector;
use Rector\PHPUnit\PHPUnit100\Rector\Class_\StaticDataProviderClassMethodRector;
use Rector\PHPUnit\PHPUnit110\Rector\Class_\NamedArgumentForDataProviderRector;



return RectorConfig::configure()
    ->withPaths([
        __DIR__ . '/dev/tests/integration/framework',
        __DIR__ . '/app/code/Magento/*/Test/Integration',
        __DIR__ . '/app/code/Magento/*/Test/Functional',
        __DIR__ . '/app/code/Magento/*/Test/Api',
        __DIR__ . '/app/code/Magento/*/Test/Api-Functional',
        __DIR__ . '/app/code/Magento/*/Test/Api-Unit',
        __DIR__ . '/app/code/Magento/*/Test/Api-Integration',
        __DIR__ . '/app/code/Magento/*/Test/Api-Functional',
    ])
    ->withRules([
        DataProviderAnnotationToAttributeRector::class,
        TicketAnnotationToAttributeRector::class,
        CoversAnnotationWithValueToAttributeRector::class,
        DependsAnnotationWithValueToAttributeRector::class,
        TestWithAnnotationToAttributeRector::class,
        PublicDataProviderClassMethodRector::class,
        StaticDataProviderClassMethodRector::class,
        NamedArgumentForDataProviderRector::class
    ]);