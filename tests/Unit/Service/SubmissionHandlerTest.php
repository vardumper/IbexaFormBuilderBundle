<?php

declare(strict_types=1);

use Doctrine\ORM\EntityManagerInterface;
use Ibexa\Contracts\Core\Repository\ContentService;
use Ibexa\Contracts\Core\Repository\Values\Content\Content;
use Ibexa\Contracts\Core\Repository\Values\Content\Field;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Mailer\MailerInterface;
use Twig\Environment;
use vardumper\IbexaFormBuilderBundle\Service\SubmissionHandler;

function makeHandler(
    Content $content,
    EntityManagerInterface $em,
    MailerInterface $mailer,
    string $fromEmail = '',
    ?EventDispatcherInterface $dispatcher = null,
): SubmissionHandler {
    $contentService = testMock(ContentService::class);
    $contentService->method('loadContent')->willReturn($content);

    $twig = testMock(Environment::class);
    $twig->method('render')->willReturn('<html>test</html>');

    $dispatcher ??= testMock(EventDispatcherInterface::class);
    $dispatcher->method('dispatch')->willReturnArgument(0);

    return new SubmissionHandler($contentService, $em, $dispatcher, $mailer, $twig, $fromEmail);
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
    $field = makeField('submission_action', makeSelectionValue($submissionAction));

    $content = testMock(Content::class);
    $content->method('getFields')->willReturn([$field]);
    $content->method('getName')->willReturn('Test Form');

    return $content;
}

it('calls flush when submission_action is store', function () {
    $em = testMock(EntityManagerInterface::class);
    $em->expects($this->once())->method('persist');
    $em->expects($this->once())->method('flush');

    $mailer = testMock(MailerInterface::class);
    $mailer->expects($this->never())->method('send');

    $handler = makeHandler(makeContent('store'), $em, $mailer);
    $handler->handle(1, ['field' => 'val'], '127.0.0.1');
});

it('calls mailer send when submission_action is email', function () {
    $em = testMock(EntityManagerInterface::class);
    $em->expects($this->never())->method('flush');

    $mailer = testMock(MailerInterface::class);

    $content = testMock(Content::class);
    $content->method('getFields')->willReturn([
        makeField('submission_action', makeSelectionValue('email')),
        makeField('notification_email', 'recipient@example.com'),
    ]);
    $content->method('getName')->willReturn('Test Form');

    $mailer->expects($this->once())->method('send');

    $handler = makeHandler($content, $em, $mailer);
    $handler->handle(1, ['field' => 'val'], null);
});

it('calls both persist and send when submission_action is both', function () {
    $em = testMock(EntityManagerInterface::class);
    $em->expects($this->once())->method('persist');
    $em->expects($this->once())->method('flush');

    $mailer = testMock(MailerInterface::class);

    $content = testMock(Content::class);
    $content->method('getFields')->willReturn([
        makeField('submission_action', makeSelectionValue('both')),
        makeField('notification_email', 'recipient@example.com'),
    ]);
    $content->method('getName')->willReturn('Test Form');

    $mailer->expects($this->once())->method('send');

    $handler = makeHandler($content, $em, $mailer);
    $handler->handle(1, ['_token' => 'xyz', 'field' => 'val'], null);
});
