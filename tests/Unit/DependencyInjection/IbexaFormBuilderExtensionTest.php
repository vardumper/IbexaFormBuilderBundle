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
