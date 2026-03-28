<?php

declare(strict_types=1);

use Ibexa\Contracts\Core\Repository\LocationService;
use Ibexa\Contracts\Core\Repository\SearchService;
use Ibexa\Contracts\Core\Repository\Values\Content\Search\SearchResult;
use Psr\Container\ContainerInterface as PsrContainerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\RouterInterface;
use Twig\Environment;
use vardumper\IbexaFormBuilderBundle\Controller\FormController;
use vardumper\IbexaFormBuilderBundle\Event\PreValidationEvent;
use vardumper\IbexaFormBuilderBundle\Service\ContentFormFactory;
use vardumper\IbexaFormBuilderBundle\Service\SubmissionHandler;

function makeFormContainer(): PsrContainerInterface
{
    $twig = testMock(Environment::class);
    $twig->method('render')->willReturn('');

    $router = testMock(RouterInterface::class);
    $router->method('generate')->willReturn('/form/1');

    $session = new Session(new MockArraySessionStorage());
    $requestStack = testMock(RequestStack::class);
    $requestStack->method('getSession')->willReturn($session);

    $container = testMock(PsrContainerInterface::class);
    $container->method('has')->willReturn(true);
    $container->method('get')->willReturnMap([
        ['twig', $twig],
        ['router', $router],
        ['request_stack', $requestStack],
    ]);

    return $container;
}

function makeFormController(
    ?ContentFormFactory $factory = null,
    ?EventDispatcherInterface $dispatcher = null,
    ?SubmissionHandler $handler = null,
): FormController {
    $factory ??= testMock(ContentFormFactory::class);
    $dispatcher ??= testMock(EventDispatcherInterface::class);
    $handler ??= testMock(SubmissionHandler::class);

    return new FormController(
        $factory,
        $dispatcher,
        testMock(LocationService::class),
        testMock(SearchService::class),
        $handler,
    );
}

it('renderForm throws not found exception when no identifier is provided', function (): void {
    $controller = makeFormController();
    $request = Request::create('/form/');

    expect(fn () => $controller->renderForm($request, null, null, null, null))
        ->toThrow(NotFoundHttpException::class);
});

it('renderForm throws not found exception when form name is not found', function (): void {
    $searchService = testMock(SearchService::class);
    $searchService->method('findContent')
        ->willReturn(new SearchResult(['searchHits' => [], 'totalCount' => 0]));

    $controller = new FormController(
        testMock(ContentFormFactory::class),
        testMock(EventDispatcherInterface::class),
        testMock(LocationService::class),
        $searchService,
        testMock(SubmissionHandler::class),
    );

    $request = Request::create('/form/my-form');

    expect(fn () => $controller->renderForm($request, 'my-form', null, null, null))
        ->toThrow(NotFoundHttpException::class);
});

it('renderForm returns 304 when the content has not been modified', function (): void {
    $modDate = new DateTimeImmutable('2024-01-01 12:00:00');

    $factory = testMock(ContentFormFactory::class);
    $factory->method('getFormStructure')->willReturn([
        'formName' => 'test',
        'method' => 'POST',
        'modificationDate' => $modDate,
        'fields' => [],
    ]);

    $controller = makeFormController(factory: $factory);

    $request = Request::create('/form/1');
    $request->headers->set('If-Modified-Since', $modDate->format('D, d M Y H:i:s') . ' GMT');

    $response = $controller->renderForm($request, '1', null, null, null);

    expect($response->getStatusCode())->toBe(304);
});

it('renderForm calls submission handler when form is valid and event is not cancelled', function (): void {
    $modDate = new DateTimeImmutable('2024-01-01 12:00:00');

    $mockForm = testMock(FormInterface::class);
    $mockForm->method('handleRequest')->willReturnSelf();
    $mockForm->method('isSubmitted')->willReturn(true);
    $mockForm->method('isValid')->willReturn(true);
    $mockForm->method('createView')->willReturn(new \Symfony\Component\Form\FormView());

    $factory = testMock(ContentFormFactory::class);
    $factory->method('getFormStructure')->willReturn([
        'formName' => 'test',
        'method' => 'POST',
        'modificationDate' => $modDate,
        'fields' => [],
    ]);
    $factory->method('createForm')->willReturn($mockForm);

    $dispatcher = testMock(EventDispatcherInterface::class);
    $dispatcher->method('dispatch')->willReturnArgument(0);

    $handleCalled = false;
    $handler = testMock(SubmissionHandler::class);
    $handler->method('handle')->willReturnCallback(function () use (&$handleCalled): void {
        $handleCalled = true;
    });

    $controller = makeFormController(factory: $factory, dispatcher: $dispatcher, handler: $handler);
    $controller->setContainer(makeFormContainer());

    $request = Request::create('/form/1', 'POST', ['field' => 'value']);
    $response = $controller->renderForm($request, '1', null, null, null);

    expect($response->getStatusCode())->toBe(302)
        ->and($handleCalled)->toBeTrue();
});

it('renderForm does not call submission handler when pre-validation event is cancelled', function (): void {
    $modDate = new DateTimeImmutable('2024-01-01 12:00:00');

    $mockForm = testMock(FormInterface::class);
    $mockForm->method('handleRequest')->willReturnSelf();
    $mockForm->method('isSubmitted')->willReturn(true);
    $mockForm->method('isValid')->willReturn(true);
    $mockForm->method('createView')->willReturn(new \Symfony\Component\Form\FormView());

    $factory = testMock(ContentFormFactory::class);
    $factory->method('getFormStructure')->willReturn([
        'formName' => 'test',
        'method' => 'POST',
        'modificationDate' => $modDate,
        'fields' => [],
    ]);
    $factory->method('createForm')->willReturn($mockForm);

    $dispatcher = testMock(EventDispatcherInterface::class);
    $dispatcher->method('dispatch')->willReturnCallback(function (object $event): object {
        if ($event instanceof PreValidationEvent) {
            $event->cancel();
        }

        return $event;
    });

    $handleCalled = false;
    $handler = testMock(SubmissionHandler::class);
    $handler->method('handle')->willReturnCallback(function () use (&$handleCalled): void {
        $handleCalled = true;
    });

    $controller = makeFormController(factory: $factory, dispatcher: $dispatcher, handler: $handler);
    $controller->setContainer(makeFormContainer());

    $request = Request::create('/form/1', 'POST', ['field' => 'value']);
    $response = $controller->renderForm($request, '1', null, null, null);

    expect($response->getStatusCode())->toBe(200)
        ->and($handleCalled)->toBeFalse();
});
