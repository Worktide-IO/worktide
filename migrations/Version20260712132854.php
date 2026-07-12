<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Double opt-in: newsletter_subscriptions.confirmed_at. A subscription only
 * receives mail when confirmed AND not revoked. Existing rows predate double
 * opt-in, so they are backfilled as confirmed (confirmed_at = consented_at) —
 * behaviour is unchanged until a workspace turns double opt-in on.
 */
final class Version20260712132854 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Newsletter double opt-in: newsletter_subscriptions.confirmed_at (+ backfill existing rows)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE newsletter_subscriptions ADD confirmed_at DATETIME DEFAULT NULL');
        // Existing opt-ins are treated as already confirmed (single opt-in era).
        $this->addSql('UPDATE newsletter_subscriptions SET confirmed_at = consented_at WHERE confirmed_at IS NULL AND revoked_at IS NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE newsletter_subscriptions DROP confirmed_at');
    }
}
