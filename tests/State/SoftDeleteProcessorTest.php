<?php

declare(strict_types=1);

namespace App\Tests\State;

use ApiPlatform\Metadata\Delete;
use App\Entity\Conversation;
use App\State\SoftDeleteProcessor;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

final class SoftDeleteProcessorTest extends TestCase
{
    public function testSoftDeletesInsteadOfRemoving(): void
    {
        $conversation = new Conversation();
        self::assertFalse($conversation->isDeleted());

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::never())->method('remove'); // must NOT hard-delete
        $em->expects(self::once())->method('flush');

        $processor = new SoftDeleteProcessor($em);
        $result = $processor->process($conversation, new Delete());

        self::assertSame($conversation, $result);
        self::assertTrue($conversation->isDeleted());
        self::assertNotNull($conversation->getDeletedAt());
    }

    public function testFallsBackToRemovalForNonSoftDeletable(): void
    {
        $plain = new \stdClass();

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::once())->method('remove')->with($plain);
        $em->expects(self::once())->method('flush');

        $result = (new SoftDeleteProcessor($em))->process($plain, new Delete());

        self::assertNull($result);
    }
}
