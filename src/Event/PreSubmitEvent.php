<?php

declare(strict_types=1);

namespace vardumper\IbexaFormBuilderBundle\Event;

/** Dispatched after POST data is cleaned, before storage or email. Cancel to skip both (PostSubmitEvent still fires). */
final class PreSubmitEvent extends AbstractCancellableEvent
{
    public function __construct(
        private readonly int $contentId,
        private array $data,
        private readonly ?string $ipAddress,
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

    public function setData(array $data): self
    {
        $this->data = $data;

        return $this;
    }

    public function getIpAddress(): ?string
    {
        return $this->ipAddress;
    }
}
