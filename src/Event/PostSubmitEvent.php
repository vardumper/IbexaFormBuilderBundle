<?php

declare(strict_types=1);

namespace vardumper\IbexaFormBuilderBundle\Event;

use Ibexa\Contracts\Core\Repository\Values\Content\Content;
use Symfony\Contracts\EventDispatcher\Event;

/** Dispatched when a submission passes the PreSubmitEvent gate. Store and email listeners act on this event. */
final class PostSubmitEvent extends Event
{
    public function __construct(
        private readonly int $contentId,
        private readonly Content $content,
        private readonly array $data,
        private readonly ?string $ipAddress,
        private readonly ?string $submissionAction,
    ) {
    }

    public function getContentId(): int
    {
        return $this->contentId;
    }

    public function getContent(): Content
    {
        return $this->content;
    }

    public function getData(): array
    {
        return $this->data;
    }

    public function getIpAddress(): ?string
    {
        return $this->ipAddress;
    }

    public function getSubmissionAction(): ?string
    {
        return $this->submissionAction;
    }
}
