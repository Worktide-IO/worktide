<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260612233505 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE comment_reactions (emoji VARCHAR(32) NOT NULL, id BINARY(16) NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, comment_id BINARY(16) NOT NULL, user_id BINARY(16) NOT NULL, INDEX IDX_D10D9EE5A76ED395 (user_id), INDEX comment_reaction_comment_idx (comment_id), UNIQUE INDEX comment_reaction_unique (comment_id, user_id, emoji), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE comment_reactions ADD CONSTRAINT FK_D10D9EE5F8697D13 FOREIGN KEY (comment_id) REFERENCES comments (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE comment_reactions ADD CONSTRAINT FK_D10D9EE5A76ED395 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE comments ADD is_hidden_for_connect_users TINYINT NOT NULL, ADD previews JSON NOT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE comment_reactions DROP FOREIGN KEY FK_D10D9EE5F8697D13');
        $this->addSql('ALTER TABLE comment_reactions DROP FOREIGN KEY FK_D10D9EE5A76ED395');
        $this->addSql('DROP TABLE comment_reactions');
        $this->addSql('ALTER TABLE comments DROP is_hidden_for_connect_users, DROP previews');
    }
}
