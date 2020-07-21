<?php

declare(strict_types=1);

use Rector\PHPUnit\Rector\Class_\AddSeeTestAnnotationRector;
use Rector\PHPUnit\Rector\MethodCall\RemoveExpectAnyFromMockRector;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

return static function (ContainerConfigurator $containerConfigurator): void {
    # [configurable]
    # Rector\PHPUnit\Rector\Class_\ArrayArgumentInTestToDataProviderRector: null
    $services = $containerConfigurator->services();

    $services->set(RemoveExpectAnyFromMockRector::class);

    $services->set(AddSeeTestAnnotationRector::class);
};