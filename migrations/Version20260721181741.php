<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260721181741 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE customer_bookmarks (label VARCHAR(200) NOT NULL, type VARCHAR(8) NOT NULL, host VARCHAR(253) NOT NULL, port INT DEFAULT NULL, credentials JSON NOT NULL, connect_config JSON NOT NULL, notes LONGTEXT DEFAULT NULL, is_enabled TINYINT NOT NULL, is_shared TINYINT NOT NULL, portal_visible TINYINT NOT NULL, last_used_at DATETIME DEFAULT NULL, use_count INT DEFAULT 0 NOT NULL, id BINARY(16) NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, deleted_at DATETIME DEFAULT NULL, version INT DEFAULT 1 NOT NULL, customer_id BINARY(16) DEFAULT NULL, system_id BINARY(16) DEFAULT NULL, owner_user_id BINARY(16) DEFAULT NULL, created_by_user_id BINARY(16) DEFAULT NULL, updated_by_user_id BINARY(16) DEFAULT NULL, workspace_id BINARY(16) NOT NULL, INDEX IDX_D32F395AD0952FA5 (system_id), INDEX IDX_D32F395A2B18554A (owner_user_id), INDEX IDX_D32F395A7D182D95 (created_by_user_id), INDEX IDX_D32F395A2793CC5E (updated_by_user_id), INDEX IDX_D32F395A82D40A1F (workspace_id), INDEX bookmark_customer_idx (customer_id), INDEX bookmark_workspace_idx (workspace_id, type), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE customer_bookmark_tag (customer_bookmark_id BINARY(16) NOT NULL, tag_id BINARY(16) NOT NULL, INDEX IDX_2D13BC53913F362D (customer_bookmark_id), INDEX IDX_2D13BC53BAD26311 (tag_id), PRIMARY KEY (customer_bookmark_id, tag_id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE customer_bookmarks ADD CONSTRAINT FK_D32F395A9395C3F3 FOREIGN KEY (customer_id) REFERENCES customers (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE customer_bookmarks ADD CONSTRAINT FK_D32F395AD0952FA5 FOREIGN KEY (system_id) REFERENCES customer_systems (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE customer_bookmarks ADD CONSTRAINT FK_D32F395A2B18554A FOREIGN KEY (owner_user_id) REFERENCES users (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE customer_bookmarks ADD CONSTRAINT FK_D32F395A7D182D95 FOREIGN KEY (created_by_user_id) REFERENCES users (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE customer_bookmarks ADD CONSTRAINT FK_D32F395A2793CC5E FOREIGN KEY (updated_by_user_id) REFERENCES users (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE customer_bookmarks ADD CONSTRAINT FK_D32F395A82D40A1F FOREIGN KEY (workspace_id) REFERENCES workspaces (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE customer_bookmark_tag ADD CONSTRAINT FK_2D13BC53913F362D FOREIGN KEY (customer_bookmark_id) REFERENCES customer_bookmarks (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE customer_bookmark_tag ADD CONSTRAINT FK_2D13BC53BAD26311 FOREIGN KEY (tag_id) REFERENCES tags (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE absences CHANGE availability_percent availability_percent INT NOT NULL');
        $this->addSql('ALTER TABLE agreement_line_items CHANGE quantity quantity DOUBLE PRECISION DEFAULT 1 NOT NULL');
        $this->addSql('ALTER TABLE agreement_types CHANGE position position DOUBLE PRECISION DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE industries CHANGE position position DOUBLE PRECISION DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE sprints CHANGE position position DOUBLE PRECISION DEFAULT 0 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE customer_bookmarks DROP FOREIGN KEY FK_D32F395A9395C3F3');
        $this->addSql('ALTER TABLE customer_bookmarks DROP FOREIGN KEY FK_D32F395AD0952FA5');
        $this->addSql('ALTER TABLE customer_bookmarks DROP FOREIGN KEY FK_D32F395A2B18554A');
        $this->addSql('ALTER TABLE customer_bookmarks DROP FOREIGN KEY FK_D32F395A7D182D95');
        $this->addSql('ALTER TABLE customer_bookmarks DROP FOREIGN KEY FK_D32F395A2793CC5E');
        $this->addSql('ALTER TABLE customer_bookmarks DROP FOREIGN KEY FK_D32F395A82D40A1F');
        $this->addSql('ALTER TABLE customer_bookmark_tag DROP FOREIGN KEY FK_2D13BC53913F362D');
        $this->addSql('ALTER TABLE customer_bookmark_tag DROP FOREIGN KEY FK_2D13BC53BAD26311');
        $this->addSql('DROP TABLE customer_bookmarks');
        $this->addSql('DROP TABLE customer_bookmark_tag');
        $this->addSql('ALTER TABLE absences CHANGE availability_percent availability_percent INT DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE agreement_line_items CHANGE quantity quantity DOUBLE PRECISION DEFAULT \'1\' NOT NULL');
        $this->addSql('ALTER TABLE agreement_types CHANGE position position DOUBLE PRECISION DEFAULT \'0\' NOT NULL');
        $this->addSql('ALTER TABLE industries CHANGE position position DOUBLE PRECISION DEFAULT \'0\' NOT NULL');
        $this->addSql('ALTER TABLE sprints CHANGE position position DOUBLE PRECISION DEFAULT \'0\' NOT NULL');
    }
}
