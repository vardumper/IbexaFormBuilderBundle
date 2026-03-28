<?php

declare(strict_types=1);

namespace vardumper\IbexaFormBuilderBundle\EventListener;

use DateTimeImmutable;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Twig\Environment;
use vardumper\IbexaFormBuilderBundle\Event\{FormBuilderEvents, PostSubmitEvent, PreSendEmailEvent};

#[AsEventListener(event: FormBuilderEvents::POST_SUBMIT)]
final class SendNotificationEmailListener
{
    public function __construct(
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly MailerInterface $mailer,
        private readonly Environment $twig,
        #[Autowire(param: 'ibexa_form_builder.from_email')]
        private readonly string $fromEmail,
    ) {
    }

    public function __invoke(PostSubmitEvent $event): void
    {
        $action = $event->getSubmissionAction();

        if ($action !== 'email' && $action !== 'both') {
            return;
        }

        $content = $event->getContent();
        $data = $event->getData();

        $to = null;
        $cc = null;
        $bcc = null;
        $subject = 'New form submission';
        $contentName = $content->getName() ?? 'Form';

        foreach ($content->getFields() as $field) {
            match ($field->fieldDefIdentifier) {
                'notification_email' => $to = (string) $field->value,
                'notification_email_cc' => $cc = (string) $field->value ?: null,
                'notification_email_bcc' => $bcc = (string) $field->value ?: null,
                'email_subject' => $subject = (string) $field->value ?: $subject,
                default => null,
            };
        }

        if (empty($to)) {
            return;
        }

        $html = $this->twig->render('@IbexaFormBuilderBundle/email/form_submission.html.twig', [
            'data' => $data,
            'formName' => $contentName,
            'submittedAt' => new DateTimeImmutable(),
        ]);

        $email = (new Email())
            ->html($html)
            ->subject($subject)
            ->to($to);

        if (!empty($this->fromEmail)) {
            $email->from($this->fromEmail);
        }
        if ($cc !== null) {
            $email->cc($cc);
        }
        if ($bcc !== null) {
            $email->bcc($bcc);
        }

        $preSend = new PreSendEmailEvent($email, $event->getContentId(), $data);
        $this->eventDispatcher->dispatch($preSend, FormBuilderEvents::PRE_SEND_EMAIL);

        if (!$preSend->isCancelled()) {
            $this->mailer->send($email);
        }
    }
}
