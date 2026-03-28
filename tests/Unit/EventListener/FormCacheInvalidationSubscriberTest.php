<?php

declare(strict_types=1);

use Ibexa\Contracts\Core\FieldType\Value;
use Ibexa\Contracts\Core\Repository\Events\Content\DeleteContentEvent;
use Ibexa\Contracts\Core\Repository\Events\Content\PublishVersionEvent;
use Ibexa\Contracts\Core\Repository\Values\Content\Content;
use Ibexa\Contracts\Core\Repository\Values\Content\ContentInfo;
use Ibexa\Contracts\Core\Repository\Values\Content\Field;
use Ibexa\Contracts\Core\Repository\Values\Content\Thumbnail;
use Ibexa\Contracts\Core\Repository\Values\Content\VersionInfo;
use Ibexa\Contracts\Core\Repository\Values\ContentType\ContentType;
use Symfony\Contracts\Cache\TagAwareCacheInterface;
use vardumper\IbexaFormBuilderBundle\EventListener\FormCacheInvalidationSubscriber;

/** Anonymous Content stub with a configurable id. */
function makeContentStub(int $id): Content
{
    return new class($id) extends Content {
        protected int $id;

        public function __construct(int $id)
        {
            $this->id = $id;
        }

        public function getVersionInfo(): VersionInfo
        {
            return testMock(VersionInfo::class);
        }
        public function getFieldValue(string $f, ?string $l = null): ?Value
        {
            return null;
        }
        public function getFields(): iterable
        {
            return [];
        }
        public function getFieldsByLanguage(?string $l = null): iterable
        {
            return [];
        }
        public function getField(string $f, ?string $l = null): ?Field
        {
            return null;
        }
        public function getContentType(): ContentType
        {
            return testMock(ContentType::class);
        }
        public function getThumbnail(): ?Thumbnail
        {
            return null;
        }
        public function getDefaultLanguageCode(): string
        {
            return 'eng-GB';
        }
    };
}

it('returns expected subscribed events', function () {
    expect(FormCacheInvalidationSubscriber::getSubscribedEvents())->toBe([
        PublishVersionEvent::class => 'onPublishVersion',
        DeleteContentEvent::class => 'onDeleteContent',
    ]);
});

it('invalidates cache tag on publish version', function () {
    $invalidated = null;
    $cache = testMock(TagAwareCacheInterface::class);
    $cache->method('invalidateTags')
        ->willReturnCallback(function (array $tags) use (&$invalidated) {
            $invalidated = $tags;

            return true;
        });

    $event = new PublishVersionEvent(makeContentStub(42), testMock(VersionInfo::class), []);

    (new FormCacheInvalidationSubscriber($cache))->onPublishVersion($event);

    expect($invalidated)->toBe(['form_42']);
});

it('invalidates cache tag on delete content', function () {
    $invalidated = null;
    $cache = testMock(TagAwareCacheInterface::class);
    $cache->method('invalidateTags')
        ->willReturnCallback(function (array $tags) use (&$invalidated) {
            $invalidated = $tags;

            return true;
        });

    $contentInfo = new ContentInfo(['id' => 99]);
    $event = new DeleteContentEvent([], $contentInfo);

    (new FormCacheInvalidationSubscriber($cache))->onDeleteContent($event);

    expect($invalidated)->toBe(['form_99']);
});
