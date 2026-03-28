<?php

declare(strict_types=1);

namespace vardumper\IbexaFormBuilderBundle\Event;

use Symfony\Component\Form\FormInterface;

/** Dispatched after handleRequest(), before isValid() is evaluated. Cancel to abort the submission pipeline. */
final class PreValidationEvent extends AbstractCancellableEvent
{
    public function __construct(
        private readonly FormInterface $form,
        private readonly int $contentId,
    ) {
    }

    public function getForm(): FormInterface
    {
        return $this->form;
    }

    public function getContentId(): int
    {
        return $this->contentId;
    }
}
