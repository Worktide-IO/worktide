<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Add contacts.locale — preferred language for mail sent to a customer contact
 * (closes the recipient-locale gap for newsletter/portal mail; a Contact isn't
 * always a portal User). i18n Phase 0.
 */
final class Version20260711220000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add locale to contacts (i18n recipient locale)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE contacts ADD locale VARCHAR(8) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE contacts DROP locale');
    }
}
