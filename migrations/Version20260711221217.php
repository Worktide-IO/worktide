<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260711221217 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Tagging: add M2M join tables for 15 more taggable record types (Contact, Lead, Product, CustomerSystem, CustomerAgreement, Document, Idea, BrainstormNote, File, SavedReply, Conversation, SocialPost, Newsletter, NewsletterIssue, ResearchMission).';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE brainstorm_note_tag (brainstorm_note_id BINARY(16) NOT NULL, tag_id BINARY(16) NOT NULL, INDEX IDX_39D939415AE66B31 (brainstorm_note_id), INDEX IDX_39D93941BAD26311 (tag_id), PRIMARY KEY (brainstorm_note_id, tag_id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE contact_tag (contact_id BINARY(16) NOT NULL, tag_id BINARY(16) NOT NULL, INDEX IDX_FEB3D6BBE7A1254A (contact_id), INDEX IDX_FEB3D6BBBAD26311 (tag_id), PRIMARY KEY (contact_id, tag_id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE conversation_tag (conversation_id BINARY(16) NOT NULL, tag_id BINARY(16) NOT NULL, INDEX IDX_713845629AC0396 (conversation_id), INDEX IDX_71384562BAD26311 (tag_id), PRIMARY KEY (conversation_id, tag_id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE customer_agreement_tag (customer_agreement_id BINARY(16) NOT NULL, tag_id BINARY(16) NOT NULL, INDEX IDX_A0A0DF582A51C832 (customer_agreement_id), INDEX IDX_A0A0DF58BAD26311 (tag_id), PRIMARY KEY (customer_agreement_id, tag_id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE customer_system_tag (customer_system_id BINARY(16) NOT NULL, tag_id BINARY(16) NOT NULL, INDEX IDX_33F819DA7B68D7BF (customer_system_id), INDEX IDX_33F819DABAD26311 (tag_id), PRIMARY KEY (customer_system_id, tag_id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE document_tag (document_id BINARY(16) NOT NULL, tag_id BINARY(16) NOT NULL, INDEX IDX_D0234567C33F7837 (document_id), INDEX IDX_D0234567BAD26311 (tag_id), PRIMARY KEY (document_id, tag_id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE file_tag (file_id BINARY(16) NOT NULL, tag_id BINARY(16) NOT NULL, INDEX IDX_2CCA391A93CB796C (file_id), INDEX IDX_2CCA391ABAD26311 (tag_id), PRIMARY KEY (file_id, tag_id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE idea_tag (idea_id BINARY(16) NOT NULL, tag_id BINARY(16) NOT NULL, INDEX IDX_46B2BD7E5B6FEF7D (idea_id), INDEX IDX_46B2BD7EBAD26311 (tag_id), PRIMARY KEY (idea_id, tag_id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE lead_tag (lead_id BINARY(16) NOT NULL, tag_id BINARY(16) NOT NULL, INDEX IDX_FB5475C855458D (lead_id), INDEX IDX_FB5475C8BAD26311 (tag_id), PRIMARY KEY (lead_id, tag_id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE newsletter_issue_tag (newsletter_issue_id BINARY(16) NOT NULL, tag_id BINARY(16) NOT NULL, INDEX IDX_21B7E03E837863E6 (newsletter_issue_id), INDEX IDX_21B7E03EBAD26311 (tag_id), PRIMARY KEY (newsletter_issue_id, tag_id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE newsletter_tag (newsletter_id BINARY(16) NOT NULL, tag_id BINARY(16) NOT NULL, INDEX IDX_EBAC81CE22DB1917 (newsletter_id), INDEX IDX_EBAC81CEBAD26311 (tag_id), PRIMARY KEY (newsletter_id, tag_id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE product_tag (product_id BINARY(16) NOT NULL, tag_id BINARY(16) NOT NULL, INDEX IDX_E3A6E39C4584665A (product_id), INDEX IDX_E3A6E39CBAD26311 (tag_id), PRIMARY KEY (product_id, tag_id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE research_mission_tag (research_mission_id BINARY(16) NOT NULL, tag_id BINARY(16) NOT NULL, INDEX IDX_DB41D3967A76A8F8 (research_mission_id), INDEX IDX_DB41D396BAD26311 (tag_id), PRIMARY KEY (research_mission_id, tag_id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE saved_reply_tag (saved_reply_id BINARY(16) NOT NULL, tag_id BINARY(16) NOT NULL, INDEX IDX_9DB6F40EE4EF4957 (saved_reply_id), INDEX IDX_9DB6F40EBAD26311 (tag_id), PRIMARY KEY (saved_reply_id, tag_id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE social_post_tag (social_post_id BINARY(16) NOT NULL, tag_id BINARY(16) NOT NULL, INDEX IDX_D4FFAEDCC4F2D6B1 (social_post_id), INDEX IDX_D4FFAEDCBAD26311 (tag_id), PRIMARY KEY (social_post_id, tag_id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE brainstorm_note_tag ADD CONSTRAINT FK_39D939415AE66B31 FOREIGN KEY (brainstorm_note_id) REFERENCES brainstorm_notes (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE brainstorm_note_tag ADD CONSTRAINT FK_39D93941BAD26311 FOREIGN KEY (tag_id) REFERENCES tags (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE contact_tag ADD CONSTRAINT FK_FEB3D6BBE7A1254A FOREIGN KEY (contact_id) REFERENCES contacts (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE contact_tag ADD CONSTRAINT FK_FEB3D6BBBAD26311 FOREIGN KEY (tag_id) REFERENCES tags (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE conversation_tag ADD CONSTRAINT FK_713845629AC0396 FOREIGN KEY (conversation_id) REFERENCES conversations (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE conversation_tag ADD CONSTRAINT FK_71384562BAD26311 FOREIGN KEY (tag_id) REFERENCES tags (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE customer_agreement_tag ADD CONSTRAINT FK_A0A0DF582A51C832 FOREIGN KEY (customer_agreement_id) REFERENCES customer_agreements (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE customer_agreement_tag ADD CONSTRAINT FK_A0A0DF58BAD26311 FOREIGN KEY (tag_id) REFERENCES tags (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE customer_system_tag ADD CONSTRAINT FK_33F819DA7B68D7BF FOREIGN KEY (customer_system_id) REFERENCES customer_systems (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE customer_system_tag ADD CONSTRAINT FK_33F819DABAD26311 FOREIGN KEY (tag_id) REFERENCES tags (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE document_tag ADD CONSTRAINT FK_D0234567C33F7837 FOREIGN KEY (document_id) REFERENCES documents (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE document_tag ADD CONSTRAINT FK_D0234567BAD26311 FOREIGN KEY (tag_id) REFERENCES tags (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE file_tag ADD CONSTRAINT FK_2CCA391A93CB796C FOREIGN KEY (file_id) REFERENCES files (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE file_tag ADD CONSTRAINT FK_2CCA391ABAD26311 FOREIGN KEY (tag_id) REFERENCES tags (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE idea_tag ADD CONSTRAINT FK_46B2BD7E5B6FEF7D FOREIGN KEY (idea_id) REFERENCES ideas (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE idea_tag ADD CONSTRAINT FK_46B2BD7EBAD26311 FOREIGN KEY (tag_id) REFERENCES tags (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE lead_tag ADD CONSTRAINT FK_FB5475C855458D FOREIGN KEY (lead_id) REFERENCES leads (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE lead_tag ADD CONSTRAINT FK_FB5475C8BAD26311 FOREIGN KEY (tag_id) REFERENCES tags (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE newsletter_issue_tag ADD CONSTRAINT FK_21B7E03E837863E6 FOREIGN KEY (newsletter_issue_id) REFERENCES newsletter_issues (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE newsletter_issue_tag ADD CONSTRAINT FK_21B7E03EBAD26311 FOREIGN KEY (tag_id) REFERENCES tags (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE newsletter_tag ADD CONSTRAINT FK_EBAC81CE22DB1917 FOREIGN KEY (newsletter_id) REFERENCES newsletters (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE newsletter_tag ADD CONSTRAINT FK_EBAC81CEBAD26311 FOREIGN KEY (tag_id) REFERENCES tags (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE product_tag ADD CONSTRAINT FK_E3A6E39C4584665A FOREIGN KEY (product_id) REFERENCES products (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE product_tag ADD CONSTRAINT FK_E3A6E39CBAD26311 FOREIGN KEY (tag_id) REFERENCES tags (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE research_mission_tag ADD CONSTRAINT FK_DB41D3967A76A8F8 FOREIGN KEY (research_mission_id) REFERENCES research_missions (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE research_mission_tag ADD CONSTRAINT FK_DB41D396BAD26311 FOREIGN KEY (tag_id) REFERENCES tags (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE saved_reply_tag ADD CONSTRAINT FK_9DB6F40EE4EF4957 FOREIGN KEY (saved_reply_id) REFERENCES saved_replies (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE saved_reply_tag ADD CONSTRAINT FK_9DB6F40EBAD26311 FOREIGN KEY (tag_id) REFERENCES tags (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE social_post_tag ADD CONSTRAINT FK_D4FFAEDCC4F2D6B1 FOREIGN KEY (social_post_id) REFERENCES social_posts (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE social_post_tag ADD CONSTRAINT FK_D4FFAEDCBAD26311 FOREIGN KEY (tag_id) REFERENCES tags (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE brainstorm_note_tag DROP FOREIGN KEY FK_39D939415AE66B31');
        $this->addSql('ALTER TABLE brainstorm_note_tag DROP FOREIGN KEY FK_39D93941BAD26311');
        $this->addSql('ALTER TABLE contact_tag DROP FOREIGN KEY FK_FEB3D6BBE7A1254A');
        $this->addSql('ALTER TABLE contact_tag DROP FOREIGN KEY FK_FEB3D6BBBAD26311');
        $this->addSql('ALTER TABLE conversation_tag DROP FOREIGN KEY FK_713845629AC0396');
        $this->addSql('ALTER TABLE conversation_tag DROP FOREIGN KEY FK_71384562BAD26311');
        $this->addSql('ALTER TABLE customer_agreement_tag DROP FOREIGN KEY FK_A0A0DF582A51C832');
        $this->addSql('ALTER TABLE customer_agreement_tag DROP FOREIGN KEY FK_A0A0DF58BAD26311');
        $this->addSql('ALTER TABLE customer_system_tag DROP FOREIGN KEY FK_33F819DA7B68D7BF');
        $this->addSql('ALTER TABLE customer_system_tag DROP FOREIGN KEY FK_33F819DABAD26311');
        $this->addSql('ALTER TABLE document_tag DROP FOREIGN KEY FK_D0234567C33F7837');
        $this->addSql('ALTER TABLE document_tag DROP FOREIGN KEY FK_D0234567BAD26311');
        $this->addSql('ALTER TABLE file_tag DROP FOREIGN KEY FK_2CCA391A93CB796C');
        $this->addSql('ALTER TABLE file_tag DROP FOREIGN KEY FK_2CCA391ABAD26311');
        $this->addSql('ALTER TABLE idea_tag DROP FOREIGN KEY FK_46B2BD7E5B6FEF7D');
        $this->addSql('ALTER TABLE idea_tag DROP FOREIGN KEY FK_46B2BD7EBAD26311');
        $this->addSql('ALTER TABLE lead_tag DROP FOREIGN KEY FK_FB5475C855458D');
        $this->addSql('ALTER TABLE lead_tag DROP FOREIGN KEY FK_FB5475C8BAD26311');
        $this->addSql('ALTER TABLE newsletter_issue_tag DROP FOREIGN KEY FK_21B7E03E837863E6');
        $this->addSql('ALTER TABLE newsletter_issue_tag DROP FOREIGN KEY FK_21B7E03EBAD26311');
        $this->addSql('ALTER TABLE newsletter_tag DROP FOREIGN KEY FK_EBAC81CE22DB1917');
        $this->addSql('ALTER TABLE newsletter_tag DROP FOREIGN KEY FK_EBAC81CEBAD26311');
        $this->addSql('ALTER TABLE product_tag DROP FOREIGN KEY FK_E3A6E39C4584665A');
        $this->addSql('ALTER TABLE product_tag DROP FOREIGN KEY FK_E3A6E39CBAD26311');
        $this->addSql('ALTER TABLE research_mission_tag DROP FOREIGN KEY FK_DB41D3967A76A8F8');
        $this->addSql('ALTER TABLE research_mission_tag DROP FOREIGN KEY FK_DB41D396BAD26311');
        $this->addSql('ALTER TABLE saved_reply_tag DROP FOREIGN KEY FK_9DB6F40EE4EF4957');
        $this->addSql('ALTER TABLE saved_reply_tag DROP FOREIGN KEY FK_9DB6F40EBAD26311');
        $this->addSql('ALTER TABLE social_post_tag DROP FOREIGN KEY FK_D4FFAEDCC4F2D6B1');
        $this->addSql('ALTER TABLE social_post_tag DROP FOREIGN KEY FK_D4FFAEDCBAD26311');
        $this->addSql('DROP TABLE brainstorm_note_tag');
        $this->addSql('DROP TABLE contact_tag');
        $this->addSql('DROP TABLE conversation_tag');
        $this->addSql('DROP TABLE customer_agreement_tag');
        $this->addSql('DROP TABLE customer_system_tag');
        $this->addSql('DROP TABLE document_tag');
        $this->addSql('DROP TABLE file_tag');
        $this->addSql('DROP TABLE idea_tag');
        $this->addSql('DROP TABLE lead_tag');
        $this->addSql('DROP TABLE newsletter_issue_tag');
        $this->addSql('DROP TABLE newsletter_tag');
        $this->addSql('DROP TABLE product_tag');
        $this->addSql('DROP TABLE research_mission_tag');
        $this->addSql('DROP TABLE saved_reply_tag');
        $this->addSql('DROP TABLE social_post_tag');
    }
}
