<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260721200940 extends AbstractMigration
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
        $this->addSql('ALTER TABLE products ADD position INT DEFAULT 0 NOT NULL, ADD parent_id BINARY(16) DEFAULT NULL');
        $this->addSql('ALTER TABLE products ADD CONSTRAINT FK_B3BA5A5A727ACA70 FOREIGN KEY (parent_id) REFERENCES products (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX product_parent_idx ON products (parent_id)');
        $this->addSql('ALTER TABLE services ADD position INT DEFAULT 0 NOT NULL, ADD parent_id BINARY(16) DEFAULT NULL');
        $this->addSql('ALTER TABLE services ADD CONSTRAINT FK_7332E169727ACA70 FOREIGN KEY (parent_id) REFERENCES services (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX service_parent_idx ON services (parent_id)');
        $this->addSql('ALTER TABLE sprints CHANGE position position DOUBLE PRECISION DEFAULT 0 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE agreement_line_items CHANGE quantity quantity DOUBLE PRECISION DEFAULT \'1\' NOT NULL');
        $this->addSql('ALTER TABLE agreement_types CHANGE position position DOUBLE PRECISION DEFAULT \'0\' NOT NULL');
        $this->addSql('ALTER TABLE industries CHANGE position position DOUBLE PRECISION DEFAULT \'0\' NOT NULL');
        $this->addSql('ALTER TABLE products DROP FOREIGN KEY FK_B3BA5A5A727ACA70');
        $this->addSql('DROP INDEX product_parent_idx ON products');
        $this->addSql('ALTER TABLE products DROP position, DROP parent_id');
        $this->addSql('ALTER TABLE services DROP FOREIGN KEY FK_7332E169727ACA70');
        $this->addSql('DROP INDEX service_parent_idx ON services');
        $this->addSql('ALTER TABLE services DROP position, DROP parent_id');
        $this->addSql('ALTER TABLE sprints CHANGE position position DOUBLE PRECISION DEFAULT \'0\' NOT NULL');
    }
}
