<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Версия 1.5.0: согласование документов перед публикацией (document_approval).
 * Статус документа «на согласовании» (review) хранится в существующей колонке document.status.
 */
final class Version20260917000005 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Таблица document_approval: согласование редакции документа перед публикацией';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE document_approval (id INT AUTO_INCREMENT NOT NULL, version_number SMALLINT NOT NULL, requested_at DATETIME NOT NULL, decision VARCHAR(12) NOT NULL, decided_at DATETIME DEFAULT NULL, request_note LONGTEXT DEFAULT NULL, decision_note LONGTEXT DEFAULT NULL, document_id INT NOT NULL, approver_id INT NOT NULL, requested_by_id INT DEFAULT NULL, INDEX idx_approval_approver (approver_id, decision), INDEX idx_approval_document (document_id, version_number), INDEX IDX_99216472C33F7837 (document_id), INDEX IDX_99216472BB23766C (approver_id), INDEX IDX_992164724DA1E751 (requested_by_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE document_approval ADD CONSTRAINT FK_99216472C33F7837 FOREIGN KEY (document_id) REFERENCES document (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE document_approval ADD CONSTRAINT FK_99216472BB23766C FOREIGN KEY (approver_id) REFERENCES `user` (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE document_approval ADD CONSTRAINT FK_992164724DA1E751 FOREIGN KEY (requested_by_id) REFERENCES `user` (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE document_approval DROP FOREIGN KEY FK_99216472C33F7837');
        $this->addSql('ALTER TABLE document_approval DROP FOREIGN KEY FK_99216472BB23766C');
        $this->addSql('ALTER TABLE document_approval DROP FOREIGN KEY FK_992164724DA1E751');
        $this->addSql('DROP TABLE document_approval');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
