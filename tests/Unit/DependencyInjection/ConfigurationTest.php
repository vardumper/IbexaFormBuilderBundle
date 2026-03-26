<?php

declare(strict_types=1);

use Symfony\Component\Config\Definition\Processor;
use vardumper\IbexaFormBuilderBundle\DependencyInjection\Configuration;

it('has empty from_email by default', function () {
    $processor = new Processor();
    $config = $processor->processConfiguration(new Configuration(), [[]]);
    expect($config['from_email'])->toBe('');
});

it('accepts a custom from_email', function () {
    $processor = new Processor();
    $config = $processor->processConfiguration(new Configuration(), [['from_email' => 'noreply@example.com']]);
    expect($config['from_email'])->toBe('noreply@example.com');
});

it('last config wins on multi-config merge', function () {
    $processor = new Processor();
    $config = $processor->processConfiguration(new Configuration(), [
        ['from_email' => 'first@example.com'],
        ['from_email' => 'last@example.com'],
    ]);
    expect($config['from_email'])->toBe('last@example.com');
});
