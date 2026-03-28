<?php

declare(strict_types=1);

use Ibexa\Contracts\Core\FieldType\Value;
use Ibexa\Contracts\Core\Repository\Events\Content\PublishVersionEvent;
use Ibexa\Contracts\Core\Repository\LocationService;
use Ibexa\Contracts\Core\Repository\Values\Content\Content;
use Ibexa\Contracts\Core\Repository\Values\Content\ContentInfo;
use Ibexa\Contracts\Core\Repository\Values\Content\Field;
use Ibexa\Contracts\Core\Repository\Values\Content\Location;
use Ibexa\Contracts\Core\Repository\Values\Content\LocationUpdateStruct;
use Ibexa\Contracts\Core\Repository\Values\Content\Thumbnail;
use Ibexa\Contracts\Core\Repository\Values\Content\VersionInfo;
use Ibexa\Contracts\Core\Repository\Values\ContentType\ContentType;
use Ibexa\Core\FieldType\Integer\Value as IntegerValue;
use Ibexa\Core\Repository\Values\ContentType\ContentType as ConcreteContentType;
use vardumper\IbexaFormBuilderBundle\EventListener\OrderSyncListener;

/**
 * Builds a Content stub with a given content-type identifier and optional order value.
 */
function makeOrderContent(string $typeIdentifier, ?int $orderValue = null): Content
{
    $contentInfo = new ContentInfo(['id' => 1]);

    return new class($typeIdentifier, $orderValue, $contentInfo) extends Content {
        protected ContentInfo $contentInfo;
        private ContentType $ct;

        public function __construct(
            string $typeIdentifier,
            private readonly ?int $orderValue,
            ContentInfo $contentInfo,
        ) {
            $this->contentInfo = $contentInfo;
            $this->ct = new ConcreteContentType(['identifier' => $typeIdentifier]);
        }

        public function getVersionInfo(): VersionInfo
        {
            return testMock(VersionInfo::class);
        }
        public function getContentType(): ContentType
        {
            return $this->ct;
        }

        public function getFieldValue(string $f, ?string $l = null): ?Value
        {
            if ($f === 'form_builder_order') {
                return $this->orderValue !== null ? new IntegerValue($this->orderValue) : new IntegerValue(null);
            }

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
    $locationService = testMock(LocationService::class);

    expect(OrderSyncListener::getSubscribedEvents())->toBe([
        PublishVersionEvent::class => 'onPublishVersion',
    ]);
});

it('skips update for unsupported content type', function () {
    $called = false;
    $locationService = testMock(LocationService::class);
    $locationService->method('loadLocations')
        ->willReturnCallback(function () use (&$called) {
            $called = true;

            return [];
        });

    $content = makeOrderContent('form_builder_unsupported', 5);
    $event = new PublishVersionEvent($content, testMock(VersionInfo::class), []);

    (new OrderSyncListener($locationService))->onPublishVersion($event);

    expect($called)->toBeFalse();
});

it('skips update when order field is not IntegerValue', function () {
    $called = false;
    $locationService = testMock(LocationService::class);
    $locationService->method('loadLocations')
        ->willReturnCallback(function () use (&$called) {
            $called = true;

            return [];
        });

    /** @var Content $content */
    $content = new class() extends Content {
        public function getVersionInfo(): VersionInfo
        {
            return testMock(VersionInfo::class);
        }
        public function getContentType(): ContentType
        {
            return new ConcreteContentType(['identifier' => 'form_builder_input']);
        }

        public function getFieldValue(string $f, ?string $l = null): ?Value
        {
            return null;
        } /** returns null, not IntegerValue */
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
        public function getThumbnail(): ?Thumbnail
        {
            return null;
        }
        public function getDefaultLanguageCode(): string
        {
            return 'eng-GB';
        }
    };

    $event = new PublishVersionEvent($content, testMock(VersionInfo::class), []);
    (new OrderSyncListener($locationService))->onPublishVersion($event);

    expect($called)->toBeFalse();
});

it('skips update when order field value is null', function () {
    $called = false;
    $locationService = testMock(LocationService::class);
    $locationService->method('loadLocations')
        ->willReturnCallback(function () use (&$called) {
            $called = true;

            return [];
        });

    $content = makeOrderContent('form_builder_input', null);
    $event = new PublishVersionEvent($content, testMock(VersionInfo::class), []);
    (new OrderSyncListener($locationService))->onPublishVersion($event);

    expect($called)->toBeFalse();
});

it('updates location priority for supported content type with valid order', function () {
    $updateStruct = new LocationUpdateStruct();
    $location = testMock(Location::class);
    $returnLocation = testMock(Location::class);

    $locationService = testMock(LocationService::class);
    $locationService->method('loadLocations')->willReturn([$location]);
    $locationService->method('newLocationUpdateStruct')->willReturn($updateStruct);
    $locationService->method('updateLocation')->willReturn($returnLocation);

    $content = makeOrderContent('form_builder_input', 5);
    $event = new PublishVersionEvent($content, testMock(VersionInfo::class), []);
    (new OrderSyncListener($locationService))->onPublishVersion($event);

    expect($updateStruct->priority)->toBe(5);
});
