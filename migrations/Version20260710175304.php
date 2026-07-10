<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260710175304 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Newsletter tree + per-contact subscriptions + per-customer newsletter enablement';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE newsletter_subscriptions (id BINARY(16) NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, newsletter_id BINARY(16) NOT NULL, contact_id BINARY(16) NOT NULL, workspace_id BINARY(16) NOT NULL, INDEX IDX_B3C13B0B82D40A1F (workspace_id), INDEX newsletter_sub_newsletter_idx (newsletter_id), INDEX newsletter_sub_contact_idx (contact_id), UNIQUE INDEX newsletter_sub_newsletter_contact_uniq (newsletter_id, contact_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE newsletters (title VARCHAR(200) NOT NULL, description LONGTEXT DEFAULT NULL, position DOUBLE PRECISION NOT NULL, id BINARY(16) NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, deleted_at DATETIME DEFAULT NULL, parent_id BINARY(16) DEFAULT NULL, workspace_id BINARY(16) NOT NULL, INDEX newsletter_workspace_idx (workspace_id), INDEX newsletter_parent_idx (parent_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE newsletter_subscriptions ADD CONSTRAINT FK_B3C13B0B22DB1917 FOREIGN KEY (newsletter_id) REFERENCES newsletters (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE newsletter_subscriptions ADD CONSTRAINT FK_B3C13B0BE7A1254A FOREIGN KEY (contact_id) REFERENCES contacts (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE newsletter_subscriptions ADD CONSTRAINT FK_B3C13B0B82D40A1F FOREIGN KEY (workspace_id) REFERENCES workspaces (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE newsletters ADD CONSTRAINT FK_8ECF000C727ACA70 FOREIGN KEY (parent_id) REFERENCES newsletters (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE newsletters ADD CONSTRAINT FK_8ECF000C82D40A1F FOREIGN KEY (workspace_id) REFERENCES workspaces (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE customers ADD enabled_newsletter_ids JSON DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE newsletter_subscriptions DROP FOREIGN KEY FK_B3C13B0B22DB1917');
        $this->addSql('ALTER TABLE newsletter_subscriptions DROP FOREIGN KEY FK_B3C13B0BE7A1254A');
        $this->addSql('ALTER TABLE newsletter_subscriptions DROP FOREIGN KEY FK_B3C13B0B82D40A1F');
        $this->addSql('ALTER TABLE newsletters DROP FOREIGN KEY FK_8ECF000C727ACA70');
        $this->addSql('ALTER TABLE newsletters DROP FOREIGN KEY FK_8ECF000C82D40A1F');
        $this->addSql('DROP TABLE newsletter_subscriptions');
        $this->addSql('DROP TABLE newsletters');
        $this->addSql('ALTER TABLE customers DROP enabled_newsletter_ids');
    }
}
