<?php

declare(strict_types=1);

namespace vardumper\IbexaFormBuilderBundle\Event;

use vardumper\IbexaFormBuilderBundle\Entity\FormSubmission;

/** Dispatched before persist()+flush(). Cancel to skip the DB write; email action still proceeds. Mutate the entity directly if needed. */
final class PreStoreSubmissionEvent extends AbstractCancellableEvent
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
