<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260704133752 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Portal: customer_agreements signer fields (digital signing).';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE customer_agreements ADD signed_by_name VARCHAR(160) DEFAULT NULL, ADD signed_by_contact_id BINARY(16) DEFAULT NULL');
        $this->addSql('ALTER TABLE customer_agreements ADD CONSTRAINT FK_DAEA4A022CB158E6 FOREIGN KEY (signed_by_contact_id) REFERENCES contacts (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_DAEA4A022CB158E6 ON customer_agreements (signed_by_contact_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE customer_agreements DROP FOREIGN KEY FK_DAEA4A022CB158E6');
        $this->addSql('DROP INDEX IDX_DAEA4A022CB158E6 ON customer_agreements');
        $this->addSql('ALTER TABLE customer_agreements DROP signed_by_name, DROP signed_by_contact_id');
    }
}
