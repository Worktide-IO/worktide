<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Contact absences — a customer contact's own away-dates, set in the portal and
 * visible to staff. Informational (does not affect booking slots).
 */
final class Version20260711160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Contact absences (client-set, staff-visible)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE contact_absences (starts_on DATETIME NOT NULL, ends_on DATETIME NOT NULL, note LONGTEXT DEFAULT NULL, version INT DEFAULT 1 NOT NULL, id BINARY(16) NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, deleted_at DATETIME DEFAULT NULL, contact_id BINARY(16) NOT NULL, customer_id BINARY(16) NOT NULL, workspace_id BINARY(16) NOT NULL, INDEX contact_absence_workspace_idx (workspace_id), INDEX contact_absence_contact_idx (contact_id), INDEX contact_absence_customer_idx (customer_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE contact_absences ADD CONSTRAINT FK_contact_absence_contact FOREIGN KEY (contact_id) REFERENCES contacts (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE contact_absences ADD CONSTRAINT FK_contact_absence_customer FOREIGN KEY (customer_id) REFERENCES customers (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE contact_absences ADD CONSTRAINT FK_contact_absence_workspace FOREIGN KEY (workspace_id) REFERENCES workspaces (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE contact_absences DROP FOREIGN KEY FK_contact_absence_contact');
        $this->addSql('ALTER TABLE contact_absences DROP FOREIGN KEY FK_contact_absence_customer');
        $this->addSql('ALTER TABLE contact_absences DROP FOREIGN KEY FK_contact_absence_workspace');
        $this->addSql('DROP TABLE contact_absences');
    }
}
