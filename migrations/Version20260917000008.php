<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Версия 1.5.0: ограничение доступа к отдельным документам (document.restricted,
 * document_allowed_user, document.allowed_departments) и журнал аудита (audit_event).
 */
final class Version20260917000008 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ограниченный доступ к документам и журнал аудита';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE audit_event (id INT AUTO_INCREMENT NOT NULL, occurred_at DATETIME NOT NULL, level VARCHAR(16) NOT NULL, action VARCHAR(255) NOT NULL, actor_name VARCHAR(64) DEFAULT NULL, ip VARCHAR(45) DEFAULT NULL, details JSON NOT NULL, INDEX idx_audit_time (occurred_at), INDEX idx_audit_actor (actor_name, occurred_at), INDEX idx_audit_level (level, occurred_at), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE document_allowed_user (document_id INT NOT NULL, user_id INT NOT NULL, INDEX IDX_8DE28EC5C33F7837 (document_id), INDEX IDX_8DE28EC5A76ED395 (user_id), PRIMARY KEY (document_id, user_id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE document_allowed_user ADD CONSTRAINT FK_8DE28EC5C33F7837 FOREIGN KEY (document_id) REFERENCES document (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE document_allowed_user ADD CONSTRAINT FK_8DE28EC5A76ED395 FOREIGN KEY (user_id) REFERENCES `user` (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE document ADD restricted TINYINT DEFAULT 0 NOT NULL, ADD allowed_departments LONGTEXT NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE document_allowed_user DROP FOREIGN KEY FK_8DE28EC5C33F7837');
        $this->addSql('ALTER TABLE document_allowed_user DROP FOREIGN KEY FK_8DE28EC5A76ED395');
        $this->addSql('DROP TABLE audit_event');
        $this->addSql('DROP TABLE document_allowed_user');
        $this->addSql('ALTER TABLE document DROP restricted, DROP allowed_departments');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
