<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Версия 1.5.0: обсуждение документов (document_comment), подписка на изменения
 * (document_subscription) и каналы уведомлений сотрудника, включая Telegram.
 */
final class Version20260917000009 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Обсуждение, подписки и уведомления в Telegram';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE document_comment (id INT AUTO_INCREMENT NOT NULL, author_name VARCHAR(128) NOT NULL, body LONGTEXT NOT NULL, version_number INT DEFAULT NULL, created_at DATETIME NOT NULL, edited_at DATETIME DEFAULT NULL, deleted_at DATETIME DEFAULT NULL, deleted_by_name VARCHAR(128) DEFAULT NULL, document_id INT NOT NULL, parent_id INT DEFAULT NULL, author_id INT DEFAULT NULL, INDEX idx_comment_document (document_id, created_at), INDEX idx_comment_author (author_id), INDEX IDX_301BF4B0C33F7837 (document_id), INDEX IDX_301BF4B0727ACA70 (parent_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE document_subscription (id INT AUTO_INCREMENT NOT NULL, created_at DATETIME NOT NULL, user_id INT NOT NULL, document_id INT DEFAULT NULL, section_id INT DEFAULT NULL, INDEX idx_subscription_document (document_id), INDEX idx_subscription_section (section_id), UNIQUE INDEX uniq_subscription_document (user_id, document_id), UNIQUE INDEX uniq_subscription_section (user_id, section_id), INDEX IDX_E2345A2A76ED395 (user_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE document_comment ADD CONSTRAINT FK_301BF4B0C33F7837 FOREIGN KEY (document_id) REFERENCES document (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE document_comment ADD CONSTRAINT FK_301BF4B0727ACA70 FOREIGN KEY (parent_id) REFERENCES document_comment (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE document_comment ADD CONSTRAINT FK_301BF4B0F675F31B FOREIGN KEY (author_id) REFERENCES `user` (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE document_subscription ADD CONSTRAINT FK_E2345A2A76ED395 FOREIGN KEY (user_id) REFERENCES `user` (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE document_subscription ADD CONSTRAINT FK_E2345A2C33F7837 FOREIGN KEY (document_id) REFERENCES document (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE document_subscription ADD CONSTRAINT FK_E2345A2D823E37A FOREIGN KEY (section_id) REFERENCES section (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE `user` ADD notify_email TINYINT DEFAULT 1 NOT NULL, ADD notify_telegram TINYINT DEFAULT 1 NOT NULL, ADD telegram_chat_id VARCHAR(32) DEFAULT NULL, ADD telegram_name VARCHAR(64) DEFAULT NULL, ADD telegram_code VARCHAR(16) DEFAULT NULL, ADD telegram_code_at DATETIME DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE document_comment DROP FOREIGN KEY FK_301BF4B0C33F7837');
        $this->addSql('ALTER TABLE document_comment DROP FOREIGN KEY FK_301BF4B0727ACA70');
        $this->addSql('ALTER TABLE document_comment DROP FOREIGN KEY FK_301BF4B0F675F31B');
        $this->addSql('ALTER TABLE document_subscription DROP FOREIGN KEY FK_E2345A2A76ED395');
        $this->addSql('ALTER TABLE document_subscription DROP FOREIGN KEY FK_E2345A2C33F7837');
        $this->addSql('ALTER TABLE document_subscription DROP FOREIGN KEY FK_E2345A2D823E37A');
        $this->addSql('DROP TABLE document_comment');
        $this->addSql('DROP TABLE document_subscription');
        $this->addSql('ALTER TABLE `user` DROP notify_email, DROP notify_telegram, DROP telegram_chat_id, DROP telegram_name, DROP telegram_code, DROP telegram_code_at');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
