<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * i18n foundation: per-entity `translations` JSON column on the translatable
 * lookup/config/template entities, plus `users.preferred_language`.
 *
 * The base text columns (name/label/title/…) are untouched — they stay the
 * source-language value; `translations` only carries alternate-locale strings.
 */
final class Version20260710104813 extends AbstractMigration
{
    /**
     * Tables that gain a nullable `translations` JSON column.
     *
     * @var list<string>
     */
    private const TRANSLATABLE_TABLES = [
        'task_statuses',
        'project_statuses',
        'trackers',
        'types_of_work',
        'project_types',
        'industries',
        'agreement_types',
        'tags',
        'custom_field_definitions',
        'custom_field_options',
        'saved_replies',
        'project_templates',
        'task_templates',
        'public_forms',
        'products',
    ];

    public function getDescription(): string
    {
        return 'Add translations JSON columns to translatable entities + users.preferred_language.';
    }

    public function up(Schema $schema): void
    {
        foreach (self::TRANSLATABLE_TABLES as $table) {
            $this->addSql(sprintf('ALTER TABLE %s ADD translations JSON DEFAULT NULL', $table));
        }
        $this->addSql('ALTER TABLE users ADD preferred_language VARCHAR(8) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        foreach (self::TRANSLATABLE_TABLES as $table) {
            $this->addSql(sprintf('ALTER TABLE %s DROP translations', $table));
        }
        $this->addSql('ALTER TABLE users DROP preferred_language');
    }
}
