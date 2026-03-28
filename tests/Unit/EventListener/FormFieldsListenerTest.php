<?php

declare(strict_types=1);

use Ibexa\Contracts\Core\Repository\ContentService;
use Ibexa\Contracts\Core\Repository\Values\Content\Content;
use Ibexa\Contracts\Core\Repository\Values\Content\ContentInfo;
use Ibexa\Contracts\Core\Repository\Values\Content\Location as APILocation;
use Ibexa\Contracts\Core\Repository\Values\Content\Search\SearchHit;
use Ibexa\Contracts\Core\Repository\Values\Content\Search\SearchResult;
use Ibexa\Core\MVC\Symfony\View\Event\FilterViewParametersEvent;
use Ibexa\Core\MVC\Symfony\View\View;
use Ibexa\Core\MVC\Symfony\View\ViewEvents;
use Ibexa\Core\Repository\SiteAccessAware\SearchService;
use Ibexa\Core\Repository\Values\Content\Location as ConcreteLocation;
use Ibexa\Core\Repository\Values\ContentType\ContentType as ConcreteContentType;
use Symfony\Component\HttpFoundation\ParameterBag;
use vardumper\IbexaFormBuilderBundle\EventListener\FormFieldsListener;

it('returns expected subscribed events', function () {
    expect(FormFieldsListener::getSubscribedEvents())->toBe([
        ViewEvents::FILTER_VIEW_PARAMETERS => 'onFilterViewParameters',
    ]);
});

it('skips non-full view type', function () {
    $searchService = testMock(SearchService::class);
    $contentService = testMock(ContentService::class);

    $view = testMock(View::class);
    $view->method('getViewType')->willReturn('line');
    $view->method('getParameters')->willReturn([]);

    $findCalled = false;
    $searchService->method('findLocations')
        ->willReturnCallback(function () use (&$findCalled) {
            $findCalled = true;
        });

    $event = new FilterViewParametersEvent($view, []);
    (new FormFieldsListener($searchService, $contentService))->onFilterViewParameters($event);

    expect($findCalled)->toBeFalse();
});

it('skips full view when content type is not form', function () {
    $searchService = testMock(SearchService::class);
    $contentService = testMock(ContentService::class);

    $content = testMock(Content::class);
    $content->method('getContentType')->willReturn(new ConcreteContentType(['identifier' => 'article']));

    $location = testMock(APILocation::class);

    $view = testMock(View::class);
    $view->method('getViewType')->willReturn('full');
    $view->method('getParameters')->willReturn(['content' => $content, 'location' => $location]);

    $findCalled = false;
    $searchService->method('findLocations')
        ->willReturnCallback(function () use (&$findCalled) {
            $findCalled = true;

            return new SearchResult(['searchHits' => [], 'totalCount' => 0]);
        });

    $event = new FilterViewParametersEvent($view, []);
    (new FormFieldsListener($searchService, $contentService))->onFilterViewParameters($event);

    expect($findCalled)->toBeFalse();
});

it('sets form_fields parameter for full view with form content type', function () {
    $searchService = testMock(SearchService::class);
    $contentService = testMock(ContentService::class);

    $content = testMock(Content::class);
    $content->method('getContentType')->willReturn(new ConcreteContentType(['identifier' => 'form']));

    $contentInfo = new ContentInfo(['id' => 1]);
    $concreteLocation = new ConcreteLocation(['contentInfo' => $contentInfo]);

    $childContent = testMock(Content::class);
    $childContent->method('getFieldValue')->willReturn(null);

    $searchHit = new SearchHit(['valueObject' => $concreteLocation]);
    $searchResult = new SearchResult(['searchHits' => [$searchHit], 'totalCount' => 1]);

    $searchService->method('findLocations')->willReturn($searchResult);
    $contentService->method('loadContentByContentInfo')->willReturn($childContent);

    $parentLocation = new ConcreteLocation(['id' => 10]);

    $view = testMock(View::class);
    $view->method('getViewType')->willReturn('full');
    $view->method('getParameters')->willReturn(['content' => $content, 'location' => $parentLocation]);

    $paramBag = new ParameterBag();
    $event = new FilterViewParametersEvent($view, []);
    // Expose the ParameterBag via reflection to inspect results
    $paramBagRef = new ReflectionProperty(FilterViewParametersEvent::class, 'parameterBag');
    $paramBagRef->setValue($event, $paramBag);

    (new FormFieldsListener($searchService, $contentService))->onFilterViewParameters($event);

    expect($paramBag->has('form_fields'))->toBeTrue()
        ->and($paramBag->get('form_fields'))->toBeArray();
});
