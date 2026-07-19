<?php

declare(strict_types=1);

namespace App\Tests\ApiPlatform\Doctrine;

use App\ApiPlatform\Doctrine\SoftDeleteExtension;
use App\Entity\Conversation;
use App\Entity\InboundEvent;
use Doctrine\ORM\QueryBuilder;
use PHPUnit\Framework\TestCase;

final class SoftDeleteExtensionTest extends TestCase
{
    public function testFiltersSoftDeletableResource(): void
    {
        $qb = $this->createMock(QueryBuilder::class);
        $qb->method('getRootAliases')->willReturn(['e']);
        $qb->expects(self::once())->method('andWhere')->with('e.deletedAt IS NULL')->willReturnSelf();

        (new SoftDeleteExtension())->applyToCollection($qb, $this->qng(), Conversation::class);
    }

    public function testSkipsNonSoftDeletableResource(): void
    {
        $qb = $this->createMock(QueryBuilder::class);
        $qb->method('getRootAliases')->willReturn(['e']);
        $qb->expects(self::never())->method('andWhere'); // InboundEvent has no SoftDeletableTrait

        (new SoftDeleteExtension())->applyToCollection($qb, $this->qng(), InboundEvent::class);
    }

    private function qng(): \ApiPlatform\Doctrine\Orm\Util\QueryNameGeneratorInterface
    {
        return $this->createStub(\ApiPlatform\Doctrine\Orm\Util\QueryNameGeneratorInterface::class);
    }
}
