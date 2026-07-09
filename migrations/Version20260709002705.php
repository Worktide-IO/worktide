<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Per-customer portal "Freischaltung": adds customers.portal_enabled.
 *
 * Gates portal logins — see App\Security\PortalUserChecker. Opt-in, so existing
 * customers default to 0 (portal locked) until staff enable it.
 */
final class Version20260709002705 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add customers.portal_enabled (per-customer portal Freischaltung)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE customers ADD portal_enabled TINYINT(1) DEFAULT 0 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE customers DROP portal_enabled');
    }
}
