<?php

declare(strict_types=1);

use Doctrine\ORM\EntityManagerInterface;
use Ibexa\Contracts\Core\Repository\ContentService;
use Ibexa\Contracts\Core\Repository\Values\Content\Content;
use Ibexa\Contracts\Core\Repository\Values\Content\Field;
use Symfony\Component\Mailer\MailerInterface;
use Twig\Environment;
use vardumper\IbexaFormBuilderBundle\Service\SubmissionHandler;

function makeHandler(
    Content $content,
    EntityManagerInterface $em,
    MailerInterface $mailer,
    string $fromEmail = '',
): SubmissionHandler {
    $contentService = testMock(ContentService::class);
    $contentService->method('loadContent')->willReturn($content);

    $twig = testMock(Environment::class);
    $twig->method('render')->willReturn('<html>test</html>');

    return new SubmissionHandler($contentService, $em, $mailer, $twig, $fromEmail);
}

function makeContent(string $submissionAction): Content
{
    $field = testMock(Field::class);
    $field->fieldDefIdentifier = 'submission_action';

    $value = new stdClass();
    $value->selection = [$submissionAction];
    $field->value = $value;

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

    // email requires a notification_email field — without it, send is skipped
    $content = testMock(Content::class);
    $actionField = testMock(Field::class);
    $actionField->fieldDefIdentifier = 'submission_action';
    $actionValue = new stdClass();
    $actionValue->selection = ['email'];
    $actionField->value = $actionValue;

    $emailField = testMock(Field::class);
    $emailField->fieldDefIdentifier = 'notification_email';
    $emailField->value = 'recipient@example.com';

    $content->method('getFields')->willReturn([$actionField, $emailField]);
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
    $actionField = testMock(Field::class);
    $actionField->fieldDefIdentifier = 'submission_action';
    $actionValue = new stdClass();
    $actionValue->selection = ['both'];
    $actionField->value = $actionValue;

    $emailField = testMock(Field::class);
    $emailField->fieldDefIdentifier = 'notification_email';
    $emailField->value = 'recipient@example.com';

    $content->method('getFields')->willReturn([$actionField, $emailField]);
    $content->method('getName')->willReturn('Test Form');

    $mailer->expects($this->once())->method('send');

    $handler = makeHandler($content, $em, $mailer);
    $handler->handle(1, ['_token' => 'xyz', 'field' => 'val'], null);
});
