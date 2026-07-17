<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260717201123 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add availability_percent to absences (limited availability for sickness / child-sickness)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE absences ADD availability_percent INT DEFAULT 0 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE absences DROP availability_percent');
    }
}
