<?php

declare(strict_types=1);

namespace vardumper\IbexaFormBuilderBundle\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\Query;
use Doctrine\Persistence\ManagerRegistry;
use vardumper\IbexaFormBuilderBundle\Entity\FormSubmission;

/** @extends ServiceEntityRepository<FormSubmission> */
class FormSubmissionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, FormSubmission::class);
    }

    /** @return FormSubmission[] */
    public function findByContentId(int $contentId): array
    {
        return $this->createQueryBuilder('s')
            ->andWhere('s.contentId = :contentId')
            ->setParameter('contentId', $contentId)
            ->orderBy('s.submittedAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function findPaginatedQuery(?int $contentId = null): Query
    {
        $qb = $this->createQueryBuilder('s')
            ->orderBy('s.submittedAt', 'DESC');

        if ($contentId !== null) {
            $qb->andWhere('s.contentId = :contentId')
                ->setParameter('contentId', $contentId);
        }

        return $qb->getQuery();
    }
}
