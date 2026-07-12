<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Newsletter: estimated send frequency on the node, and consent-tracking columns
 * on subscriptions (when/how the opt-in was given, and a soft-revocation stamp so
 * withdrawals stay on record instead of being hard-deleted).
 *
 * consented_at / consent_source are NOT NULL, so existing subscriptions are
 * backfilled first: consent time = the row's created_at (best available proxy),
 * origin = 'import' (their true source predates tracking).
 */
final class Version20260712122342 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Newsletter estimatedFrequency + subscription consent tracking (consented_at/consent_source/revoked_at)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE newsletters ADD estimated_frequency VARCHAR(20) DEFAULT NULL');

        $this->addSql('ALTER TABLE newsletter_subscriptions ADD consented_at DATETIME DEFAULT NULL, ADD consent_source VARCHAR(20) DEFAULT NULL, ADD revoked_at DATETIME DEFAULT NULL');
        $this->addSql('UPDATE newsletter_subscriptions SET consented_at = COALESCE(created_at, NOW()) WHERE consented_at IS NULL');
        $this->addSql("UPDATE newsletter_subscriptions SET consent_source = 'import' WHERE consent_source IS NULL");
        $this->addSql('ALTER TABLE newsletter_subscriptions CHANGE consented_at consented_at DATETIME NOT NULL, CHANGE consent_source consent_source VARCHAR(20) NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE newsletter_subscriptions DROP consented_at, DROP consent_source, DROP revoked_at');
        $this->addSql('ALTER TABLE newsletters DROP estimated_frequency');
    }
}
