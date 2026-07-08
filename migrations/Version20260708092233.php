<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260708092233 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Tally-like form engine: add schema (v2 document) + schema_version to public_forms';
    }

    public function up(Schema $schema): void
    {
        // `form_schema`, not `schema` — the latter is a MySQL reserved word that
        // Doctrine does not quote in INSERT/UPDATE DML.
        $this->addSql('ALTER TABLE public_forms ADD form_schema JSON DEFAULT NULL, ADD schema_version INT DEFAULT 1 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE public_forms DROP form_schema, DROP schema_version');
    }
}
