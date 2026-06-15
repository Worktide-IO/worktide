<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260615231404 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'awork-Schema-Abgleich Quick-Wins: Task.correlationId/createdVia/startOn, Project.isExternal/isProjectKeyVisible, TimeEntry.isExternal/timezone';
    }

    public function up(Schema $schema): void
    {
        // Two-step: add NOT NULL columns with a temporary DEFAULT so
        // existing rows back-fill, then strip the DEFAULT so the ORM
        // mapping (which carries the PHP-side default) stays in sync.
        $this->addSql("ALTER TABLE projects ADD is_external TINYINT(1) NOT NULL DEFAULT 0, ADD is_project_key_visible TINYINT(1) NOT NULL DEFAULT 1");
        $this->addSql("ALTER TABLE tasks ADD start_on DATETIME DEFAULT NULL, ADD correlation_id BINARY(16) DEFAULT NULL, ADD created_via VARCHAR(16) NOT NULL DEFAULT 'created'");
        $this->addSql('CREATE INDEX task_correlation_idx ON tasks (correlation_id)');
        $this->addSql("ALTER TABLE time_entries ADD is_external TINYINT(1) NOT NULL DEFAULT 0, ADD timezone VARCHAR(64) DEFAULT NULL");

        $this->addSql("ALTER TABLE projects ALTER is_external DROP DEFAULT, ALTER is_project_key_visible DROP DEFAULT");
        $this->addSql("ALTER TABLE tasks ALTER created_via DROP DEFAULT");
        $this->addSql("ALTER TABLE time_entries ALTER is_external DROP DEFAULT");
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE projects DROP is_external, DROP is_project_key_visible');
        $this->addSql('DROP INDEX task_correlation_idx ON tasks');
        $this->addSql('ALTER TABLE tasks DROP start_on, DROP correlation_id, DROP created_via');
        $this->addSql('ALTER TABLE time_entries DROP is_external, DROP timezone');
    }
}
