<?php

declare(strict_types=1);

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Container\ContainerInterface as PsrContainerInterface;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormView;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\RouterInterface;
use Twig\Environment;
use vardumper\IbexaFormBuilderBundle\Controller\Admin\FormSubmissionsController;
use vardumper\IbexaFormBuilderBundle\Repository\FormSubmissionRepository;

function makeAdminContainer(): PsrContainerInterface
{
    $twig = testMock(Environment::class);
    $twig->method('render')->willReturn('');

    $router = testMock(RouterInterface::class);
    $router->method('generate')->willReturn('/admin/form-submissions');

    $session = new Session(new MockArraySessionStorage());
    $requestStack = testMock(RequestStack::class);
    $requestStack->method('getSession')->willReturn($session);

    $container = testMock(PsrContainerInterface::class);
    $container->method('has')->willReturn(true);
    $mockForm = testMock(FormInterface::class);
    $mockForm->method('handleRequest')->willReturnSelf();
    $mockForm->method('isSubmitted')->willReturn(false);
    $mockForm->method('isValid')->willReturn(false);
    $mockForm->method('createView')->willReturn(new FormView());

    $formFactory = testMock(FormFactoryInterface::class);
    $formFactory->method('create')->willReturn($mockForm);

    $container->method('get')->willReturnMap([
        ['twig', $twig],
        ['router', $router],
        ['form.factory', $formFactory],
        ['request_stack', $requestStack],
    ]);

    return $container;
}

it('viewAction throws a not found exception when the submission does not exist', function (): void {
    $repository = testMock(FormSubmissionRepository::class);
    $repository->method('find')->willReturn(null);

    $controller = new FormSubmissionsController($repository, testMock(EntityManagerInterface::class));

    expect(fn () => $controller->viewAction(999))->toThrow(NotFoundHttpException::class);
});

it('deleteAction throws a not found exception when the submission does not exist', function (): void {
    $repository = testMock(FormSubmissionRepository::class);
    $repository->method('find')->willReturn(null);

    $controller = new FormSubmissionsController($repository, testMock(EntityManagerInterface::class));
    $request = Request::create('/admin/form-submissions/999/delete', 'POST');

    expect(fn () => $controller->deleteAction($request, 999))->toThrow(NotFoundHttpException::class);
});

it('viewAction renders the submission view when the submission exists', function (): void {
    $submission = \vardumper\IbexaFormBuilderBundle\Entity\FormSubmission::create(42, ['field' => 'val']);

    $repository = testMock(FormSubmissionRepository::class);
    $repository->method('find')->willReturn($submission);

    $controller = new FormSubmissionsController($repository, testMock(EntityManagerInterface::class));
    $controller->setContainer(makeAdminContainer());

    $response = $controller->viewAction(1);

    expect($response->getStatusCode())->toBe(200);
});

it('deleteAction removes the submission and redirects on success', function (): void {
    $submission = \vardumper\IbexaFormBuilderBundle\Entity\FormSubmission::create(42, ['field' => 'val']);

    $repository = testMock(FormSubmissionRepository::class);
    $repository->method('find')->willReturn($submission);

    $removeCalled = false;
    $flushCalled = false;

    $em = testMock(EntityManagerInterface::class);
    $em->method('remove')->willReturnCallback(function () use (&$removeCalled): void {
        $removeCalled = true;
    });
    $em->method('flush')->willReturnCallback(function () use (&$flushCalled): void {
        $flushCalled = true;
    });

    $controller = new FormSubmissionsController($repository, $em);
    $controller->setContainer(makeAdminContainer());

    $request = Request::create('/admin/form-submissions/1/delete', 'POST');
    $response = $controller->deleteAction($request, 1);

    expect($response->getStatusCode())->toBe(302)
        ->and($removeCalled)->toBeTrue()
        ->and($flushCalled)->toBeTrue();
});

it('listAction renders the paginated list of submissions', function (): void {
    $em = sqliteEm();
    $registry = testMock(ManagerRegistry::class);
    $registry->method('getManagerForClass')->willReturn($em);

    $repository = new FormSubmissionRepository($registry);
    $controller = new FormSubmissionsController($repository, $em);
    $controller->setContainer(makeAdminContainer());

    $request = Request::create('/admin/form-submissions', 'GET');
    $response = $controller->listAction($request);

    expect($response->getStatusCode())->toBe(200);
});
