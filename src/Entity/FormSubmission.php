<?php

declare(strict_types=1);

namespace vardumper\IbexaFormBuilderBundle\Entity;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use vardumper\IbexaFormBuilderBundle\Repository\FormSubmissionRepository;

#[ORM\Entity(repositoryClass: FormSubmissionRepository::class)]
#[ORM\Table(name: 'form_submission')]
class FormSubmission
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    /** @phpstan-ignore property.unusedType */
    private int|null $id = null;

    #[ORM\Column]
    private int $contentId;

    #[ORM\Column(type: 'datetime_immutable')]
    private DateTimeImmutable $submittedAt;

    #[ORM\Column(type: 'json')]
    private array $data;

    #[ORM\Column(length: 45, nullable: true)]
    private ?string $ipAddress;

    private function __construct(int $contentId, DateTimeImmutable $submittedAt, array $data, ?string $ipAddress)
    {
        $this->contentId = $contentId;
        $this->submittedAt = $submittedAt;
        $this->data = $data;
        $this->ipAddress = $ipAddress;
    }

    public static function create(int $contentId, array $data, ?string $ipAddress = null): self
    {
        return new self($contentId, new DateTimeImmutable(), $data, $ipAddress);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getContentId(): int
    {
        return $this->contentId;
    }

    public function getSubmittedAt(): DateTimeImmutable
    {
        return $this->submittedAt;
    }

    public function getData(): array
    {
        return $this->data;
    }

    public function getIpAddress(): ?string
    {
        return $this->ipAddress;
    }
}
