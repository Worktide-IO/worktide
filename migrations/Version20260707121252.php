<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260707121252 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add portal_invited_at to contacts (tracks when the portal invitation email was sent)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE contacts ADD portal_invited_at DATETIME DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE contacts DROP portal_invited_at');
    }
}
