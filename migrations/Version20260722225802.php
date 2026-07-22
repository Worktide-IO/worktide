<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260722225802 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE agreement_line_items CHANGE quantity quantity DOUBLE PRECISION DEFAULT 1 NOT NULL');
        $this->addSql('ALTER TABLE agreement_types CHANGE position position DOUBLE PRECISION DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE industries CHANGE position position DOUBLE PRECISION DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE product_shares DROP FOREIGN KEY `FK_F22330D889A22BC5`');
        $this->addSql('DROP INDEX IDX_F22330D889A22BC5 ON product_shares');
        $this->addSql('ALTER TABLE product_shares DROP shared_copy_id');
        $this->addSql('ALTER TABLE products DROP FOREIGN KEY `FK_B3BA5A5A3930177E`');
        $this->addSql('ALTER TABLE products DROP FOREIGN KEY `FK_B3BA5A5A543ED6A7`');
        $this->addSql('DROP INDEX IDX_B3BA5A5A543ED6A7 ON products');
        $this->addSql('DROP INDEX IDX_B3BA5A5A3930177E ON products');
        $this->addSql('ALTER TABLE products DROP source_workspace_id, DROP source_product_id');
        $this->addSql('ALTER TABLE sprints CHANGE position position DOUBLE PRECISION DEFAULT 0 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE agreement_line_items CHANGE quantity quantity DOUBLE PRECISION DEFAULT \'1\' NOT NULL');
        $this->addSql('ALTER TABLE agreement_types CHANGE position position DOUBLE PRECISION DEFAULT \'0\' NOT NULL');
        $this->addSql('ALTER TABLE industries CHANGE position position DOUBLE PRECISION DEFAULT \'0\' NOT NULL');
        $this->addSql('ALTER TABLE product_shares ADD shared_copy_id BINARY(16) DEFAULT NULL');
        $this->addSql('ALTER TABLE product_shares ADD CONSTRAINT `FK_F22330D889A22BC5` FOREIGN KEY (shared_copy_id) REFERENCES products (id) ON UPDATE NO ACTION ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_F22330D889A22BC5 ON product_shares (shared_copy_id)');
        $this->addSql('ALTER TABLE products ADD source_workspace_id BINARY(16) DEFAULT NULL, ADD source_product_id BINARY(16) DEFAULT NULL');
        $this->addSql('ALTER TABLE products ADD CONSTRAINT `FK_B3BA5A5A3930177E` FOREIGN KEY (source_product_id) REFERENCES products (id) ON UPDATE NO ACTION ON DELETE SET NULL');
        $this->addSql('ALTER TABLE products ADD CONSTRAINT `FK_B3BA5A5A543ED6A7` FOREIGN KEY (source_workspace_id) REFERENCES workspaces (id) ON UPDATE NO ACTION ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_B3BA5A5A543ED6A7 ON products (source_workspace_id)');
        $this->addSql('CREATE INDEX IDX_B3BA5A5A3930177E ON products (source_product_id)');
        $this->addSql('ALTER TABLE sprints CHANGE position position DOUBLE PRECISION DEFAULT \'0\' NOT NULL');
    }
}
