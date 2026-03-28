<?php

declare(strict_types=1);

namespace vardumper\IbexaFormBuilderBundle\Event;

use Symfony\Contracts\EventDispatcher\Event;
use vardumper\IbexaFormBuilderBundle\Entity\FormSubmission;

/** Dispatched after flush(); the submission entity carries its persisted auto-generated ID. */
final class PostStoreSubmissionEvent extends Event
{
    public function __construct(
        private readonly FormSubmission $submission,
    ) {
    }

    public function getSubmission(): FormSubmission
    {
        return $this->submission;
    }
}
