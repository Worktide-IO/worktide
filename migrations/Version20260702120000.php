<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Widen ai_recommendations.kind from varchar(16) to varchar(32): the value
 * 'ticket_from_conversation' (24 chars) overflowed the original column, which
 * only ever held 'triage'. Without this the ticket-suggestion feature fails at
 * persist time.
 */
final class Version20260702120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Widen ai_recommendations.kind to varchar(32) for ticket_from_conversation.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE ai_recommendations CHANGE kind kind VARCHAR(32) NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE ai_recommendations CHANGE kind kind VARCHAR(16) NOT NULL');
    }
}
