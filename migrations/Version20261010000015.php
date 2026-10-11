<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Версия 1.8.0: роли «Маркетолог» и «Помощник маркетолога», модерация материалов.
 *
 * Запрос без согласующего (approver_id = NULL) — материал в общей очереди модерации; кто из
 * маркетологов принял решение, записывается в decided_by_id. Роли хранятся в user.roles (JSON),
 * менять схему для них не нужно.
 */
final class Version20261010000015 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Модерация материалов: согласующий необязателен, кто принял решение';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE document_approval MODIFY approver_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE document_approval ADD decided_by_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE document_approval ADD CONSTRAINT FK_99216472E26B496B FOREIGN KEY (decided_by_id) REFERENCES `user` (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_99216472E26B496B ON document_approval (decided_by_id)');
        // Прежние решения принимал назначенный согласующий.
        $this->addSql("UPDATE document_approval SET decided_by_id = approver_id WHERE decision IN ('approved', 'rejected') AND decided_by_id IS NULL");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE document_approval DROP FOREIGN KEY FK_99216472E26B496B');
        $this->addSql('DROP INDEX IDX_99216472E26B496B ON document_approval');
        $this->addSql('ALTER TABLE document_approval DROP decided_by_id');
        // Запросы модерации без согласующего при откате удаляются: прежняя схема их не допускает.
        $this->addSql('DELETE FROM document_approval WHERE approver_id IS NULL');
        $this->addSql('ALTER TABLE document_approval MODIFY approver_id INT NOT NULL');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
