<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260704143245 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Portal monitoring: system_uptime_days + system_incidents.';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE system_incidents (kind VARCHAR(16) NOT NULL, title VARCHAR(200) NOT NULL, started_at DATETIME NOT NULL, resolved_at DATETIME DEFAULT NULL, id BINARY(16) NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, system_id BINARY(16) NOT NULL, workspace_id BINARY(16) NOT NULL, INDEX IDX_E58E3D6D82D40A1F (workspace_id), INDEX system_incident_system_idx (system_id), INDEX system_incident_open_idx (system_id, resolved_at), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE system_uptime_days (day DATE NOT NULL, uptime_pct DOUBLE PRECISION NOT NULL, avg_response_ms INT DEFAULT NULL, sample_count INT NOT NULL, id BINARY(16) NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, system_id BINARY(16) NOT NULL, workspace_id BINARY(16) NOT NULL, INDEX IDX_ED5B703882D40A1F (workspace_id), INDEX system_uptime_day_system_idx (system_id), UNIQUE INDEX system_uptime_day_system_day_uniq (system_id, day), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE system_incidents ADD CONSTRAINT FK_E58E3D6DD0952FA5 FOREIGN KEY (system_id) REFERENCES customer_systems (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE system_incidents ADD CONSTRAINT FK_E58E3D6D82D40A1F FOREIGN KEY (workspace_id) REFERENCES workspaces (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE system_uptime_days ADD CONSTRAINT FK_ED5B7038D0952FA5 FOREIGN KEY (system_id) REFERENCES customer_systems (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE system_uptime_days ADD CONSTRAINT FK_ED5B703882D40A1F FOREIGN KEY (workspace_id) REFERENCES workspaces (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE system_incidents DROP FOREIGN KEY FK_E58E3D6DD0952FA5');
        $this->addSql('ALTER TABLE system_incidents DROP FOREIGN KEY FK_E58E3D6D82D40A1F');
        $this->addSql('ALTER TABLE system_uptime_days DROP FOREIGN KEY FK_ED5B7038D0952FA5');
        $this->addSql('ALTER TABLE system_uptime_days DROP FOREIGN KEY FK_ED5B703882D40A1F');
        $this->addSql('DROP TABLE system_incidents');
        $this->addSql('DROP TABLE system_uptime_days');
    }
}
