<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260613213811 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE absence_regions (name VARCHAR(120) NOT NULL, country_code VARCHAR(2) NOT NULL, location VARCHAR(120) DEFAULT NULL, id BINARY(16) NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, version INT DEFAULT 1 NOT NULL, workspace_id BINARY(16) NOT NULL, created_by_user_id BINARY(16) DEFAULT NULL, updated_by_user_id BINARY(16) DEFAULT NULL, INDEX IDX_6BE15C7B82D40A1F (workspace_id), INDEX IDX_6BE15C7B7D182D95 (created_by_user_id), INDEX IDX_6BE15C7B2793CC5E (updated_by_user_id), UNIQUE INDEX absence_region_workspace_country_location_unique (workspace_id, country_code, location), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE absence_region_users (absence_region_id BINARY(16) NOT NULL, user_id BINARY(16) NOT NULL, INDEX IDX_451D099B20780ED7 (absence_region_id), INDEX IDX_451D099BA76ED395 (user_id), PRIMARY KEY (absence_region_id, user_id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE absences (starts_on DATETIME NOT NULL, ends_on DATETIME NOT NULL, type VARCHAR(24) NOT NULL, description LONGTEXT DEFAULT NULL, is_half_day_on_start TINYINT NOT NULL, is_half_day_on_end TINYINT NOT NULL, is_read_only TINYINT NOT NULL, id BINARY(16) NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, deleted_at DATETIME DEFAULT NULL, version INT DEFAULT 1 NOT NULL, external_source VARCHAR(60) DEFAULT NULL, external_id VARCHAR(200) DEFAULT NULL, user_id BINARY(16) NOT NULL, workspace_id BINARY(16) NOT NULL, created_by_user_id BINARY(16) DEFAULT NULL, updated_by_user_id BINARY(16) DEFAULT NULL, INDEX IDX_F9C0EFFF82D40A1F (workspace_id), INDEX IDX_F9C0EFFF7D182D95 (created_by_user_id), INDEX IDX_F9C0EFFF2793CC5E (updated_by_user_id), INDEX absence_user_idx (user_id), INDEX absence_range_idx (starts_on, ends_on), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE teams (name VARCHAR(80) NOT NULL, description LONGTEXT DEFAULT NULL, icon VARCHAR(60) DEFAULT NULL, color VARCHAR(16) DEFAULT NULL, is_archived TINYINT NOT NULL, id BINARY(16) NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, deleted_at DATETIME DEFAULT NULL, version INT DEFAULT 1 NOT NULL, workspace_id BINARY(16) NOT NULL, created_by_user_id BINARY(16) DEFAULT NULL, updated_by_user_id BINARY(16) DEFAULT NULL, INDEX IDX_96C2225882D40A1F (workspace_id), INDEX IDX_96C222587D182D95 (created_by_user_id), INDEX IDX_96C222582793CC5E (updated_by_user_id), UNIQUE INDEX team_workspace_name_unique (workspace_id, name), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE team_members (team_id BINARY(16) NOT NULL, user_id BINARY(16) NOT NULL, INDEX IDX_BAD9A3C8296CD8AE (team_id), INDEX IDX_BAD9A3C8A76ED395 (user_id), PRIMARY KEY (team_id, user_id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE team_projects (team_id BINARY(16) NOT NULL, project_id BINARY(16) NOT NULL, INDEX IDX_E4D16FDA296CD8AE (team_id), INDEX IDX_E4D16FDA166D1F9C (project_id), PRIMARY KEY (team_id, project_id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE types_of_work (name VARCHAR(80) NOT NULL, description LONGTEXT DEFAULT NULL, icon VARCHAR(60) DEFAULT NULL, color VARCHAR(16) DEFAULT NULL, is_billable_by_default TINYINT NOT NULL, is_archived TINYINT NOT NULL, position INT NOT NULL, id BINARY(16) NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, version INT DEFAULT 1 NOT NULL, external_source VARCHAR(60) DEFAULT NULL, external_id VARCHAR(200) DEFAULT NULL, workspace_id BINARY(16) NOT NULL, created_by_user_id BINARY(16) DEFAULT NULL, updated_by_user_id BINARY(16) DEFAULT NULL, INDEX IDX_2B04578582D40A1F (workspace_id), INDEX IDX_2B0457857D182D95 (created_by_user_id), INDEX IDX_2B0457852793CC5E (updated_by_user_id), UNIQUE INDEX type_of_work_workspace_name_unique (workspace_id, name), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE user_capacities (mon_minutes INT NOT NULL, tue_minutes INT NOT NULL, wed_minutes INT NOT NULL, thu_minutes INT NOT NULL, fri_minutes INT NOT NULL, sat_minutes INT NOT NULL, sun_minutes INT NOT NULL, id BINARY(16) NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, version INT DEFAULT 1 NOT NULL, user_id BINARY(16) NOT NULL, created_by_user_id BINARY(16) DEFAULT NULL, updated_by_user_id BINARY(16) DEFAULT NULL, INDEX IDX_DC2B42077D182D95 (created_by_user_id), INDEX IDX_DC2B42072793CC5E (updated_by_user_id), UNIQUE INDEX user_capacity_user_unique (user_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE user_contact_infos (type VARCHAR(20) NOT NULL, sub_type VARCHAR(30) DEFAULT NULL, value VARCHAR(200) NOT NULL, label VARCHAR(80) DEFAULT NULL, address JSON NOT NULL, id BINARY(16) NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, version INT DEFAULT 1 NOT NULL, user_id BINARY(16) NOT NULL, created_by_user_id BINARY(16) DEFAULT NULL, updated_by_user_id BINARY(16) DEFAULT NULL, INDEX IDX_2D6E3F327D182D95 (created_by_user_id), INDEX IDX_2D6E3F322793CC5E (updated_by_user_id), INDEX user_contact_info_user_idx (user_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE workspace_absences (name VARCHAR(120) NOT NULL, starts_on DATETIME NOT NULL, ends_on DATETIME NOT NULL, description LONGTEXT DEFAULT NULL, id BINARY(16) NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, version INT DEFAULT 1 NOT NULL, workspace_id BINARY(16) NOT NULL, created_by_user_id BINARY(16) DEFAULT NULL, updated_by_user_id BINARY(16) DEFAULT NULL, INDEX IDX_6ECA0B9C82D40A1F (workspace_id), INDEX IDX_6ECA0B9C7D182D95 (created_by_user_id), INDEX IDX_6ECA0B9C2793CC5E (updated_by_user_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE absence_regions ADD CONSTRAINT FK_6BE15C7B82D40A1F FOREIGN KEY (workspace_id) REFERENCES workspaces (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE absence_regions ADD CONSTRAINT FK_6BE15C7B7D182D95 FOREIGN KEY (created_by_user_id) REFERENCES users (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE absence_regions ADD CONSTRAINT FK_6BE15C7B2793CC5E FOREIGN KEY (updated_by_user_id) REFERENCES users (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE absence_region_users ADD CONSTRAINT FK_451D099B20780ED7 FOREIGN KEY (absence_region_id) REFERENCES absence_regions (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE absence_region_users ADD CONSTRAINT FK_451D099BA76ED395 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE absences ADD CONSTRAINT FK_F9C0EFFFA76ED395 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE absences ADD CONSTRAINT FK_F9C0EFFF82D40A1F FOREIGN KEY (workspace_id) REFERENCES workspaces (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE absences ADD CONSTRAINT FK_F9C0EFFF7D182D95 FOREIGN KEY (created_by_user_id) REFERENCES users (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE absences ADD CONSTRAINT FK_F9C0EFFF2793CC5E FOREIGN KEY (updated_by_user_id) REFERENCES users (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE teams ADD CONSTRAINT FK_96C2225882D40A1F FOREIGN KEY (workspace_id) REFERENCES workspaces (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE teams ADD CONSTRAINT FK_96C222587D182D95 FOREIGN KEY (created_by_user_id) REFERENCES users (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE teams ADD CONSTRAINT FK_96C222582793CC5E FOREIGN KEY (updated_by_user_id) REFERENCES users (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE team_members ADD CONSTRAINT FK_BAD9A3C8296CD8AE FOREIGN KEY (team_id) REFERENCES teams (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE team_members ADD CONSTRAINT FK_BAD9A3C8A76ED395 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE team_projects ADD CONSTRAINT FK_E4D16FDA296CD8AE FOREIGN KEY (team_id) REFERENCES teams (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE team_projects ADD CONSTRAINT FK_E4D16FDA166D1F9C FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE types_of_work ADD CONSTRAINT FK_2B04578582D40A1F FOREIGN KEY (workspace_id) REFERENCES workspaces (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE types_of_work ADD CONSTRAINT FK_2B0457857D182D95 FOREIGN KEY (created_by_user_id) REFERENCES users (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE types_of_work ADD CONSTRAINT FK_2B0457852793CC5E FOREIGN KEY (updated_by_user_id) REFERENCES users (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE user_capacities ADD CONSTRAINT FK_DC2B4207A76ED395 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE user_capacities ADD CONSTRAINT FK_DC2B42077D182D95 FOREIGN KEY (created_by_user_id) REFERENCES users (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE user_capacities ADD CONSTRAINT FK_DC2B42072793CC5E FOREIGN KEY (updated_by_user_id) REFERENCES users (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE user_contact_infos ADD CONSTRAINT FK_2D6E3F32A76ED395 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE user_contact_infos ADD CONSTRAINT FK_2D6E3F327D182D95 FOREIGN KEY (created_by_user_id) REFERENCES users (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE user_contact_infos ADD CONSTRAINT FK_2D6E3F322793CC5E FOREIGN KEY (updated_by_user_id) REFERENCES users (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE workspace_absences ADD CONSTRAINT FK_6ECA0B9C82D40A1F FOREIGN KEY (workspace_id) REFERENCES workspaces (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE workspace_absences ADD CONSTRAINT FK_6ECA0B9C7D182D95 FOREIGN KEY (created_by_user_id) REFERENCES users (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE workspace_absences ADD CONSTRAINT FK_6ECA0B9C2793CC5E FOREIGN KEY (updated_by_user_id) REFERENCES users (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE time_entries ADD is_billed TINYINT NOT NULL, ADD type_of_work_id BINARY(16) DEFAULT NULL');
        $this->addSql('ALTER TABLE time_entries ADD CONSTRAINT FK_797F12A35B42744F FOREIGN KEY (type_of_work_id) REFERENCES types_of_work (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_797F12A35B42744F ON time_entries (type_of_work_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE absence_regions DROP FOREIGN KEY FK_6BE15C7B82D40A1F');
        $this->addSql('ALTER TABLE absence_regions DROP FOREIGN KEY FK_6BE15C7B7D182D95');
        $this->addSql('ALTER TABLE absence_regions DROP FOREIGN KEY FK_6BE15C7B2793CC5E');
        $this->addSql('ALTER TABLE absence_region_users DROP FOREIGN KEY FK_451D099B20780ED7');
        $this->addSql('ALTER TABLE absence_region_users DROP FOREIGN KEY FK_451D099BA76ED395');
        $this->addSql('ALTER TABLE absences DROP FOREIGN KEY FK_F9C0EFFFA76ED395');
        $this->addSql('ALTER TABLE absences DROP FOREIGN KEY FK_F9C0EFFF82D40A1F');
        $this->addSql('ALTER TABLE absences DROP FOREIGN KEY FK_F9C0EFFF7D182D95');
        $this->addSql('ALTER TABLE absences DROP FOREIGN KEY FK_F9C0EFFF2793CC5E');
        $this->addSql('ALTER TABLE teams DROP FOREIGN KEY FK_96C2225882D40A1F');
        $this->addSql('ALTER TABLE teams DROP FOREIGN KEY FK_96C222587D182D95');
        $this->addSql('ALTER TABLE teams DROP FOREIGN KEY FK_96C222582793CC5E');
        $this->addSql('ALTER TABLE team_members DROP FOREIGN KEY FK_BAD9A3C8296CD8AE');
        $this->addSql('ALTER TABLE team_members DROP FOREIGN KEY FK_BAD9A3C8A76ED395');
        $this->addSql('ALTER TABLE team_projects DROP FOREIGN KEY FK_E4D16FDA296CD8AE');
        $this->addSql('ALTER TABLE team_projects DROP FOREIGN KEY FK_E4D16FDA166D1F9C');
        $this->addSql('ALTER TABLE types_of_work DROP FOREIGN KEY FK_2B04578582D40A1F');
        $this->addSql('ALTER TABLE types_of_work DROP FOREIGN KEY FK_2B0457857D182D95');
        $this->addSql('ALTER TABLE types_of_work DROP FOREIGN KEY FK_2B0457852793CC5E');
        $this->addSql('ALTER TABLE user_capacities DROP FOREIGN KEY FK_DC2B4207A76ED395');
        $this->addSql('ALTER TABLE user_capacities DROP FOREIGN KEY FK_DC2B42077D182D95');
        $this->addSql('ALTER TABLE user_capacities DROP FOREIGN KEY FK_DC2B42072793CC5E');
        $this->addSql('ALTER TABLE user_contact_infos DROP FOREIGN KEY FK_2D6E3F32A76ED395');
        $this->addSql('ALTER TABLE user_contact_infos DROP FOREIGN KEY FK_2D6E3F327D182D95');
        $this->addSql('ALTER TABLE user_contact_infos DROP FOREIGN KEY FK_2D6E3F322793CC5E');
        $this->addSql('ALTER TABLE workspace_absences DROP FOREIGN KEY FK_6ECA0B9C82D40A1F');
        $this->addSql('ALTER TABLE workspace_absences DROP FOREIGN KEY FK_6ECA0B9C7D182D95');
        $this->addSql('ALTER TABLE workspace_absences DROP FOREIGN KEY FK_6ECA0B9C2793CC5E');
        $this->addSql('DROP TABLE absence_regions');
        $this->addSql('DROP TABLE absence_region_users');
        $this->addSql('DROP TABLE absences');
        $this->addSql('DROP TABLE teams');
        $this->addSql('DROP TABLE team_members');
        $this->addSql('DROP TABLE team_projects');
        $this->addSql('DROP TABLE types_of_work');
        $this->addSql('DROP TABLE user_capacities');
        $this->addSql('DROP TABLE user_contact_infos');
        $this->addSql('DROP TABLE workspace_absences');
        $this->addSql('ALTER TABLE time_entries DROP FOREIGN KEY FK_797F12A35B42744F');
        $this->addSql('DROP INDEX IDX_797F12A35B42744F ON time_entries');
        $this->addSql('ALTER TABLE time_entries DROP is_billed, DROP type_of_work_id');
    }
}
