<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260704124854 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Portal Social-Freigabe: social_posts.project_id + change_request_note.';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE social_posts ADD change_request_note LONGTEXT DEFAULT NULL, ADD project_id BINARY(16) DEFAULT NULL');
        $this->addSql('ALTER TABLE social_posts ADD CONSTRAINT FK_C2CD0E68166D1F9C FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_C2CD0E68166D1F9C ON social_posts (project_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE social_posts DROP FOREIGN KEY FK_C2CD0E68166D1F9C');
        $this->addSql('DROP INDEX IDX_C2CD0E68166D1F9C ON social_posts');
        $this->addSql('ALTER TABLE social_posts DROP change_request_note, DROP project_id');
    }
}
