<?php

declare(strict_types=1);

namespace vardumper\IbexaFormBuilderBundle\Service;

use Doctrine\ORM\EntityManagerInterface;
use Ibexa\Contracts\Core\Repository\ContentService;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Twig\Environment;
use vardumper\IbexaFormBuilderBundle\Entity\FormSubmission;

final class SubmissionHandler
{
    public function __construct(
        private readonly ContentService $contentService,
        private readonly EntityManagerInterface $entityManager,
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

        // Strip Symfony internal fields before persisting/emailing
        $cleanData = array_filter(
            $postData,
            static fn (string $key) => !str_starts_with($key, '_'),
            ARRAY_FILTER_USE_KEY,
        );

        if ($submissionAction === null || $submissionAction === 'store' || $submissionAction === 'both') {
            $submission = FormSubmission::create($contentId, $cleanData, $ipAddress);
            $this->entityManager->persist($submission);
            $this->entityManager->flush();
        }

        if ($submissionAction === 'email' || $submissionAction === 'both') {
            $this->sendEmail($content, $cleanData, $fields);
        }
    }

    private function sendEmail(\Ibexa\Contracts\Core\Repository\Values\Content\Content $content, array $data, iterable $fields): void
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

        $this->mailer->send($email);
    }
}
