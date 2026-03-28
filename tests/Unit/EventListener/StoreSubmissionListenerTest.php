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
    $em = testMock(EntityManagerInterface::class);
    $em->expects($this->once())->method('persist')->with($this->isInstanceOf(FormSubmission::class));
    $em->expects($this->once())->method('flush');

    $dispatcher = testMock(EventDispatcherInterface::class);
    $dispatcher->method('dispatch')->willReturnArgument(0);

    $listener = new StoreSubmissionListener($em, $dispatcher);
    $listener(makePostSubmitEvent('store', ['field' => 'val'], '127.0.0.1'));
});

it('persists and flushes when submissionAction is both', function () {
    $em = testMock(EntityManagerInterface::class);
    $em->expects($this->once())->method('persist');
    $em->expects($this->once())->method('flush');

    $dispatcher = testMock(EventDispatcherInterface::class);
    $dispatcher->method('dispatch')->willReturnArgument(0);

    (new StoreSubmissionListener($em, $dispatcher))(makePostSubmitEvent('both'));
});

it('persists and flushes when submissionAction is null', function () {
    $em = testMock(EntityManagerInterface::class);
    $em->expects($this->once())->method('persist');
    $em->expects($this->once())->method('flush');

    $dispatcher = testMock(EventDispatcherInterface::class);
    $dispatcher->method('dispatch')->willReturnArgument(0);

    $content = testMock(Content::class);
    $content->method('getFields')->willReturn([]);
    $listener = new StoreSubmissionListener($em, $dispatcher);
    $listener(new PostSubmitEvent(1, $content, [], null, null));
});

it('does not persist when submissionAction is email', function () {
    $em = testMock(EntityManagerInterface::class);
    $em->expects($this->never())->method('persist');
    $em->expects($this->never())->method('flush');

    $dispatcher = testMock(EventDispatcherInterface::class);
    $dispatcher->method('dispatch')->willReturnArgument(0);

    (new StoreSubmissionListener($em, $dispatcher))(makePostSubmitEvent('email'));
});

it('skips persist when PreStoreSubmissionEvent is cancelled', function () {
    $em = testMock(EntityManagerInterface::class);
    $em->expects($this->never())->method('persist');
    $em->expects($this->never())->method('flush');

    $dispatcher = testMock(EventDispatcherInterface::class);
    $dispatcher->method('dispatch')->willReturnCallback(function (object $event) {
        if ($event instanceof PreStoreSubmissionEvent) {
            $event->cancel();
        }

        return $event;
    });

    (new StoreSubmissionListener($em, $dispatcher))(makePostSubmitEvent('store'));
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
