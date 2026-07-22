<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260722225827 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE agreement_line_items CHANGE quantity quantity DOUBLE PRECISION DEFAULT 1 NOT NULL');
        $this->addSql('ALTER TABLE agreement_types CHANGE position position DOUBLE PRECISION DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE industries CHANGE position position DOUBLE PRECISION DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE sprints CHANGE position position DOUBLE PRECISION DEFAULT 0 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE agreement_line_items CHANGE quantity quantity DOUBLE PRECISION DEFAULT \'1\' NOT NULL');
        $this->addSql('ALTER TABLE agreement_types CHANGE position position DOUBLE PRECISION DEFAULT \'0\' NOT NULL');
        $this->addSql('ALTER TABLE industries CHANGE position position DOUBLE PRECISION DEFAULT \'0\' NOT NULL');
        $this->addSql('ALTER TABLE sprints CHANGE position position DOUBLE PRECISION DEFAULT \'0\' NOT NULL');
    }
}
