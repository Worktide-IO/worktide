<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260626014306 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Conversation→Task: tasks.source_conversation_id (Phase C Schicht 4)';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE tasks ADD source_conversation_id BINARY(16) DEFAULT NULL');
        $this->addSql('ALTER TABLE tasks ADD CONSTRAINT FK_50586597AFB32D9D FOREIGN KEY (source_conversation_id) REFERENCES conversations (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_50586597AFB32D9D ON tasks (source_conversation_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE tasks DROP FOREIGN KEY FK_50586597AFB32D9D');
        $this->addSql('DROP INDEX IDX_50586597AFB32D9D ON tasks');
        $this->addSql('ALTER TABLE tasks DROP source_conversation_id');
    }
}
