<?php

declare(strict_types=1);

namespace vardumper\IbexaFormBuilderBundle\Service;

use Ibexa\Contracts\Core\Repository\ContentService;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use vardumper\IbexaFormBuilderBundle\Event\{FormBuilderEvents, PostSubmitEvent, PreSubmitEvent};

class SubmissionHandler
{
    public function __construct(
        private readonly ContentService $contentService,
        private readonly EventDispatcherInterface $eventDispatcher,
    ) {
    }

    public function handle(int $contentId, array $postData, ?string $ipAddress): void
    {
        $content = $this->contentService->loadContent($contentId);

        $submissionAction = null;
        foreach ($content->getFields() as $field) {
            if ($field->fieldDefIdentifier === 'submission_action' && !empty($field->value->selection)) {
                $submissionAction = $field->value->selection[0] ?? null;
                break;
            }
        }

        $cleanData = \array_filter(
            $postData,
            static fn (string $key) => !\str_starts_with($key, '_'),
            ARRAY_FILTER_USE_KEY,
        ); /** Strip Symfony internal fields. */

        $preSubmit = new PreSubmitEvent($contentId, $cleanData, $ipAddress);
        $this->eventDispatcher->dispatch($preSubmit, FormBuilderEvents::PRE_SUBMIT);

        if ($preSubmit->isCancelled()) {
            return;
        }

        $this->eventDispatcher->dispatch(
            new PostSubmitEvent($contentId, $content, $preSubmit->getData(), $ipAddress, $submissionAction),
            FormBuilderEvents::POST_SUBMIT,
        );
    }
}
