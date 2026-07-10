<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260710170835 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add notification delivery preferences + last digest timestamp to user_preferences';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE user_preferences ADD notification_preferences JSON DEFAULT NULL, ADD last_notification_digest_at DATETIME DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE user_preferences DROP notification_preferences, DROP last_notification_digest_at');
    }
}
