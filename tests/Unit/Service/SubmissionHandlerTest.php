<?php

declare(strict_types=1);

use Ibexa\Contracts\Core\Repository\ContentService;
use Ibexa\Contracts\Core\Repository\Values\Content\Content;
use Ibexa\Contracts\Core\Repository\Values\Content\Field;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use vardumper\IbexaFormBuilderBundle\Event\{PostSubmitEvent, PreSubmitEvent};
use vardumper\IbexaFormBuilderBundle\Service\SubmissionHandler;

function makeHandler(
    Content $content,
    ?EventDispatcherInterface $dispatcher = null,
): SubmissionHandler {
    $contentService = testMock(ContentService::class);
    $contentService->method('loadContent')->willReturn($content);

    $dispatcher ??= testMock(EventDispatcherInterface::class);
    $dispatcher->method('dispatch')->willReturnArgument(0);

    return new SubmissionHandler($contentService, $dispatcher);
}

function makeField(string $identifier, mixed $value): Field
{
    return new Field(['fieldDefIdentifier' => $identifier, 'value' => $value]);
}

function makeSelectionValue(string $option): stdClass
{
    $v = new stdClass();
    $v->selection = [$option];

    return $v;
}

function makeContent(string $submissionAction): Content
{
    $content = testMock(Content::class);
    $content->method('getFields')->willReturn([makeField('submission_action', makeSelectionValue($submissionAction))]);
    $content->method('getName')->willReturn('Test Form');

    return $content;
}

it('dispatches PreSubmitEvent', function () {
    $preSubmitDispatched = false;
    $dispatcher = testMock(EventDispatcherInterface::class);
    $dispatcher->method('dispatch')->willReturnCallback(function (object $event) use (&$preSubmitDispatched) {
        if ($event instanceof PreSubmitEvent) {
            $preSubmitDispatched = true;
        }

        return $event;
    });

    makeHandler(makeContent('store'), $dispatcher)->handle(1, ['field' => 'val'], null);

    expect($preSubmitDispatched)->toBeTrue();
});

it('dispatches PostSubmitEvent when PreSubmitEvent is not cancelled', function () {
    $dispatcher = testMock(EventDispatcherInterface::class);
    $dispatcher->method('dispatch')->willReturnArgument(0);

    $postSubmitDispatched = false;
    $dispatcher->method('dispatch')->willReturnCallback(function (object $event, string $name) use (&$postSubmitDispatched) {
        if ($event instanceof PostSubmitEvent) {
            $postSubmitDispatched = true;
        }

        return $event;
    });

    makeHandler(makeContent('store'), $dispatcher)->handle(1, ['field' => 'val'], null);

    expect($postSubmitDispatched)->toBeTrue();
});

it('does not dispatch PostSubmitEvent when PreSubmitEvent is cancelled', function () {
    $dispatcher = testMock(EventDispatcherInterface::class);

    $postSubmitDispatched = false;
    $dispatcher->method('dispatch')->willReturnCallback(function (object $event, string $name) use (&$postSubmitDispatched) {
        if ($event instanceof PreSubmitEvent) {
            $event->cancel();
        }
        if ($event instanceof PostSubmitEvent) {
            $postSubmitDispatched = true;
        }

        return $event;
    });

    makeHandler(makeContent('store'), $dispatcher)->handle(1, ['field' => 'val'], null);

    expect($postSubmitDispatched)->toBeFalse();
});

it('strips Symfony internal fields before dispatching PostSubmitEvent', function () {
    $capturedData = null;
    $dispatcher = testMock(EventDispatcherInterface::class);
    $dispatcher->method('dispatch')->willReturnCallback(function (object $event) use (&$capturedData) {
        if ($event instanceof PostSubmitEvent) {
            $capturedData = $event->getData();
        }

        return $event;
    });

    makeHandler(makeContent('store'), $dispatcher)->handle(1, ['_token' => 'xyz', 'name' => 'Alice'], null);

    expect($capturedData)->toBe(['name' => 'Alice']);
});

it('passes submissionAction to PostSubmitEvent', function () {
    $capturedAction = 'not-set';
    $dispatcher = testMock(EventDispatcherInterface::class);
    $dispatcher->method('dispatch')->willReturnCallback(function (object $event) use (&$capturedAction) {
        if ($event instanceof PostSubmitEvent) {
            $capturedAction = $event->getSubmissionAction();
        }

        return $event;
    });

    makeHandler(makeContent('email'), $dispatcher)->handle(1, [], null);

    expect($capturedAction)->toBe('email');
});
