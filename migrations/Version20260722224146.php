<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260722224146 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE product_shares (status VARCHAR(16) NOT NULL, message LONGTEXT DEFAULT NULL, id BINARY(16) NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, source_workspace_id BINARY(16) NOT NULL, target_workspace_id BINARY(16) NOT NULL, product_id BINARY(16) NOT NULL, shared_copy_id BINARY(16) DEFAULT NULL, INDEX IDX_F22330D8543ED6A7 (source_workspace_id), INDEX IDX_F22330D86286D94E (target_workspace_id), INDEX IDX_F22330D84584665A (product_id), INDEX IDX_F22330D889A22BC5 (shared_copy_id), INDEX product_share_target_idx (target_workspace_id, status), UNIQUE INDEX product_share_uniq (product_id, source_workspace_id, target_workspace_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE product_shares ADD CONSTRAINT FK_F22330D8543ED6A7 FOREIGN KEY (source_workspace_id) REFERENCES workspaces (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE product_shares ADD CONSTRAINT FK_F22330D86286D94E FOREIGN KEY (target_workspace_id) REFERENCES workspaces (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE product_shares ADD CONSTRAINT FK_F22330D84584665A FOREIGN KEY (product_id) REFERENCES products (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE product_shares ADD CONSTRAINT FK_F22330D889A22BC5 FOREIGN KEY (shared_copy_id) REFERENCES products (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE agreement_line_items CHANGE quantity quantity DOUBLE PRECISION DEFAULT 1 NOT NULL');
        $this->addSql('ALTER TABLE agreement_types CHANGE position position DOUBLE PRECISION DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE industries CHANGE position position DOUBLE PRECISION DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE sprints CHANGE position position DOUBLE PRECISION DEFAULT 0 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE product_shares DROP FOREIGN KEY FK_F22330D8543ED6A7');
        $this->addSql('ALTER TABLE product_shares DROP FOREIGN KEY FK_F22330D86286D94E');
        $this->addSql('ALTER TABLE product_shares DROP FOREIGN KEY FK_F22330D84584665A');
        $this->addSql('ALTER TABLE product_shares DROP FOREIGN KEY FK_F22330D889A22BC5');
        $this->addSql('DROP TABLE product_shares');
        $this->addSql('ALTER TABLE agreement_line_items CHANGE quantity quantity DOUBLE PRECISION DEFAULT \'1\' NOT NULL');
        $this->addSql('ALTER TABLE agreement_types CHANGE position position DOUBLE PRECISION DEFAULT \'0\' NOT NULL');
        $this->addSql('ALTER TABLE industries CHANGE position position DOUBLE PRECISION DEFAULT \'0\' NOT NULL');
        $this->addSql('ALTER TABLE sprints CHANGE position position DOUBLE PRECISION DEFAULT \'0\' NOT NULL');
    }
}
