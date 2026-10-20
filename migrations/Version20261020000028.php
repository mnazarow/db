<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Версия 1.8.0: помощник — разговоры сотрудников с помощником по материалам портала (чат на портале и в Telegram).
 */
final class Version20261020000028 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Помощник: разговоры';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE chat_conversation (id INT AUTO_INCREMENT NOT NULL, channel VARCHAR(16) NOT NULL, title VARCHAR(255) NOT NULL, message_count INT NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, user_id INT NOT NULL, INDEX idx_chat_conversation_user (user_id, updated_at), INDEX IDX_74654F68A76ED395 (user_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE chat_message (id INT AUTO_INCREMENT NOT NULL, role VARCHAR(16) NOT NULL, content LONGTEXT NOT NULL, sources JSON NOT NULL, status VARCHAR(16) DEFAULT NULL, has_answer TINYINT DEFAULT 0 NOT NULL, created_at DATETIME NOT NULL, conversation_id INT NOT NULL, question_id INT DEFAULT NULL, INDEX idx_chat_message_conversation (conversation_id, id), INDEX IDX_FAB3FC169AC0396 (conversation_id), INDEX IDX_FAB3FC161E27F6BF (question_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE chat_conversation ADD CONSTRAINT FK_74654F68A76ED395 FOREIGN KEY (user_id) REFERENCES `user` (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE chat_message ADD CONSTRAINT FK_FAB3FC169AC0396 FOREIGN KEY (conversation_id) REFERENCES chat_conversation (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE chat_message ADD CONSTRAINT FK_FAB3FC161E27F6BF FOREIGN KEY (question_id) REFERENCES ai_question (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE chat_conversation DROP FOREIGN KEY FK_74654F68A76ED395');
        $this->addSql('ALTER TABLE chat_message DROP FOREIGN KEY FK_FAB3FC169AC0396');
        $this->addSql('ALTER TABLE chat_message DROP FOREIGN KEY FK_FAB3FC161E27F6BF');
        $this->addSql('DROP TABLE chat_conversation');
        $this->addSql('DROP TABLE chat_message');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
