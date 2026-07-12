<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Per-contract content i18n (Piece E1): add the `translations` JSON column to
 * agreement_line_items so a line item's `description` can carry per-locale
 * overrides (the portal renders them in the customer's language).
 */
final class Version20260712130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add translations JSON column to agreement_line_items (content i18n).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE agreement_line_items ADD translations JSON DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE agreement_line_items DROP translations');
    }
}
