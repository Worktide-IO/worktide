<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260715134309 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add user.discipline + task.required_discipline (AI scheduling / role offers).';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE tasks ADD required_discipline VARCHAR(20) DEFAULT NULL');
        $this->addSql('ALTER TABLE users ADD discipline VARCHAR(20) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE tasks DROP required_discipline');
        $this->addSql('ALTER TABLE users DROP discipline');
    }
}
