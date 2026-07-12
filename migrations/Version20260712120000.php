<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Extend the content-i18n `translations` JSON column to the two remaining
 * admin-authored, customer-facing entities: newsletters (topic/category tree)
 * and meeting_types (bookable categories). These are shown to portal customers
 * who may not speak the workspace language, so their title/description need
 * per-locale overrides (mirrors Version20260710104813 for the config entities).
 */
final class Version20260712120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add translations JSON column to newsletters + meeting_types (content i18n).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE newsletters ADD translations JSON DEFAULT NULL');
        $this->addSql('ALTER TABLE meeting_types ADD translations JSON DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE newsletters DROP translations');
        $this->addSql('ALTER TABLE meeting_types DROP translations');
    }
}
