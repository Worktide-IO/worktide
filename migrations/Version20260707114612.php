<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260707114612 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add invitation email dispatch tracking (sent_at, send_count) to workspace_invitations';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE workspace_invitations ADD sent_at DATETIME DEFAULT NULL, ADD send_count INT DEFAULT 0 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE workspace_invitations DROP sent_at, DROP send_count');
    }
}
