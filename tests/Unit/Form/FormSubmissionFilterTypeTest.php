<?php

declare(strict_types=1);

use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Forms;
use vardumper\IbexaFormBuilderBundle\Form\Type\FormSubmissionFilterType;

$factory = Forms::createFormFactory();

it('builds form with expected fields', function () use ($factory) {
    $form = $factory->create(FormSubmissionFilterType::class);

    expect($form->has('contentId'))->toBeTrue()
        ->and($form->has('dateFrom'))->toBeTrue()
        ->and($form->has('dateTo'))->toBeTrue()
        ->and($form->has('filter'))->toBeTrue();
});

it('contentId field is IntegerType and not required', function () use ($factory) {
    $form = $factory->create(FormSubmissionFilterType::class);
    $config = $form->get('contentId')->getConfig();

    expect($config->getType()->getInnerType())->toBeInstanceOf(IntegerType::class)
        ->and($config->getOption('required'))->toBeFalse();
});

it('dateFrom and dateTo fields are DateType with single_text widget', function () use ($factory) {
    $form = $factory->create(FormSubmissionFilterType::class);

    foreach (['dateFrom', 'dateTo'] as $field) {
        $config = $form->get($field)->getConfig();
        expect($config->getType()->getInnerType())->toBeInstanceOf(DateType::class)
            ->and($config->getOption('widget'))->toBe('single_text')
            ->and($config->getOption('required'))->toBeFalse();
    }
});

it('filter field is SubmitType', function () use ($factory) {
    $form = $factory->create(FormSubmissionFilterType::class);
    $config = $form->get('filter')->getConfig();

    expect($config->getType()->getInnerType())->toBeInstanceOf(SubmitType::class);
});

it('configures method GET and disables csrf protection', function () use ($factory) {
    $form = $factory->create(FormSubmissionFilterType::class);
    $config = $form->getConfig();

    expect($config->getMethod())->toBe('GET')
        ->and($config->getOption('csrf_protection'))->toBeFalse();
});
