<?php

declare(strict_types=1);

namespace vardumper\IbexaFormBuilderBundle\Service;

use Doctrine\ORM\EntityManagerInterface;
use Ibexa\Contracts\Core\Repository\ContentService;
use Ibexa\Contracts\Core\Repository\Values\Content\Content;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Twig\Environment;
use vardumper\IbexaFormBuilderBundle\Entity\FormSubmission;
use vardumper\IbexaFormBuilderBundle\Event\{FormBuilderEvents, PostStoreSubmissionEvent, PostSubmitEvent, PreSendEmailEvent, PreStoreSubmissionEvent, PreSubmitEvent};

final class SubmissionHandler
{
    public function __construct(
        private readonly ContentService $contentService,
        private readonly EntityManagerInterface $entityManager,
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly MailerInterface $mailer,
        private readonly Environment $twig,
        private readonly string $fromEmail,
    ) {
    }

    public function handle(int $contentId, array $postData, ?string $ipAddress): void
    {
        $content = $this->contentService->loadContent($contentId);
        $fields = $content->getFields();

        $submissionAction = null;
        foreach ($fields as $field) {
            if ($field->fieldDefIdentifier === 'submission_action' && !empty($field->value->selection)) {
                $submissionAction = $field->value->selection[0] ?? null;
                break;
            }
        }

        $cleanData = array_filter(
            $postData,
            static fn (string $key) => !str_starts_with($key, '_'),
            ARRAY_FILTER_USE_KEY,
        ); /** strip Symfony internal fields */

        $preSubmit = new PreSubmitEvent($contentId, $cleanData, $ipAddress);
        $this->eventDispatcher->dispatch($preSubmit, FormBuilderEvents::PRE_SUBMIT);

        if ($preSubmit->isCancelled()) {
            $this->eventDispatcher->dispatch(new PostSubmitEvent($contentId, $preSubmit->getData(), null), FormBuilderEvents::POST_SUBMIT);

            return;
        }

        $cleanData = $preSubmit->getData(); /** allow listeners to enrich or sanitize data */
        $storedSubmission = null;

        if ($submissionAction === null || $submissionAction === 'store' || $submissionAction === 'both') {
            $submission = FormSubmission::create($contentId, $cleanData, $ipAddress);
            $preStore = new PreStoreSubmissionEvent($submission);
            $this->eventDispatcher->dispatch($preStore, FormBuilderEvents::PRE_STORE_SUBMISSION);

            if (!$preStore->isCancelled()) {
                $this->entityManager->persist($submission);
                $this->entityManager->flush();
                $storedSubmission = $submission;
                $this->eventDispatcher->dispatch(new PostStoreSubmissionEvent($submission), FormBuilderEvents::POST_STORE_SUBMISSION);
            }
        }

        if ($submissionAction === 'email' || $submissionAction === 'both') {
            $this->sendEmail($content, $cleanData, $fields, $contentId);
        }

        $this->eventDispatcher->dispatch(new PostSubmitEvent($contentId, $cleanData, $storedSubmission), FormBuilderEvents::POST_SUBMIT);
    }

    private function sendEmail(Content $content, array $data, iterable $fields, int $contentId): void
    {
        $to = null;
        $cc = null;
        $bcc = null;
        $subject = 'New form submission';
        $contentName = $content->getName() ?? 'Form';

        foreach ($fields as $field) {
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
            'formName' => $contentName,
            'data' => $data,
            'submittedAt' => new \DateTimeImmutable(),
        ]);

        $email = (new Email())
            ->subject($subject)
            ->html($html)
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

        $preSend = new PreSendEmailEvent($email, $contentId, $data);
        $this->eventDispatcher->dispatch($preSend, FormBuilderEvents::PRE_SEND_EMAIL);

        if (!$preSend->isCancelled()) {
            $this->mailer->send($email);
        }
    }
}
