<?php

declare(strict_types=1);

use vardumper\IbexaFormBuilderBundle\Event\FormBuilderEvents;

it('defines all six event name constants as non-empty strings', function () {
    expect(FormBuilderEvents::PRE_VALIDATION)->toBe('ibexa_form_builder.pre_validation')
        ->and(FormBuilderEvents::PRE_SUBMIT)->toBe('ibexa_form_builder.pre_submit')
        ->and(FormBuilderEvents::PRE_STORE_SUBMISSION)->toBe('ibexa_form_builder.pre_store_submission')
        ->and(FormBuilderEvents::POST_STORE_SUBMISSION)->toBe('ibexa_form_builder.post_store_submission')
        ->and(FormBuilderEvents::PRE_SEND_EMAIL)->toBe('ibexa_form_builder.pre_send_email')
        ->and(FormBuilderEvents::POST_SUBMIT)->toBe('ibexa_form_builder.post_submit');
});

it('all event names share the ibexa_form_builder namespace prefix', function () {
    $constants = [
        FormBuilderEvents::PRE_VALIDATION,
        FormBuilderEvents::PRE_SUBMIT,
        FormBuilderEvents::PRE_STORE_SUBMISSION,
        FormBuilderEvents::POST_STORE_SUBMISSION,
        FormBuilderEvents::PRE_SEND_EMAIL,
        FormBuilderEvents::POST_SUBMIT,
    ];

    foreach ($constants as $name) {
        expect($name)->toStartWith('ibexa_form_builder.');
    }
});
