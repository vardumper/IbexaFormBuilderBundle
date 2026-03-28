<?php

declare(strict_types=1);

namespace vardumper\IbexaFormBuilderBundle\Event;

use Symfony\Component\Mime\Email;

/** Dispatched after the Email object is built, before it is sent. Cancel to suppress sending. Mutate Email directly to alter recipients, subject, or body. */
final class PreSendEmailEvent extends AbstractCancellableEvent
{
    public function __construct(
        private readonly Email $email,
        private readonly int $contentId,
        private readonly array $data,
    ) {
    }

    public function getEmail(): Email
    {
        return $this->email;
    }

    public function getContentId(): int
    {
        return $this->contentId;
    }

    public function getData(): array
    {
        return $this->data;
    }
}
