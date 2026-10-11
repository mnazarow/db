<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Версия 1.8.0: поиск по смыслу для ответов ИИ (векторы документов и их фрагментов) и журнал вопросов к ИИ
 * с оценками ответов — по нему администратор видит, на какие вопросы в документах нет ответа.
 * Колонка с вектором — embedding: слово VECTOR зарезервировано в MariaDB 11.7+ (Debian 13 — MariaDB 11.8).
 */
final class Version20261012000017 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Смысловой индекс документов и журнал вопросов к ИИ';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE document_embedding (document_id INT NOT NULL, version_number SMALLINT NOT NULL, model VARCHAR(190) NOT NULL, dims SMALLINT NOT NULL, chunks SMALLINT NOT NULL, status VARCHAR(12) NOT NULL, error VARCHAR(255) DEFAULT NULL, embedding BLOB DEFAULT NULL, content_hash VARCHAR(40) DEFAULT NULL, embedded_at DATETIME NOT NULL, INDEX idx_embedding_model (model), PRIMARY KEY (document_id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE document_chunk (id INT AUTO_INCREMENT NOT NULL, document_id INT NOT NULL, position SMALLINT NOT NULL, content LONGTEXT NOT NULL, embedding BLOB NOT NULL, INDEX idx_chunk_document (document_id, position), INDEX IDX_FCA7075CC33F7837 (document_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE ai_question (id INT AUTO_INCREMENT NOT NULL, user_id INT DEFAULT NULL, question VARCHAR(500) NOT NULL, topic VARCHAR(190) NOT NULL, status VARCHAR(12) NOT NULL, answer LONGTEXT DEFAULT NULL, sources JSON NOT NULL, semantic TINYINT DEFAULT 0 NOT NULL, rating SMALLINT DEFAULT NULL, comment VARCHAR(500) DEFAULT NULL, duration_ms INT DEFAULT 0 NOT NULL, created_at DATETIME NOT NULL, rated_at DATETIME DEFAULT NULL, INDEX idx_ai_question_created (created_at), INDEX idx_ai_question_status (status, created_at), INDEX idx_ai_question_topic (topic), INDEX IDX_A911CF2EA76ED395 (user_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE document_embedding ADD CONSTRAINT FK_5A637D0FC33F7837 FOREIGN KEY (document_id) REFERENCES document (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE document_chunk ADD CONSTRAINT FK_FCA7075CC33F7837 FOREIGN KEY (document_id) REFERENCES document (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE ai_question ADD CONSTRAINT FK_A911CF2EA76ED395 FOREIGN KEY (user_id) REFERENCES `user` (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE ai_question');
        $this->addSql('DROP TABLE document_chunk');
        $this->addSql('DROP TABLE document_embedding');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
