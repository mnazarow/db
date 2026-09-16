<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Версия 1.3.0: ключи REST API для интеграций (RAG), отметки об удалённых документах,
 * источник описания документа (ручное / сгенерировано LLM).
 */
final class Version20260916000003 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Таблицы api_key и document_deletion; document.description_source / description_generated_at';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE api_key (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(128) NOT NULL, token_hash VARCHAR(64) NOT NULL, token_prefix VARCHAR(12) NOT NULL, include_internal TINYINT DEFAULT 0 NOT NULL, enabled TINYINT DEFAULT 1 NOT NULL, created_at DATETIME NOT NULL, last_used_at DATETIME DEFAULT NULL, last_used_ip VARCHAR(45) DEFAULT NULL, request_count INT DEFAULT 0 NOT NULL, created_by_id INT DEFAULT NULL, UNIQUE INDEX uniq_api_key_hash (token_hash), INDEX IDX_C912ED9DB03A8386 (created_by_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE api_key ADD CONSTRAINT FK_C912ED9DB03A8386 FOREIGN KEY (created_by_id) REFERENCES `user` (id) ON DELETE SET NULL');
        $this->addSql('CREATE TABLE document_deletion (document_id INT NOT NULL, title VARCHAR(255) NOT NULL, section_path VARCHAR(512) DEFAULT NULL, deleted_at DATETIME NOT NULL, deleted_by VARCHAR(128) DEFAULT NULL, INDEX idx_document_deletion_at (deleted_at), PRIMARY KEY (document_id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE document ADD description_source VARCHAR(8) DEFAULT NULL, ADD description_generated_at DATETIME DEFAULT NULL');
        $this->addSql("UPDATE document SET description_source = 'manual' WHERE description IS NOT NULL AND description <> ''");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE document DROP description_source, DROP description_generated_at');
        $this->addSql('DROP TABLE document_deletion');
        $this->addSql('ALTER TABLE api_key DROP FOREIGN KEY FK_C912ED9DB03A8386');
        $this->addSql('DROP TABLE api_key');
    }
}
