<?php

declare(strict_types=1);

namespace vardumper\IbexaFormBuilderBundle\EventListener;

use Ibexa\Contracts\Core\Repository\ContentService;
use Ibexa\Contracts\Core\Repository\Values\Content\Content;
use Ibexa\Contracts\Core\Repository\Values\Content\Location;
use Ibexa\Contracts\Core\Repository\Values\Content\LocationQuery;
use Ibexa\Contracts\Core\Repository\Values\Content\Query\Criterion;
use Ibexa\Core\MVC\Symfony\View\Event\FilterViewParametersEvent;
use Ibexa\Core\MVC\Symfony\View\ViewEvents;
use Ibexa\Core\Repository\SiteAccessAware\SearchService;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

final class FormFieldsListener implements EventSubscriberInterface
{
    public function __construct(
        private readonly SearchService $searchService,
        private readonly ContentService $contentService,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            ViewEvents::FILTER_VIEW_PARAMETERS => 'onFilterViewParameters',
        ];
    }

    public function onFilterViewParameters(FilterViewParametersEvent $event): void
    {
        $view = $event->getView();

        if ($view->getViewType() !== 'full') {
            return;
        }

        $parameters = $view->getParameters();
        $content = $parameters['content'] ?? null;
        $location = $parameters['location'] ?? null;

        if ($content instanceof Content && $location instanceof Location && $content->getContentType()->identifier === 'form') {
            $event->getParameterBag()->set('form_fields', $this->loadFormFields($location));
        }
    }

    /** @return list<Content> */
    private function loadFormFields(Location $location): array
    {
        $query = new LocationQuery();
        $query->filter = new Criterion\LogicalAnd([
            new Criterion\ParentLocationId($location->id),
            new Criterion\Visibility(Criterion\Visibility::VISIBLE),
            new Criterion\ContentTypeIdentifier(['input', 'select', 'textarea']),
        ]);
        $result = $this->searchService->findLocations($query);

        $contentInfoList = \array_map(
            static fn (mixed $hit): \Ibexa\Contracts\Core\Repository\Values\Content\ContentInfo => $hit->valueObject->contentInfo,
            $result->searchHits,
        );

        /** @var list<Content> $formFields */
        $formFields = \array_values(\iterator_to_array($this->contentService->loadContentListByContentInfo($contentInfoList)));

        \usort($formFields, static function (Content $left, Content $right): int {
            $leftOrder = $left->getFieldValue('order');
            $rightOrder = $right->getFieldValue('order');

            return $leftOrder <=> $rightOrder;
        });

        return $formFields;
    }
}
