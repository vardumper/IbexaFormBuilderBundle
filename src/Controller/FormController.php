<?php

declare(strict_types=1);

namespace vardumper\IbexaFormBuilderBundle\Controller;

use Ibexa\Contracts\Core\Repository\LocationService;
use Ibexa\Contracts\Core\Repository\SearchService;
use Ibexa\Contracts\Core\Repository\Values\Content\Query;
use Ibexa\Contracts\Core\Repository\Values\Content\Query\Criterion;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Annotation\Route;
use vardumper\IbexaFormBuilderBundle\Service\ContentFormFactory;
use vardumper\IbexaFormBuilderBundle\Service\SubmissionHandler;

#[AsController]
final class FormController extends AbstractController
{
    public function __construct(
        private readonly ContentFormFactory $contentFormFactory,
        private readonly SubmissionHandler $submissionHandler,
        private readonly LocationService $locationService,
        private readonly SearchService $searchService,
    ) {
    }

    /**
     * Render a form content item identified by exactly one of:
     *   - contentId  (int)    direct content ID
     *   - locationId (int)    main location ID whose contentInfo.id is used
     *   - formName   (string) value of the form_builder_name field on the form content type
     */
    #[Route(
        path: '/form/{:identifier}',
        name: 'render_form',
        methods: ['GET', 'POST'],
    )]
    public function renderForm(
        Request $request,
        ?int $contentId = null,
        ?int $locationId = null,
        ?string $formName = null,
    ): Response {
        $provided = array_filter(
            ['contentId' => $contentId, 'locationId' => $locationId, 'formName' => $formName],
            static fn (mixed $v): bool => $v !== null,
        );

        if (count($provided) !== 1) {
            throw $this->createNotFoundException(
                sprintf('Exactly one of contentId, locationId, or formName must be provided (%d given).', count($provided)),
            );
        }

        if ($locationId !== null) {
            $location = $this->locationService->loadLocation($locationId);
            $contentId = $location->contentId;
        } elseif ($formName !== null) {
            $query = new Query();
            $query->filter = new Criterion\LogicalAnd([
                new Criterion\ContentTypeIdentifier('form_builder_form'),
                new Criterion\Field('form_builder_name', Criterion\Operator::EQ, $formName),
            ]);
            $query->limit = 1;

            $results = $this->searchService->findContent($query);

            if ($results->totalCount === 0) {
                throw $this->createNotFoundException(sprintf('Form with name "%s" not found.', $formName));
            }

            $contentId = $results->searchHits[0]->valueObject->id;
        }

        $structure = $this->contentFormFactory->getFormStructure($contentId);

        if ($request->isMethod('GET')) {
            $cacheValidationResponse = new Response();
            /** Headers below are no-ops for render() subrequests; they take effect only on direct access or render_esi(). */
            $cacheValidationResponse->setSharedMaxAge(3600);
            $cacheValidationResponse->headers->addCacheControlDirective('must-revalidate');
            $cacheValidationResponse->setLastModified($structure['modificationDate']);

            if ($cacheValidationResponse->isNotModified($request)) {
                return $cacheValidationResponse;
            }
        }

        $form = $this->contentFormFactory->createForm(
            $structure,
            $this->generateUrl('render_form', ['contentId' => $contentId]),
        );
        $form->handleRequest($request);

        if ($request->isMethod('POST') && $form->isSubmitted() && $form->isValid()) {
            $this->submissionHandler->handle($contentId, $request->request->all(), $request->getClientIp());
            $this->addFlash('success', 'Your submission has been received. Thank you!');

            return $this->redirectToRoute('render_form', ['contentId' => $contentId]);
        }

        $response = new Response($this->renderView('@IbexaFormBuilderBundle/form/form.html.twig', [
            'form' => $form->createView(),
            'contentId' => $contentId,
        ]));

        if ($request->isMethod('GET')) {
            $response->setSharedMaxAge(3600);
            $response->headers->addCacheControlDirective('must-revalidate');
            $response->setLastModified($structure['modificationDate']);
        }

        return $response;
    }
}
