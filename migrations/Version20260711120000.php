<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Add bookings.rescheduled_count — how many times the invitee has moved a
 * booking. Drives the iCalendar SEQUENCE on reschedule so the updated .ics
 * supersedes the previous one in the invitee's calendar.
 */
final class Version20260711120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add rescheduled_count to bookings (appointment reschedule)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE bookings ADD rescheduled_count INT DEFAULT 0 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE bookings DROP rescheduled_count');
    }
}
