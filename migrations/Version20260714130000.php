<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Service catalogue (Migration B): drop the legacy service_subscriptions table.
 *
 * GATE: run this ONLY after app:migrate:subscriptions-to-services has been
 * executed on every environment (dev + stage + prod). It removes the source
 * table the data migration reads from — running it before the migration on an
 * environment would drop that environment's un-migrated rows.
 */
final class Version20260714130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Drop legacy service_subscriptions (migrated to the service catalogue)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('DROP TABLE service_subscriptions');
    }

    public function down(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE service_subscriptions (
                name VARCHAR(200) NOT NULL,
                description LONGTEXT DEFAULT NULL,
                price_cents INT NOT NULL,
                currency VARCHAR(3) NOT NULL,
                billing_cycle VARCHAR(16) NOT NULL,
                status VARCHAR(16) NOT NULL,
                started_on DATE NOT NULL,
                ended_on DATE DEFAULT NULL,
                auto_renew TINYINT NOT NULL,
                next_billing_on DATE DEFAULT NULL,
                notes LONGTEXT DEFAULT NULL,
                id BINARY(16) NOT NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                deleted_at DATETIME DEFAULT NULL,
                version INT DEFAULT 1 NOT NULL,
                external_source VARCHAR(60) DEFAULT NULL,
                external_id VARCHAR(200) DEFAULT NULL,
                customer_id BINARY(16) NOT NULL,
                system_id BINARY(16) DEFAULT NULL,
                workspace_id BINARY(16) NOT NULL,
                created_by_user_id BINARY(16) DEFAULT NULL,
                updated_by_user_id BINARY(16) DEFAULT NULL,
                INDEX IDX_D4C830837D182D95 (created_by_user_id),
                INDEX IDX_D4C830832793CC5E (updated_by_user_id),
                INDEX svc_sub_customer_idx (customer_id),
                INDEX svc_sub_system_idx (system_id),
                INDEX svc_sub_workspace_idx (workspace_id),
                INDEX svc_sub_status_idx (status),
                INDEX svc_sub_next_billing_idx (next_billing_on),
                PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4
            SQL);
        $this->addSql('ALTER TABLE service_subscriptions ADD CONSTRAINT FK_D4C830839395C3F3 FOREIGN KEY (customer_id) REFERENCES customers (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE service_subscriptions ADD CONSTRAINT FK_D4C83083D0952FA5 FOREIGN KEY (system_id) REFERENCES customer_systems (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE service_subscriptions ADD CONSTRAINT FK_D4C8308382D40A1F FOREIGN KEY (workspace_id) REFERENCES workspaces (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE service_subscriptions ADD CONSTRAINT FK_D4C830837D182D95 FOREIGN KEY (created_by_user_id) REFERENCES users (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE service_subscriptions ADD CONSTRAINT FK_D4C830832793CC5E FOREIGN KEY (updated_by_user_id) REFERENCES users (id) ON DELETE SET NULL');
    }
}
