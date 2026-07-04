<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Contact;
use App\Entity\PortalFormDraft;
use App\Entity\PublicForm;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PortalFormDraft>
 */
class PortalFormDraftRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PortalFormDraft::class);
    }

    public function findOneForContact(PublicForm $form, Contact $contact): ?PortalFormDraft
    {
        return $this->findOneBy(['form' => $form, 'contact' => $contact]);
    }
}
