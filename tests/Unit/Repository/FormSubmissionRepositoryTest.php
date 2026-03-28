<?php

declare(strict_types=1);

use Doctrine\Persistence\ManagerRegistry;
use vardumper\IbexaFormBuilderBundle\Entity\FormSubmission;
use vardumper\IbexaFormBuilderBundle\Repository\FormSubmissionRepository;

/** Bootstrap a FormSubmissionRepository backed by a fresh SQLite EntityManager. */
function makeRepoWithEm(): array
{
    $em = sqliteEm();
    $registry = testMock(ManagerRegistry::class);
    $registry->method('getManagerForClass')->willReturn($em);

    return [new FormSubmissionRepository($registry), $em];
}

it('findByContentId returns submissions matching the given content id', function () {
    [$repo, $em] = makeRepoWithEm();

    $s1 = FormSubmission::create(7, ['field' => 'a'], '127.0.0.1');
    $s2 = FormSubmission::create(7, ['field' => 'b'], null);
    $s3 = FormSubmission::create(99, [], null);

    $em->persist($s1);
    $em->persist($s2);
    $em->persist($s3);
    $em->flush();

    $results = $repo->findByContentId(7);

    expect($results)->toHaveCount(2);
    /** @var FormSubmission $first */
    foreach ($results as $submission) {
        expect($submission->getContentId())->toBe(7);
    }
});

it('findPaginatedQuery returns Doctrine Query with optional contentId filter', function () {
    [$repo] = makeRepoWithEm();

    $queryAll = $repo->findPaginatedQuery();
    $queryFiltered = $repo->findPaginatedQuery(42);

    expect($queryAll)->toBeInstanceOf(Doctrine\ORM\Query::class)
        ->and($queryFiltered)->toBeInstanceOf(Doctrine\ORM\Query::class)
        ->and($queryFiltered->getDQL())->toContain('s.contentId = :contentId');
});
