<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Версия 1.5.0: проверка знаний после ознакомления (document_question)
 * и результат проверки в записи ознакомления.
 */
final class Version20260917000006 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Таблица document_question и результат проверки знаний в document_acknowledgement';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE document_question (id INT AUTO_INCREMENT NOT NULL, position SMALLINT NOT NULL, text VARCHAR(500) NOT NULL, options JSON NOT NULL, correct_option SMALLINT NOT NULL, document_id INT NOT NULL, INDEX idx_question_document (document_id, position), INDEX IDX_3936B817C33F7837 (document_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE document_question ADD CONSTRAINT FK_3936B817C33F7837 FOREIGN KEY (document_id) REFERENCES document (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE document_acknowledgement ADD quiz_attempts SMALLINT DEFAULT 0 NOT NULL, ADD quiz_score SMALLINT DEFAULT NULL, ADD quiz_total SMALLINT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE document_question DROP FOREIGN KEY FK_3936B817C33F7837');
        $this->addSql('DROP TABLE document_question');
        $this->addSql('ALTER TABLE document_acknowledgement DROP quiz_attempts, DROP quiz_score, DROP quiz_total');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
