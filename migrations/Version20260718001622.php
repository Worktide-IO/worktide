<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Multi-value contact info: contact_emails, contact_phones, social_profiles
 * (the latter attachable to a contact OR a customer), plus customers.invoice_email.
 *
 * Backfills contact_emails / contact_phones from the existing denormalized
 * Contact.email / phone / mobile columns as the primary rows, so no contact
 * loses its address. Those legacy columns are kept and stay mirrored by
 * ContactPrimaryInfoSyncListener.
 */
final class Version20260718001622 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Multi-value contact emails/phones + social profiles (contact & customer) + customer invoice email';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE contact_emails (address VARCHAR(254) NOT NULL, label VARCHAR(60) DEFAULT NULL, is_primary TINYINT DEFAULT 0 NOT NULL, is_verified TINYINT DEFAULT 0 NOT NULL, id BINARY(16) NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, contact_id BINARY(16) NOT NULL, workspace_id BINARY(16) NOT NULL, INDEX IDX_DC13DFD382D40A1F (workspace_id), INDEX contact_email_contact_idx (contact_id), INDEX contact_email_address_idx (address), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE contact_phones (number VARCHAR(40) NOT NULL, category VARCHAR(20) DEFAULT \'business\' NOT NULL, label VARCHAR(60) DEFAULT NULL, is_primary TINYINT DEFAULT 0 NOT NULL, id BINARY(16) NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, contact_id BINARY(16) NOT NULL, workspace_id BINARY(16) NOT NULL, INDEX IDX_73BA197482D40A1F (workspace_id), INDEX contact_phone_contact_idx (contact_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE social_profiles (platform VARCHAR(20) NOT NULL, url VARCHAR(500) NOT NULL, handle VARCHAR(120) DEFAULT NULL, label VARCHAR(60) DEFAULT NULL, id BINARY(16) NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, contact_id BINARY(16) DEFAULT NULL, customer_id BINARY(16) DEFAULT NULL, workspace_id BINARY(16) NOT NULL, INDEX IDX_501111C82D40A1F (workspace_id), INDEX social_profile_contact_idx (contact_id), INDEX social_profile_customer_idx (customer_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE contact_emails ADD CONSTRAINT FK_DC13DFD3E7A1254A FOREIGN KEY (contact_id) REFERENCES contacts (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE contact_emails ADD CONSTRAINT FK_DC13DFD382D40A1F FOREIGN KEY (workspace_id) REFERENCES workspaces (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE contact_phones ADD CONSTRAINT FK_73BA1974E7A1254A FOREIGN KEY (contact_id) REFERENCES contacts (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE contact_phones ADD CONSTRAINT FK_73BA197482D40A1F FOREIGN KEY (workspace_id) REFERENCES workspaces (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE social_profiles ADD CONSTRAINT FK_501111CE7A1254A FOREIGN KEY (contact_id) REFERENCES contacts (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE social_profiles ADD CONSTRAINT FK_501111C9395C3F3 FOREIGN KEY (customer_id) REFERENCES customers (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE social_profiles ADD CONSTRAINT FK_501111C82D40A1F FOREIGN KEY (workspace_id) REFERENCES workspaces (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE customers ADD invoice_email VARCHAR(254) DEFAULT NULL');

        // Backfill primary rows from the existing denormalized columns.
        $this->addSql('INSERT INTO contact_emails (id, contact_id, workspace_id, address, is_primary, is_verified, created_at, updated_at)
            SELECT UNHEX(REPLACE(UUID(), \'-\', \'\')), c.id, c.workspace_id, LOWER(c.email), 1, 0, NOW(), NOW()
            FROM contacts c
            WHERE c.email IS NOT NULL AND c.email <> \'\' AND c.deleted_at IS NULL');
        $this->addSql('INSERT INTO contact_phones (id, contact_id, workspace_id, number, category, is_primary, created_at, updated_at)
            SELECT UNHEX(REPLACE(UUID(), \'-\', \'\')), c.id, c.workspace_id, c.phone, \'business\', 1, NOW(), NOW()
            FROM contacts c
            WHERE c.phone IS NOT NULL AND c.phone <> \'\' AND c.deleted_at IS NULL');
        $this->addSql('INSERT INTO contact_phones (id, contact_id, workspace_id, number, category, is_primary, created_at, updated_at)
            SELECT UNHEX(REPLACE(UUID(), \'-\', \'\')), c.id, c.workspace_id, c.mobile, \'mobile\', 1, NOW(), NOW()
            FROM contacts c
            WHERE c.mobile IS NOT NULL AND c.mobile <> \'\' AND c.deleted_at IS NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE contact_emails DROP FOREIGN KEY FK_DC13DFD3E7A1254A');
        $this->addSql('ALTER TABLE contact_emails DROP FOREIGN KEY FK_DC13DFD382D40A1F');
        $this->addSql('ALTER TABLE contact_phones DROP FOREIGN KEY FK_73BA1974E7A1254A');
        $this->addSql('ALTER TABLE contact_phones DROP FOREIGN KEY FK_73BA197482D40A1F');
        $this->addSql('ALTER TABLE social_profiles DROP FOREIGN KEY FK_501111CE7A1254A');
        $this->addSql('ALTER TABLE social_profiles DROP FOREIGN KEY FK_501111C9395C3F3');
        $this->addSql('ALTER TABLE social_profiles DROP FOREIGN KEY FK_501111C82D40A1F');
        $this->addSql('DROP TABLE contact_emails');
        $this->addSql('DROP TABLE contact_phones');
        $this->addSql('DROP TABLE social_profiles');
        $this->addSql('ALTER TABLE customers DROP invoice_email');
    }
}
