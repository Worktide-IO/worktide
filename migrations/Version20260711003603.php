<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Add WorkspaceMember.is_active — soft deactivation of a member within a
 * workspace (blocks access without deleting the membership row).
 */
final class Version20260711003603 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add is_active flag to workspace_members (member deactivation)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE workspace_members ADD is_active TINYINT DEFAULT 1 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE workspace_members DROP is_active');
    }
}
