<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260715205400 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add task_offer_dismissals (role-based ticket offer declines).';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE task_offer_dismissals (id BINARY(16) NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, user_id BINARY(16) NOT NULL, task_id BINARY(16) NOT NULL, INDEX IDX_AEF985C28DB60186 (task_id), INDEX task_offer_dismissal_user_idx (user_id), UNIQUE INDEX task_offer_dismissal_unique (user_id, task_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE task_offer_dismissals ADD CONSTRAINT FK_AEF985C2A76ED395 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE task_offer_dismissals ADD CONSTRAINT FK_AEF985C28DB60186 FOREIGN KEY (task_id) REFERENCES tasks (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE task_offer_dismissals DROP FOREIGN KEY FK_AEF985C2A76ED395');
        $this->addSql('ALTER TABLE task_offer_dismissals DROP FOREIGN KEY FK_AEF985C28DB60186');
        $this->addSql('DROP TABLE task_offer_dismissals');
    }
}
