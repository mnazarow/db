<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Версия 1.8.0: проверенные ответы ИИ — ответы администраторов на частые вопросы, которые сотрудник получает
 * сразу, без обращения к языковой модели («Вопросы к ИИ → Проверенные ответы»).
 */
final class Version20261013000019 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Проверенные ответы ИИ';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE ai_verified_answer (id INT AUTO_INCREMENT NOT NULL, question VARCHAR(500) NOT NULL, alternatives LONGTEXT DEFAULT NULL, answer LONGTEXT NOT NULL, document_ids JSON NOT NULL, active TINYINT DEFAULT 1 NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, uses INT DEFAULT 0 NOT NULL, last_used_at DATETIME DEFAULT NULL, updated_by_id INT DEFAULT NULL, INDEX idx_ai_verified_active (active), INDEX IDX_11ADD68E896DBBDE (updated_by_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE ai_verified_answer ADD CONSTRAINT FK_11ADD68E896DBBDE FOREIGN KEY (updated_by_id) REFERENCES `user` (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE ai_verified_answer');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
