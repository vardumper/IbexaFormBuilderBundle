<?php

declare(strict_types=1);

namespace vardumper\IbexaFormBuilderBundle\Controller\Admin;

use Doctrine\ORM\EntityManagerInterface;
use Ibexa\Contracts\AdminUi\Controller\Controller;
use Pagerfanta\Doctrine\ORM\QueryAdapter;
use Pagerfanta\Pagerfanta;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use vardumper\IbexaFormBuilderBundle\Form\Type\FormSubmissionFilterType;
use vardumper\IbexaFormBuilderBundle\Repository\FormSubmissionRepository;

#[Route('/admin/form-submissions', name: 'ibexa_form_builder.')]
final class FormSubmissionsController extends Controller
{
    private const PER_PAGE = 25;

    public function __construct(
        private readonly FormSubmissionRepository $repository,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    #[Route('', name: 'submissions_list', methods: ['GET'])]
    public function listAction(Request $request): Response
    {
        $filterForm = $this->createForm(FormSubmissionFilterType::class, null, [
            'action' => $this->generateUrl('ibexa_form_builder.submissions_list'),
        ]);
        $filterForm->handleRequest($request);

        $contentId = null;
        if ($filterForm->isSubmitted() && $filterForm->isValid()) {
            $contentId = $filterForm->get('contentId')->getData();
        }

        $query = $this->repository->findPaginatedQuery($contentId);
        $pagerfanta = new Pagerfanta(new QueryAdapter($query));
        $pagerfanta->setMaxPerPage(self::PER_PAGE);
        $pagerfanta->setCurrentPage(\max(1, (int) $request->query->get('page', 1)));

        return $this->render('@IbexaFormBuilderBundle/admin/form_submissions/list.html.twig', [
            'submissions' => $pagerfanta,
            'filterForm' => $filterForm->createView(),
        ]);
    }

    #[Route('/{id}', name: 'submission_view', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function viewAction(int $id): Response
    {
        $submission = $this->repository->find($id);
        if ($submission === null) {
            throw $this->createNotFoundException(sprintf('Form submission #%d not found.', $id));
        }

        return $this->render('@IbexaFormBuilderBundle/admin/form_submissions/view.html.twig', [
            'submission' => $submission,
        ]);
    }

    #[Route('/{id}/delete', name: 'submission_delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function deleteAction(Request $request, int $id): RedirectResponse
    {
        $submission = $this->repository->find($id);
        if ($submission === null) {
            throw $this->createNotFoundException(sprintf('Form submission #%d not found.', $id));
        }

        $this->entityManager->remove($submission);
        $this->entityManager->flush();

        $this->addFlash('success', \sprintf('Submission #%d has been deleted.', $id));

        return $this->redirectToRoute('ibexa_form_builder.submissions_list');
    }
}
