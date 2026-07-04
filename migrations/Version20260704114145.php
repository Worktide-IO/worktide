<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260704114145 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Portal "Ziele & Ideen": customer_goals, ideas, idea_votes.';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE customer_goals (title VARCHAR(160) NOT NULL, description LONGTEXT DEFAULT NULL, unit VARCHAR(24) DEFAULT NULL, target_value DOUBLE PRECISION DEFAULT NULL, current_value DOUBLE PRECISION DEFAULT NULL, status VARCHAR(16) DEFAULT \'on_track\' NOT NULL, target_date DATE DEFAULT NULL, position INT NOT NULL, id BINARY(16) NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, deleted_at DATETIME DEFAULT NULL, version INT DEFAULT 1 NOT NULL, customer_id BINARY(16) NOT NULL, workspace_id BINARY(16) NOT NULL, created_by_user_id BINARY(16) DEFAULT NULL, updated_by_user_id BINARY(16) DEFAULT NULL, INDEX IDX_9116B147D182D95 (created_by_user_id), INDEX IDX_9116B142793CC5E (updated_by_user_id), INDEX customer_goal_customer_idx (customer_id), INDEX customer_goal_workspace_idx (workspace_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE idea_votes (id BINARY(16) NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, idea_id BINARY(16) NOT NULL, voter_id BINARY(16) NOT NULL, workspace_id BINARY(16) NOT NULL, INDEX IDX_104C491A82D40A1F (workspace_id), INDEX idea_vote_idea_idx (idea_id), INDEX idea_vote_voter_idx (voter_id), UNIQUE INDEX idea_vote_idea_voter_uniq (idea_id, voter_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE ideas (title VARCHAR(200) NOT NULL, description LONGTEXT DEFAULT NULL, status VARCHAR(16) DEFAULT \'proposed\' NOT NULL, origin VARCHAR(16) DEFAULT \'customer\' NOT NULL, vote_count INT DEFAULT 0 NOT NULL, position INT NOT NULL, id BINARY(16) NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, deleted_at DATETIME DEFAULT NULL, version INT DEFAULT 1 NOT NULL, customer_id BINARY(16) NOT NULL, submitted_by_contact_id BINARY(16) DEFAULT NULL, converted_task_id BINARY(16) DEFAULT NULL, workspace_id BINARY(16) NOT NULL, created_by_user_id BINARY(16) DEFAULT NULL, updated_by_user_id BINARY(16) DEFAULT NULL, INDEX IDX_1DB2F1DE1E580F3B (submitted_by_contact_id), INDEX IDX_1DB2F1DEDC10C5B6 (converted_task_id), INDEX IDX_1DB2F1DE7D182D95 (created_by_user_id), INDEX IDX_1DB2F1DE2793CC5E (updated_by_user_id), INDEX idea_customer_idx (customer_id), INDEX idea_workspace_idx (workspace_id), INDEX idea_status_idx (status), INDEX idea_vote_count_idx (vote_count), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE customer_goals ADD CONSTRAINT FK_9116B149395C3F3 FOREIGN KEY (customer_id) REFERENCES customers (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE customer_goals ADD CONSTRAINT FK_9116B1482D40A1F FOREIGN KEY (workspace_id) REFERENCES workspaces (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE customer_goals ADD CONSTRAINT FK_9116B147D182D95 FOREIGN KEY (created_by_user_id) REFERENCES users (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE customer_goals ADD CONSTRAINT FK_9116B142793CC5E FOREIGN KEY (updated_by_user_id) REFERENCES users (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE idea_votes ADD CONSTRAINT FK_104C491A5B6FEF7D FOREIGN KEY (idea_id) REFERENCES ideas (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE idea_votes ADD CONSTRAINT FK_104C491AEBB4B8AD FOREIGN KEY (voter_id) REFERENCES users (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE idea_votes ADD CONSTRAINT FK_104C491A82D40A1F FOREIGN KEY (workspace_id) REFERENCES workspaces (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE ideas ADD CONSTRAINT FK_1DB2F1DE9395C3F3 FOREIGN KEY (customer_id) REFERENCES customers (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE ideas ADD CONSTRAINT FK_1DB2F1DE1E580F3B FOREIGN KEY (submitted_by_contact_id) REFERENCES contacts (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE ideas ADD CONSTRAINT FK_1DB2F1DEDC10C5B6 FOREIGN KEY (converted_task_id) REFERENCES tasks (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE ideas ADD CONSTRAINT FK_1DB2F1DE82D40A1F FOREIGN KEY (workspace_id) REFERENCES workspaces (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE ideas ADD CONSTRAINT FK_1DB2F1DE7D182D95 FOREIGN KEY (created_by_user_id) REFERENCES users (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE ideas ADD CONSTRAINT FK_1DB2F1DE2793CC5E FOREIGN KEY (updated_by_user_id) REFERENCES users (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE customer_goals DROP FOREIGN KEY FK_9116B149395C3F3');
        $this->addSql('ALTER TABLE customer_goals DROP FOREIGN KEY FK_9116B1482D40A1F');
        $this->addSql('ALTER TABLE customer_goals DROP FOREIGN KEY FK_9116B147D182D95');
        $this->addSql('ALTER TABLE customer_goals DROP FOREIGN KEY FK_9116B142793CC5E');
        $this->addSql('ALTER TABLE idea_votes DROP FOREIGN KEY FK_104C491A5B6FEF7D');
        $this->addSql('ALTER TABLE idea_votes DROP FOREIGN KEY FK_104C491AEBB4B8AD');
        $this->addSql('ALTER TABLE idea_votes DROP FOREIGN KEY FK_104C491A82D40A1F');
        $this->addSql('ALTER TABLE ideas DROP FOREIGN KEY FK_1DB2F1DE9395C3F3');
        $this->addSql('ALTER TABLE ideas DROP FOREIGN KEY FK_1DB2F1DE1E580F3B');
        $this->addSql('ALTER TABLE ideas DROP FOREIGN KEY FK_1DB2F1DEDC10C5B6');
        $this->addSql('ALTER TABLE ideas DROP FOREIGN KEY FK_1DB2F1DE82D40A1F');
        $this->addSql('ALTER TABLE ideas DROP FOREIGN KEY FK_1DB2F1DE7D182D95');
        $this->addSql('ALTER TABLE ideas DROP FOREIGN KEY FK_1DB2F1DE2793CC5E');
        $this->addSql('DROP TABLE customer_goals');
        $this->addSql('DROP TABLE idea_votes');
        $this->addSql('DROP TABLE ideas');
    }
}
