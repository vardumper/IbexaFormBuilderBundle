<?php

declare(strict_types=1);

use Ibexa\Contracts\Core\Repository\Values\Content\Content;
use Ibexa\Contracts\Core\Repository\Values\Content\Field;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Mailer\MailerInterface;
use Twig\Environment;
use vardumper\IbexaFormBuilderBundle\Event\{FormBuilderEvents, PostSubmitEvent, PreSendEmailEvent};
use vardumper\IbexaFormBuilderBundle\EventListener\SendNotificationEmailListener;

function makeEmailContent(string $action, string $to = 'to@example.com'): Content
{
    $content = testMock(Content::class);
    $content->method('getName')->willReturn('Test Form');
    $content->method('getFields')->willReturn([
        new Field(['fieldDefIdentifier' => 'submission_action', 'value' => (object) ['selection' => [$action]]]),
        new Field(['fieldDefIdentifier' => 'notification_email', 'value' => $to]),
    ]);

    return $content;
}

function makeEmailEvent(string $action, string $to = 'to@example.com'): PostSubmitEvent
{
    return new PostSubmitEvent(1, makeEmailContent($action, $to), ['field' => 'val'], null, $action);
}

function makeEmailListener(
    ?MailerInterface $mailer = null,
    ?EventDispatcherInterface $dispatcher = null,
): SendNotificationEmailListener {
    $mailer ??= testMock(MailerInterface::class);
    $twig = testMock(Environment::class);
    $twig->method('render')->willReturn('<html>test</html>');
    $dispatcher ??= testMock(EventDispatcherInterface::class);
    $dispatcher->method('dispatch')->willReturnArgument(0);

    return new SendNotificationEmailListener($dispatcher, $mailer, $twig, 'from@example.com');
}

it('sends email when submissionAction is email', function () {
    $mailer = testMock(MailerInterface::class);
    $mailer->expects($this->once())->method('send');

    makeEmailListener($mailer)(makeEmailEvent('email'));
});

it('sends email when submissionAction is both', function () {
    $mailer = testMock(MailerInterface::class);
    $mailer->expects($this->once())->method('send');

    makeEmailListener($mailer)(makeEmailEvent('both'));
});

it('does not send when submissionAction is store', function () {
    $mailer = testMock(MailerInterface::class);
    $mailer->expects($this->never())->method('send');

    makeEmailListener($mailer)(makeEmailEvent('store'));
});

it('does not send when submissionAction is null', function () {
    $mailer = testMock(MailerInterface::class);
    $mailer->expects($this->never())->method('send');

    $content = testMock(Content::class);
    $content->method('getFields')->willReturn([]);
    $listener = makeEmailListener($mailer);
    $listener(new PostSubmitEvent(1, $content, [], null, null));
});

it('does not send when notification_email field is empty', function () {
    $mailer = testMock(MailerInterface::class);
    $mailer->expects($this->never())->method('send');

    $content = testMock(Content::class);
    $content->method('getName')->willReturn('Form');
    $content->method('getFields')->willReturn([
        new Field(['fieldDefIdentifier' => 'notification_email', 'value' => '']),
    ]);

    makeEmailListener($mailer)(new PostSubmitEvent(1, $content, [], null, 'email'));
});

it('suppresses send when PreSendEmailEvent is cancelled', function () {
    $mailer = testMock(MailerInterface::class);
    $mailer->expects($this->never())->method('send');

    $dispatcher = testMock(EventDispatcherInterface::class);
    $dispatcher->method('dispatch')->willReturnCallback(function (object $event) {
        if ($event instanceof PreSendEmailEvent) {
            $event->cancel();
        }

        return $event;
    });

    $twig = testMock(Environment::class);
    $twig->method('render')->willReturn('<html>test</html>');

    $listener = new SendNotificationEmailListener($dispatcher, $mailer, $twig, '');
    $listener(makeEmailEvent('email'));
});

it('dispatches PreSendEmailEvent before sending', function () {
    $preSendDispatched = false;
    $dispatcher = testMock(EventDispatcherInterface::class);
    $dispatcher->method('dispatch')->willReturnCallback(function (object $event, string $name) use (&$preSendDispatched) {
        if ($name === FormBuilderEvents::PRE_SEND_EMAIL) {
            $preSendDispatched = true;
        }

        return $event;
    });

    $twig = testMock(Environment::class);
    $twig->method('render')->willReturn('<html>test</html>');

    $listener = new SendNotificationEmailListener($dispatcher, testMock(MailerInterface::class), $twig, '');
    $listener(makeEmailEvent('email'));

    expect($preSendDispatched)->toBeTrue();
});
