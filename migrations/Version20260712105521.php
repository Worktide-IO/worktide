<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Add notifications.delivered_at — marks when the async (email/chat) delivery of
 * a batchable notification was handled by the debounce sweep. Pre-existing
 * ambient drift on unrelated tables was excluded (separate cleanup).
 */
final class Version20260712105521 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add notifications.delivered_at for debounced (batched) notification delivery.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE notifications ADD delivered_at DATETIME DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE notifications DROP delivered_at');
    }
}
