<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260702090037 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add channels.owner_user_id for personal mailboxes (visibility).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE channels ADD owner_user_id BINARY(16) DEFAULT NULL');
        $this->addSql('ALTER TABLE channels ADD CONSTRAINT FK_F314E2B62B18554A FOREIGN KEY (owner_user_id) REFERENCES users (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_F314E2B62B18554A ON channels (owner_user_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE channels DROP FOREIGN KEY FK_F314E2B62B18554A');
        $this->addSql('DROP INDEX IDX_F314E2B62B18554A ON channels');
        $this->addSql('ALTER TABLE channels DROP owner_user_id');
    }
}
