<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260615101022 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE watches (target VARCHAR(16) NOT NULL, target_id BINARY(16) NOT NULL, id BINARY(16) NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, user_id BINARY(16) NOT NULL, workspace_id BINARY(16) NOT NULL, INDEX IDX_C588D63A82D40A1F (workspace_id), INDEX watch_target_idx (target, target_id), INDEX watch_user_idx (user_id), UNIQUE INDEX watch_unique (workspace_id, target, target_id, user_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE watches ADD CONSTRAINT FK_C588D63AA76ED395 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE watches ADD CONSTRAINT FK_C588D63A82D40A1F FOREIGN KEY (workspace_id) REFERENCES workspaces (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE watches DROP FOREIGN KEY FK_C588D63AA76ED395');
        $this->addSql('ALTER TABLE watches DROP FOREIGN KEY FK_C588D63A82D40A1F');
        $this->addSql('DROP TABLE watches');
    }
}
