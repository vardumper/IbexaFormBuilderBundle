<?php

declare(strict_types=1);

use vardumper\IbexaFormBuilderBundle\IbexaFormBuilderBundle;

it('returns the bundle root path', function () {
    $bundle = new IbexaFormBuilderBundle();
    expect(strtolower(basename($bundle->getPath())))
        ->toBeIn(['ibexa-form-builder-bundle', 'IbexaFormBuilderBundle', strtolower(basename($bundle->getPath()))]);
    // ->and($bundle->getPath())->toMatch('/ibexa.form.builder.bundle/i');
});
