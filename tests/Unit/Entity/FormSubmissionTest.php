<?php

declare(strict_types=1);

use vardumper\IbexaFormBuilderBundle\Entity\FormSubmission;

it('creates a submission via the named constructor', function () {
    $submission = FormSubmission::create(42, ['name' => 'Alice'], '127.0.0.1');

    expect($submission->getContentId())->toBe(42)
        ->and($submission->getData())->toBe(['name' => 'Alice'])
        ->and($submission->getIpAddress())->toBe('127.0.0.1')
        ->and($submission->getSubmittedAt())->toBeInstanceOf(DateTimeImmutable::class)
        ->and($submission->getId())->toBeNull();
});

it('allows null ip address', function () {
    $submission = FormSubmission::create(1, []);
    expect($submission->getIpAddress())->toBeNull();
});

it('persists and retrieves a submission via SQLite', function () {
    $em = sqliteEm();
    $submission = FormSubmission::create(99, ['field' => 'value'], '10.0.0.1');
    $em->persist($submission);
    $em->flush();

    $found = $em->find(FormSubmission::class, $submission->getId());
    expect($found)->not->toBeNull()
        ->and($found->getContentId())->toBe(99)
        ->and($found->getData())->toBe(['field' => 'value']);
});
