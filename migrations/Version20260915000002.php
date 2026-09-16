<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Версия 1.2.0: открытые документы (доступ без входа) и таблица настроек панели администратора.
 */
final class Version20260915000002 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Признак открытого документа document.is_public (по умолчанию все документы открыты) и таблица setting';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE document ADD is_public TINYINT DEFAULT 1 NOT NULL');
        $this->addSql('CREATE INDEX idx_document_public ON document (status, is_public)');
        $this->addSql('CREATE TABLE setting (name VARCHAR(64) NOT NULL, value JSON DEFAULT NULL, updated_at DATETIME NOT NULL, updated_by_id INT DEFAULT NULL, INDEX IDX_9F74B898896DBBDE (updated_by_id), PRIMARY KEY (name)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE setting ADD CONSTRAINT FK_9F74B898896DBBDE FOREIGN KEY (updated_by_id) REFERENCES `user` (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE setting DROP FOREIGN KEY FK_9F74B898896DBBDE');
        $this->addSql('DROP TABLE setting');
        $this->addSql('DROP INDEX idx_document_public ON document');
        $this->addSql('ALTER TABLE document DROP is_public');
    }
}
