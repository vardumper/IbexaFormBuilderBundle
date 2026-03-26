<?php

declare(strict_types=1);

use vardumper\IbexaFormBuilderBundle\IbexaFormBuilderBundle;

it('returns the bundle root path', function () {
    $bundle = new IbexaFormBuilderBundle();
    expect($bundle->getPath())->toEndWith('ibexa-form-builder-bundle');
});
