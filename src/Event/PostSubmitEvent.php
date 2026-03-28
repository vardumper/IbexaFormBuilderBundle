<?php

declare(strict_types=1);

namespace vardumper\IbexaFormBuilderBundle\Event;

use Symfony\Contracts\EventDispatcher\Event;
use vardumper\IbexaFormBuilderBundle\Entity\FormSubmission;

/** Dispatched at the end of handle(), regardless of cancellations. $submission is null when the store step was skipped. */
final class PostSubmitEvent extends Event
{
    public function __construct(
        private readonly int $contentId,
        private readonly array $data,
        private readonly ?FormSubmission $submission,
    ) {
    }

    public function getContentId(): int
    {
        return $this->contentId;
    }

    public function getData(): array
    {
        return $this->data;
    }

    public function getSubmission(): ?FormSubmission
    {
        return $this->submission;
    }
}
