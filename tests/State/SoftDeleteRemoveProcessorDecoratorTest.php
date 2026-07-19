<?php

declare(strict_types=1);

namespace App\Tests\State;

use ApiPlatform\Metadata\Delete;
use ApiPlatform\State\ProcessorInterface;
use App\Entity\Conversation;
use App\Entity\CustomerProduct;
use App\State\SoftDeleteRemoveProcessorDecorator;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

final class SoftDeleteRemoveProcessorDecoratorTest extends TestCase
{
    public function testSoftDeletableIsSoftDeletedNotForwarded(): void
    {
        $convo = new Conversation();
        $inner = $this->createMock(ProcessorInterface::class);
        $inner->expects(self::never())->method('process'); // not hard-deleted
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::once())->method('flush');

        $result = (new SoftDeleteRemoveProcessorDecorator($inner, $em))->process($convo, new Delete());

        self::assertSame($convo, $result);
        self::assertTrue($convo->isDeleted());
    }

    public function testHardDeleteOnlyIsForwardedToRealRemove(): void
    {
        $pivot = new CustomerProduct(); // implements HardDeleteOnly
        $inner = $this->createMock(ProcessorInterface::class);
        $inner->expects(self::once())->method('process')->with($pivot);
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::never())->method('flush');

        (new SoftDeleteRemoveProcessorDecorator($inner, $em))->process($pivot, new Delete());

        self::assertFalse($pivot->isDeleted(), 'pivot must not be soft-deleted');
    }

    public function testNonSoftDeletableIsForwarded(): void
    {
        $plain = new \stdClass();
        $inner = $this->createMock(ProcessorInterface::class);
        $inner->expects(self::once())->method('process')->with($plain);

        (new SoftDeleteRemoveProcessorDecorator($inner, $this->createMock(EntityManagerInterface::class)))
            ->process($plain, new Delete());
    }
}
