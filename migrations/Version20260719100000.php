<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Per-mailbox auto-reply (receipt acknowledgement) + HTML body on outbound messages.
 */
final class Version20260719100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add auto-reply fields to channels and body_html to outbound_messages';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE channels
            ADD auto_reply_enabled TINYINT(1) DEFAULT 0 NOT NULL,
            ADD auto_reply_subject VARCHAR(250) DEFAULT NULL,
            ADD auto_reply_body_html LONGTEXT DEFAULT NULL,
            ADD auto_reply_body_text LONGTEXT DEFAULT NULL,
            ADD auto_reply_throttle_hours SMALLINT DEFAULT 24 NOT NULL');
        $this->addSql('ALTER TABLE outbound_messages ADD body_html LONGTEXT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE channels
            DROP auto_reply_enabled,
            DROP auto_reply_subject,
            DROP auto_reply_body_html,
            DROP auto_reply_body_text,
            DROP auto_reply_throttle_hours');
        $this->addSql('ALTER TABLE outbound_messages DROP body_html');
    }
}
