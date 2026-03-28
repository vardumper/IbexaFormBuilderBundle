<?php

declare(strict_types=1);

use Symfony\Component\Form\FormInterface;
use Symfony\Component\Mime\Email;
use vardumper\IbexaFormBuilderBundle\Entity\FormSubmission;
use vardumper\IbexaFormBuilderBundle\Event\{AbstractCancellableEvent, PostStoreSubmissionEvent, PostSubmitEvent, PreSendEmailEvent, PreStoreSubmissionEvent, PreSubmitEvent, PreValidationEvent};

// AbstractCancellableEvent — cancel() / isCancelled() behaviour

it('starts uncancelled', function () {
    $event = new PreSubmitEvent(1, [], null);

    expect($event->isCancelled())->toBeFalse();
});

it('reflects cancelled state after cancel()', function () {
    $event = new PreSubmitEvent(1, [], null);
    $event->cancel();

    expect($event->isCancelled())->toBeTrue();
});

it('does not call stopPropagation() when cancelled', function () {
    $event = new PreValidationEvent(testMock(FormInterface::class), 1);
    $event->cancel();

    expect($event->isPropagationStopped())->toBeFalse();
});

it('cancel() is idempotent', function () {
    $event = new PreSubmitEvent(1, [], null);
    $event->cancel();
    $event->cancel();

    expect($event->isCancelled())->toBeTrue();
});

// PreValidationEvent

it('PreValidationEvent exposes form and contentId', function () {
    $form = testMock(FormInterface::class);
    $event = new PreValidationEvent($form, 42);

    expect($event->getForm())->toBe($form)
        ->and($event->getContentId())->toBe(42);
});

it('PreValidationEvent is cancellable', function () {
    $event = new PreValidationEvent(testMock(FormInterface::class), 1);
    $event->cancel();

    expect($event->isCancelled())->toBeTrue();
});

// PreSubmitEvent

it('PreSubmitEvent exposes contentId, data and ipAddress', function () {
    $event = new PreSubmitEvent(7, ['name' => 'Alice'], '10.0.0.1');

    expect($event->getContentId())->toBe(7)
        ->and($event->getData())->toBe(['name' => 'Alice'])
        ->and($event->getIpAddress())->toBe('10.0.0.1');
});

it('PreSubmitEvent setData() mutates data and returns self', function () {
    $event = new PreSubmitEvent(1, ['orig' => true], null);
    $result = $event->setData(['replaced' => true]);

    expect($result)->toBe($event)
        ->and($event->getData())->toBe(['replaced' => true]);
});

it('PreSubmitEvent accepts null ipAddress', function () {
    $event = new PreSubmitEvent(1, [], null);

    expect($event->getIpAddress())->toBeNull();
});

// PreStoreSubmissionEvent

it('PreStoreSubmissionEvent exposes the submission entity', function () {
    $submission = FormSubmission::create(3, ['x' => 1], null);
    $event = new PreStoreSubmissionEvent($submission);

    expect($event->getSubmission())->toBe($submission);
});

it('PreStoreSubmissionEvent is cancellable', function () {
    $event = new PreStoreSubmissionEvent(FormSubmission::create(1, [], null));
    $event->cancel();

    expect($event->isCancelled())->toBeTrue();
});

// PostStoreSubmissionEvent

it('PostStoreSubmissionEvent exposes the submission entity', function () {
    $submission = FormSubmission::create(5, ['y' => 2], '1.2.3.4');
    $event = new PostStoreSubmissionEvent($submission);

    expect($event->getSubmission())->toBe($submission);
});

it('PostStoreSubmissionEvent is not cancellable', function () {
    $event = new PostStoreSubmissionEvent(FormSubmission::create(1, [], null));

    expect($event)->not->toBeInstanceOf(AbstractCancellableEvent::class);
});

// PreSendEmailEvent

it('PreSendEmailEvent exposes email, contentId and data', function () {
    $email = new Email();
    $event = new PreSendEmailEvent($email, 99, ['field' => 'val']);

    expect($event->getEmail())->toBe($email)
        ->and($event->getContentId())->toBe(99)
        ->and($event->getData())->toBe(['field' => 'val']);
});

it('PreSendEmailEvent is cancellable', function () {
    $event = new PreSendEmailEvent(new Email(), 1, []);
    $event->cancel();

    expect($event->isCancelled())->toBeTrue();
});

// PostSubmitEvent

it('PostSubmitEvent exposes contentId, data and submission', function () {
    $submission = FormSubmission::create(10, ['z' => 3], null);
    $event = new PostSubmitEvent(10, ['z' => 3], $submission);

    expect($event->getContentId())->toBe(10)
        ->and($event->getData())->toBe(['z' => 3])
        ->and($event->getSubmission())->toBe($submission);
});

it('PostSubmitEvent allows null submission', function () {
    $event = new PostSubmitEvent(1, [], null);

    expect($event->getSubmission())->toBeNull();
});

it('PostSubmitEvent is not cancellable', function () {
    $event = new PostSubmitEvent(1, [], null);

    expect($event)->not->toBeInstanceOf(AbstractCancellableEvent::class);
});
