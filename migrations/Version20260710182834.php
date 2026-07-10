<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260710182834 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Booking: meeting_types + bookings tables (Calendly-style appointment booking)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE bookings (start_at DATETIME NOT NULL, end_at DATETIME NOT NULL, invitee_name VARCHAR(200) NOT NULL, invitee_email VARCHAR(255) NOT NULL, invitee_timezone VARCHAR(64) DEFAULT NULL, notes LONGTEXT DEFAULT NULL, status VARCHAR(16) NOT NULL, cancel_token VARCHAR(64) NOT NULL, id BINARY(16) NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, meeting_type_id BINARY(16) NOT NULL, workspace_id BINARY(16) NOT NULL, INDEX IDX_7A853C355D9EC41E (meeting_type_id), INDEX booking_workspace_idx (workspace_id), INDEX booking_type_start_idx (meeting_type_id, start_at), UNIQUE INDEX booking_cancel_token_uniq (cancel_token), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE meeting_types (slug VARCHAR(60) NOT NULL, title VARCHAR(200) NOT NULL, description LONGTEXT DEFAULT NULL, duration_minutes INT NOT NULL, is_enabled TINYINT NOT NULL, location_type VARCHAR(20) NOT NULL, location_detail VARCHAR(500) DEFAULT NULL, timezone VARCHAR(64) NOT NULL, buffer_before_minutes INT NOT NULL, buffer_after_minutes INT NOT NULL, min_notice_minutes INT NOT NULL, max_advance_days INT NOT NULL, availability JSON NOT NULL, id BINARY(16) NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, deleted_at DATETIME DEFAULT NULL, version INT DEFAULT 1 NOT NULL, host_id BINARY(16) DEFAULT NULL, workspace_id BINARY(16) NOT NULL, INDEX IDX_3101CB301FB8D185 (host_id), INDEX meeting_type_workspace_idx (workspace_id), UNIQUE INDEX meeting_type_slug_uniq (slug), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE bookings ADD CONSTRAINT FK_7A853C355D9EC41E FOREIGN KEY (meeting_type_id) REFERENCES meeting_types (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE bookings ADD CONSTRAINT FK_7A853C3582D40A1F FOREIGN KEY (workspace_id) REFERENCES workspaces (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE meeting_types ADD CONSTRAINT FK_3101CB301FB8D185 FOREIGN KEY (host_id) REFERENCES users (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE meeting_types ADD CONSTRAINT FK_3101CB3082D40A1F FOREIGN KEY (workspace_id) REFERENCES workspaces (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE bookings DROP FOREIGN KEY FK_7A853C355D9EC41E');
        $this->addSql('ALTER TABLE bookings DROP FOREIGN KEY FK_7A853C3582D40A1F');
        $this->addSql('ALTER TABLE meeting_types DROP FOREIGN KEY FK_3101CB301FB8D185');
        $this->addSql('ALTER TABLE meeting_types DROP FOREIGN KEY FK_3101CB3082D40A1F');
        $this->addSql('DROP TABLE bookings');
        $this->addSql('DROP TABLE meeting_types');
    }
}
