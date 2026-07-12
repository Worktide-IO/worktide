<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Newsletter node metadata: slug (per-workspace unique handle), icon + color,
 * isArchived (retire without deleting) and isSubscribable (structure-only nodes).
 */
final class Version20260712125550 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Newsletter node metadata: slug, icon, color, is_archived, is_subscribable';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE newsletters ADD slug VARCHAR(64) DEFAULT NULL, ADD icon VARCHAR(40) NOT NULL DEFAULT \'mail\', ADD color VARCHAR(16) NOT NULL DEFAULT \'#94a3b8\', ADD is_archived TINYINT(1) DEFAULT 0 NOT NULL, ADD is_subscribable TINYINT(1) DEFAULT 1 NOT NULL');
        $this->addSql('CREATE UNIQUE INDEX newsletter_ws_slug_uniq ON newsletters (workspace_id, slug)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX newsletter_ws_slug_uniq ON newsletters');
        $this->addSql('ALTER TABLE newsletters DROP slug, DROP icon, DROP color, DROP is_archived, DROP is_subscribable');
    }
}
