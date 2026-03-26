<?php

declare(strict_types=1);

namespace vardumper\IbexaFormBuilderBundle\EventListener;

use Ibexa\Contracts\Core\Repository\Events\Content\PublishVersionEvent;
use Ibexa\Contracts\Core\Repository\LocationService;
use Ibexa\Core\FieldType\Integer\Value as IntegerValue;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Syncs form_builder_order field value → location priority so that
 * the Ibexa admin sub-item list (sorted by priority) reflects the intended order.
 */
final class OrderSyncListener implements EventSubscriberInterface
{
    private const ORDER_FIELD = 'form_builder_order';

    private const SUPPORTED_CONTENT_TYPES = [
        'form_builder_input',
        'form_builder_textarea',
        'form_builder_select',
        'form_builder_fieldset',
        'form_builder_horizontal_group',
        'form_builder_choice',
        'form_builder_option'

    ];

    public function __construct(
        private readonly LocationService $locationService,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            PublishVersionEvent::class => 'onPublishVersion',
        ];
    }

    public function onPublishVersion(PublishVersionEvent $event): void
    {
        $content = $event->getContent();
        $contentType = $content->getContentType();

        if (!\in_array($contentType->identifier, self::SUPPORTED_CONTENT_TYPES, true)) {
            return;
        }

        $orderField = $content->getFieldValue(self::ORDER_FIELD);
        if (!$orderField instanceof IntegerValue) {
            return;
        }

        if ($orderField->value === null) {
            return;
        }

        $priority = (int) $orderField->value;

        $locations = $this->locationService->loadLocations($content->contentInfo);
        foreach ($locations as $location) {
            $updateStruct = $this->locationService->newLocationUpdateStruct();
            $updateStruct->priority = $priority;
            $this->locationService->updateLocation($location, $updateStruct);
        }
    }
}
