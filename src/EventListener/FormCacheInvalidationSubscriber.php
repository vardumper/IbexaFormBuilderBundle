<?php

declare(strict_types=1);

namespace vardumper\IbexaFormBuilderBundle\EventListener;

use Ibexa\Contracts\Core\Repository\Events\Content\DeleteContentEvent;
use Ibexa\Contracts\Core\Repository\Events\Content\PublishVersionEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Contracts\Cache\TagAwareCacheInterface;

final class FormCacheInvalidationSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly TagAwareCacheInterface $cache,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            PublishVersionEvent::class => 'onPublishVersion',
            DeleteContentEvent::class => 'onDeleteContent',
        ];
    }

    public function onPublishVersion(PublishVersionEvent $event): void
    {
        $contentId = $event->getContent()->id;
        $this->cache->invalidateTags(["form_{$contentId}"]);
    }

    public function onDeleteContent(DeleteContentEvent $event): void
    {
        $contentId = $event->getContentInfo()->id;
        $this->cache->invalidateTags(["form_{$contentId}"]);
    }
}
