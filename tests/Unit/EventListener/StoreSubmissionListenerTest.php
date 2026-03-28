<?php

declare(strict_types=1);

use Doctrine\ORM\EntityManagerInterface;
use Ibexa\Contracts\Core\Repository\Values\Content\Content;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use vardumper\IbexaFormBuilderBundle\Entity\FormSubmission;
use vardumper\IbexaFormBuilderBundle\Event\{FormBuilderEvents, PostSubmitEvent, PreStoreSubmissionEvent};
use vardumper\IbexaFormBuilderBundle\EventListener\StoreSubmissionListener;

function makePostSubmitEvent(string $action, array $data = [], ?string $ip = null): PostSubmitEvent
{
    $content = testMock(Content::class);
    $content->method('getFields')->willReturn([]);

    return new PostSubmitEvent(1, $content, $data, $ip, $action);
}

function makeStoreListener(?EventDispatcherInterface $dispatcher = null): StoreSubmissionListener
{
    $em = testMock(EntityManagerInterface::class);
    $dispatcher ??= testMock(EventDispatcherInterface::class);
    $dispatcher->method('dispatch')->willReturnArgument(0);

    return new StoreSubmissionListener($em, $dispatcher);
}

it('persists and flushes when submissionAction is store', function () {
    $persisted = null;
    $flushed = false;
    $em = testMock(EntityManagerInterface::class);
    $em->method('persist')->willReturnCallback(function (object $e) use (&$persisted) { $persisted = $e; });
    $em->method('flush')->willReturnCallback(function () use (&$flushed) { $flushed = true; });

    $dispatcher = testMock(EventDispatcherInterface::class);
    $dispatcher->method('dispatch')->willReturnArgument(0);

    (new StoreSubmissionListener($em, $dispatcher))(makePostSubmitEvent('store', ['field' => 'val'], '127.0.0.1'));

    expect($persisted)->toBeInstanceOf(FormSubmission::class)
        ->and($flushed)->toBeTrue();
});

it('persists and flushes when submissionAction is both', function () {
    $persisted = false;
    $flushed = false;
    $em = testMock(EntityManagerInterface::class);
    $em->method('persist')->willReturnCallback(function () use (&$persisted) { $persisted = true; });
    $em->method('flush')->willReturnCallback(function () use (&$flushed) { $flushed = true; });

    $dispatcher = testMock(EventDispatcherInterface::class);
    $dispatcher->method('dispatch')->willReturnArgument(0);

    (new StoreSubmissionListener($em, $dispatcher))(makePostSubmitEvent('both'));

    expect($persisted)->toBeTrue()->and($flushed)->toBeTrue();
});

it('persists and flushes when submissionAction is null', function () {
    $persisted = false;
    $flushed = false;
    $em = testMock(EntityManagerInterface::class);
    $em->method('persist')->willReturnCallback(function () use (&$persisted) { $persisted = true; });
    $em->method('flush')->willReturnCallback(function () use (&$flushed) { $flushed = true; });

    $dispatcher = testMock(EventDispatcherInterface::class);
    $dispatcher->method('dispatch')->willReturnArgument(0);

    $content = testMock(Content::class);
    $content->method('getFields')->willReturn([]);
    (new StoreSubmissionListener($em, $dispatcher))(new PostSubmitEvent(1, $content, [], null, null));

    expect($persisted)->toBeTrue()->and($flushed)->toBeTrue();
});

it('does not persist when submissionAction is email', function () {
    $persisted = false;
    $em = testMock(EntityManagerInterface::class);
    $em->method('persist')->willReturnCallback(function () use (&$persisted) { $persisted = true; });

    $dispatcher = testMock(EventDispatcherInterface::class);
    $dispatcher->method('dispatch')->willReturnArgument(0);

    (new StoreSubmissionListener($em, $dispatcher))(makePostSubmitEvent('email'));

    expect($persisted)->toBeFalse();
});

it('skips persist when PreStoreSubmissionEvent is cancelled', function () {
    $persisted = false;
    $em = testMock(EntityManagerInterface::class);
    $em->method('persist')->willReturnCallback(function () use (&$persisted) { $persisted = true; });

    $dispatcher = testMock(EventDispatcherInterface::class);
    $dispatcher->method('dispatch')->willReturnCallback(function (object $event) {
        if ($event instanceof PreStoreSubmissionEvent) {
            $event->cancel();
        }

        return $event;
    });

    (new StoreSubmissionListener($em, $dispatcher))(makePostSubmitEvent('store'));

    expect($persisted)->toBeFalse();
});

it('dispatches PostStoreSubmissionEvent after flush', function () {
    $em = testMock(EntityManagerInterface::class);

    $postStoreDispatched = false;
    $dispatcher = testMock(EventDispatcherInterface::class);
    $dispatcher->method('dispatch')->willReturnCallback(function (object $event, string $name) use (&$postStoreDispatched) {
        if ($name === FormBuilderEvents::POST_STORE_SUBMISSION) {
            $postStoreDispatched = true;
        }

        return $event;
    });

    (new StoreSubmissionListener($em, $dispatcher))(makePostSubmitEvent('store'));

    expect($postStoreDispatched)->toBeTrue();
});
