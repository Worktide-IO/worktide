<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Newsletter.is_mandatory: transactional/mandatory nodes whose recipients are all
 * active contacts of a granted customer, with no subscription row and no opt-out.
 */
final class Version20260712131620 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Newsletter is_mandatory flag (transactional/forced newsletters)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE newsletters ADD is_mandatory TINYINT(1) DEFAULT 0 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE newsletters DROP is_mandatory');
    }
}
