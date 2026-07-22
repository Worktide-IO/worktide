<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Inbound mute rules: replace the single-field match (match_type + value) with a
 * Thunderbird-style condition list — `combinator` (and/or) + `conditions` JSON
 * (each {field, operator, value}). The table carries no data yet, so a plain
 * drop+add of the columns is safe.
 */
final class Version20260720163000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'inbound_mute_rules: match_type/value → combinator + conditions (Thunderbird-style)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE inbound_mute_rules DROP match_type, DROP value, ADD combinator VARCHAR(8) DEFAULT 'and' NOT NULL, ADD conditions JSON NOT NULL");
    }

    public function down(Schema $schema): void
    {
        $this->addSql("ALTER TABLE inbound_mute_rules DROP combinator, DROP conditions, ADD match_type VARCHAR(20) NOT NULL, ADD value VARCHAR(250) NOT NULL");
    }
}
