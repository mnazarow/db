<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Версия 1.8.0: библиотека фирменных слайдов — слайды, которые маркетологи сохранили как общие шаблоны
 * для галереи «Новый слайд» редактора презентаций.
 */
final class Version20261020000025 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Библиотека фирменных слайдов';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE slide_template (id INT AUTO_INCREMENT NOT NULL, title VARCHAR(200) NOT NULL, category VARCHAR(100) NOT NULL, description LONGTEXT DEFAULT NULL, content MEDIUMTEXT NOT NULL, position INT NOT NULL, use_count INT NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, created_by_id INT DEFAULT NULL, INDEX idx_slide_template_category (category, position), INDEX IDX_EE6DBEA6B03A8386 (created_by_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE slide_template ADD CONSTRAINT FK_EE6DBEA6B03A8386 FOREIGN KEY (created_by_id) REFERENCES `user` (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE slide_template DROP FOREIGN KEY FK_EE6DBEA6B03A8386');
        $this->addSql('DROP TABLE slide_template');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
