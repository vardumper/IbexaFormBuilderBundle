<?php

declare(strict_types=1);

use Symfony\Component\DependencyInjection\ContainerBuilder;
use vardumper\IbexaFormBuilderBundle\DependencyInjection\IbexaFormBuilderExtension;

it('sets the ibexa_form_builder.from_email container parameter', function () {
    $container = new ContainerBuilder();
    $extension = new IbexaFormBuilderExtension();
    $extension->load([['from_email' => 'test@example.com']], $container);
    expect($container->getParameter('ibexa_form_builder.from_email'))->toBe('test@example.com');
});

it('sets empty from_email by default', function () {
    $container = new ContainerBuilder();
    $extension = new IbexaFormBuilderExtension();
    $extension->load([[]], $container);
    expect($container->getParameter('ibexa_form_builder.from_email'))->toBe('');
});

it('prepend() registers twig path for IbexaFormBuilderBundle', function () {
    $container = new ContainerBuilder();
    $extension = new IbexaFormBuilderExtension();
    $extension->prepend($container);

    $twigConfigs = $container->getExtensionConfig('twig');
    $paths = array_merge(...array_column($twigConfigs, 'paths'));

    expect(in_array('IbexaFormBuilderBundle', array_values($paths), true))->toBeTrue();
});

it('prepend() registers doctrine ORM attribute mapping for IbexaFormBuilder', function () {
    $container = new ContainerBuilder();
    $extension = new IbexaFormBuilderExtension();
    $extension->prepend($container);

    $doctrineConfigs = $container->getExtensionConfig('doctrine');
    $mappings = array_merge(...array_column(array_column($doctrineConfigs, 'orm'), 'mappings'));

    expect($mappings)->toHaveKey('IbexaFormBuilder')
        ->and($mappings['IbexaFormBuilder']['type'])->toBe('attribute')
        ->and($mappings['IbexaFormBuilder']['alias'])->toBe('IbexaFormBuilder');
});
