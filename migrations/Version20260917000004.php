<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Версия 1.4.0: полнотекстовый поиск по содержимому файлов (document_text с индексом FULLTEXT)
 * и ознакомление сотрудников с документами под подпись (document_acknowledgement).
 */
final class Version20260917000004 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Таблицы document_text (полнотекстовый поиск) и document_acknowledgement (ознакомление под подпись)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE document_text (document_id INT NOT NULL, version_number SMALLINT NOT NULL, status VARCHAR(12) NOT NULL, content LONGTEXT NOT NULL, chars INT NOT NULL, indexed_at DATETIME NOT NULL, FULLTEXT INDEX ft_document_text (content), PRIMARY KEY (document_id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE document_text ADD CONSTRAINT FK_9C3F2B54C33F7837 FOREIGN KEY (document_id) REFERENCES document (id) ON DELETE CASCADE');
        $this->addSql('CREATE TABLE document_acknowledgement (id INT AUTO_INCREMENT NOT NULL, document_id INT NOT NULL, user_id INT NOT NULL, assigned_by_id INT DEFAULT NULL, version_number SMALLINT NOT NULL, assigned_at DATETIME NOT NULL, due_at DATE DEFAULT NULL, confirmed_at DATETIME DEFAULT NULL, confirmed_ip VARCHAR(45) DEFAULT NULL, reminded_at DATETIME DEFAULT NULL, INDEX idx_ack_user (user_id, confirmed_at), INDEX idx_ack_document (document_id, confirmed_at), UNIQUE INDEX uniq_ack_document_user_version (document_id, user_id, version_number), INDEX IDX_1846B66BC33F7837 (document_id), INDEX IDX_1846B66BA76ED395 (user_id), INDEX IDX_1846B66B6E6F1246 (assigned_by_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE document_acknowledgement ADD CONSTRAINT FK_1846B66BC33F7837 FOREIGN KEY (document_id) REFERENCES document (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE document_acknowledgement ADD CONSTRAINT FK_1846B66BA76ED395 FOREIGN KEY (user_id) REFERENCES `user` (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE document_acknowledgement ADD CONSTRAINT FK_1846B66B6E6F1246 FOREIGN KEY (assigned_by_id) REFERENCES `user` (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE document_acknowledgement DROP FOREIGN KEY FK_1846B66BC33F7837');
        $this->addSql('ALTER TABLE document_acknowledgement DROP FOREIGN KEY FK_1846B66BA76ED395');
        $this->addSql('ALTER TABLE document_acknowledgement DROP FOREIGN KEY FK_1846B66B6E6F1246');
        $this->addSql('DROP TABLE document_acknowledgement');
        $this->addSql('ALTER TABLE document_text DROP FOREIGN KEY FK_9C3F2B54C33F7837');
        $this->addSql('DROP TABLE document_text');
    }
}
