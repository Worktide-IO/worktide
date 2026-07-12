<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Global forms (Piece B0): a PublicForm is no longer bound to a single project.
 *
 * - `public_forms.project_id` becomes nullable and its FK switches to
 *   ON DELETE SET NULL — the project is now only an optional task-landing
 *   target (a submission with no project just records the audit row).
 * - New `public_form_recipients` join table (form ↔ customer) drives portal
 *   visibility; an empty recipient set means staff-only.
 */
final class Version20260712120653 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Global forms: nullable public_forms.project_id + public_form_recipients join table.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE public_form_recipients (public_form_id BINARY(16) NOT NULL, customer_id BINARY(16) NOT NULL, INDEX IDX_3B18B551428C4CF (public_form_id), INDEX IDX_3B18B5519395C3F3 (customer_id), PRIMARY KEY (public_form_id, customer_id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE public_form_recipients ADD CONSTRAINT FK_3B18B551428C4CF FOREIGN KEY (public_form_id) REFERENCES public_forms (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE public_form_recipients ADD CONSTRAINT FK_3B18B5519395C3F3 FOREIGN KEY (customer_id) REFERENCES customers (id) ON DELETE CASCADE');

        $this->addSql('ALTER TABLE public_forms DROP FOREIGN KEY `FK_C90A4F7166D1F9C`');
        $this->addSql('ALTER TABLE public_forms CHANGE project_id project_id BINARY(16) DEFAULT NULL');
        $this->addSql('ALTER TABLE public_forms ADD CONSTRAINT FK_C90A4F7166D1F9C FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE public_form_recipients DROP FOREIGN KEY FK_3B18B551428C4CF');
        $this->addSql('ALTER TABLE public_form_recipients DROP FOREIGN KEY FK_3B18B5519395C3F3');
        $this->addSql('DROP TABLE public_form_recipients');

        $this->addSql('ALTER TABLE public_forms DROP FOREIGN KEY FK_C90A4F7166D1F9C');
        $this->addSql('ALTER TABLE public_forms CHANGE project_id project_id BINARY(16) NOT NULL');
        $this->addSql('ALTER TABLE public_forms ADD CONSTRAINT `FK_C90A4F7166D1F9C` FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE');
    }
}
