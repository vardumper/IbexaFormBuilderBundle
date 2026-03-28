<?php

declare(strict_types=1);

namespace vardumper\IbexaFormBuilderBundle\EventListener;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use vardumper\IbexaFormBuilderBundle\Entity\FormSubmission;
use vardumper\IbexaFormBuilderBundle\Event\{FormBuilderEvents, PostStoreSubmissionEvent, PostSubmitEvent, PreStoreSubmissionEvent};

#[AsEventListener(event: FormBuilderEvents::POST_SUBMIT)]
final class StoreSubmissionListener
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly EventDispatcherInterface $eventDispatcher,
    ) {
    }

    public function __invoke(PostSubmitEvent $event): void
    {
        $action = $event->getSubmissionAction();

        if ($action !== null && $action !== 'store' && $action !== 'both') {
            return;
        }

        $submission = FormSubmission::create($event->getContentId(), $event->getData(), $event->getIpAddress());
        $preStore = new PreStoreSubmissionEvent($submission);
        $this->eventDispatcher->dispatch($preStore, FormBuilderEvents::PRE_STORE_SUBMISSION);

        if ($preStore->isCancelled()) {
            return;
        }

        $this->entityManager->persist($submission);
        $this->entityManager->flush();
        $this->eventDispatcher->dispatch(new PostStoreSubmissionEvent($submission), FormBuilderEvents::POST_STORE_SUBMISSION);
    }
}
