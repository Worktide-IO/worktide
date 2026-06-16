<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260615235204 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE refresh_tokens ADD user_id BINARY(16) DEFAULT NULL, ADD created_at DATETIME DEFAULT NULL, ADD last_seen_at DATETIME DEFAULT NULL, ADD user_agent VARCHAR(255) DEFAULT NULL, ADD ip_address VARCHAR(45) DEFAULT NULL');
        $this->addSql('CREATE INDEX refresh_user_idx ON refresh_tokens (user_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP INDEX refresh_user_idx ON refresh_tokens');
        $this->addSql('ALTER TABLE refresh_tokens DROP user_id, DROP created_at, DROP last_seen_at, DROP user_agent, DROP ip_address');
    }
}
