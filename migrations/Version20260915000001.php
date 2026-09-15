<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Первоначальная схема портала документации:
 * пользователи, дерево разделов, модераторы разделов, документы, версии, события.
 */
final class Version20260915000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Первоначальная схема: user, section, section_moderator, document, document_version, document_event';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE document (id INT AUTO_INCREMENT NOT NULL, title VARCHAR(255) NOT NULL, code VARCHAR(64) DEFAULT NULL, description LONGTEXT DEFAULT NULL, type VARCHAR(8) NOT NULL, status VARCHAR(12) NOT NULL, tags JSON NOT NULL, valid_until DATE DEFAULT NULL, expiry_notice_stage SMALLINT DEFAULT 0 NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, published_at DATETIME DEFAULT NULL, archived_at DATETIME DEFAULT NULL, view_count INT DEFAULT 0 NOT NULL, download_count INT DEFAULT 0 NOT NULL, last_viewed_at DATETIME DEFAULT NULL, section_id INT NOT NULL, owner_id INT DEFAULT NULL, current_version_id INT DEFAULT NULL, UNIQUE INDEX UNIQ_D8698A769407EE77 (current_version_id), INDEX idx_document_section_status (section_id, status), INDEX idx_document_status_valid (status, valid_until), INDEX idx_document_updated (updated_at), INDEX idx_document_code (code), INDEX IDX_D8698A76D823E37A (section_id), INDEX IDX_D8698A767E3C61F9 (owner_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE document_event (id INT AUTO_INCREMENT NOT NULL, actor_name VARCHAR(128) DEFAULT NULL, type VARCHAR(24) NOT NULL, created_at DATETIME NOT NULL, ip VARCHAR(45) DEFAULT NULL, details JSON DEFAULT NULL, document_id INT NOT NULL, version_id INT DEFAULT NULL, user_id INT DEFAULT NULL, INDEX idx_event_document_type (document_id, type), INDEX idx_event_created (created_at), INDEX idx_event_type_created (type, created_at), INDEX idx_event_user (user_id), INDEX IDX_520F7CD5C33F7837 (document_id), INDEX IDX_520F7CD54BBC2705 (version_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE document_version (id INT AUTO_INCREMENT NOT NULL, number INT NOT NULL, kind VARCHAR(8) NOT NULL, original_name VARCHAR(255) DEFAULT NULL, stored_path VARCHAR(255) DEFAULT NULL, mime_type VARCHAR(128) DEFAULT NULL, size BIGINT DEFAULT NULL, checksum VARCHAR(64) DEFAULT NULL, content MEDIUMTEXT DEFAULT NULL, change_note LONGTEXT DEFAULT NULL, created_at DATETIME NOT NULL, download_count INT DEFAULT 0 NOT NULL, document_id INT NOT NULL, created_by_id INT DEFAULT NULL, INDEX idx_document_version_created (created_at), UNIQUE INDEX uniq_document_version_number (document_id, number), INDEX IDX_1B73751FC33F7837 (document_id), INDEX IDX_1B73751FB03A8386 (created_by_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE section (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(128) NOT NULL, slug VARCHAR(160) NOT NULL, description LONGTEXT DEFAULT NULL, position INT DEFAULT 0 NOT NULL, path VARCHAR(255) DEFAULT \'/\' NOT NULL, depth INT DEFAULT 0 NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, parent_id INT DEFAULT NULL, created_by_id INT DEFAULT NULL, INDEX idx_section_parent (parent_id, position), INDEX idx_section_path (path), UNIQUE INDEX uniq_section_slug (slug), INDEX IDX_2D737AEF727ACA70 (parent_id), INDEX IDX_2D737AEFB03A8386 (created_by_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE section_moderator (id INT AUTO_INCREMENT NOT NULL, assigned_at DATETIME NOT NULL, section_id INT NOT NULL, user_id INT NOT NULL, assigned_by_id INT DEFAULT NULL, INDEX idx_section_moderator_user (user_id), UNIQUE INDEX uniq_section_moderator (section_id, user_id), INDEX IDX_87DC2EDD823E37A (section_id), INDEX IDX_87DC2ED6E6F1246 (assigned_by_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE `user` (id INT AUTO_INCREMENT NOT NULL, username VARCHAR(64) NOT NULL, display_name VARCHAR(128) NOT NULL, email VARCHAR(180) DEFAULT NULL, department VARCHAR(128) DEFAULT NULL, roles JSON NOT NULL, password VARCHAR(255) NOT NULL, auth_source VARCHAR(16) DEFAULT \'local\' NOT NULL, ldap_dn VARCHAR(512) DEFAULT NULL, active TINYINT DEFAULT 1 NOT NULL, must_change_password TINYINT DEFAULT 0 NOT NULL, created_at DATETIME NOT NULL, last_login_at DATETIME DEFAULT NULL, INDEX idx_user_source (auth_source), UNIQUE INDEX uniq_user_username (username), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE document ADD CONSTRAINT FK_D8698A76D823E37A FOREIGN KEY (section_id) REFERENCES section (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE document ADD CONSTRAINT FK_D8698A767E3C61F9 FOREIGN KEY (owner_id) REFERENCES `user` (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE document ADD CONSTRAINT FK_D8698A769407EE77 FOREIGN KEY (current_version_id) REFERENCES document_version (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE document_event ADD CONSTRAINT FK_520F7CD5C33F7837 FOREIGN KEY (document_id) REFERENCES document (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE document_event ADD CONSTRAINT FK_520F7CD54BBC2705 FOREIGN KEY (version_id) REFERENCES document_version (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE document_event ADD CONSTRAINT FK_520F7CD5A76ED395 FOREIGN KEY (user_id) REFERENCES `user` (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE document_version ADD CONSTRAINT FK_1B73751FC33F7837 FOREIGN KEY (document_id) REFERENCES document (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE document_version ADD CONSTRAINT FK_1B73751FB03A8386 FOREIGN KEY (created_by_id) REFERENCES `user` (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE section ADD CONSTRAINT FK_2D737AEF727ACA70 FOREIGN KEY (parent_id) REFERENCES section (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE section ADD CONSTRAINT FK_2D737AEFB03A8386 FOREIGN KEY (created_by_id) REFERENCES `user` (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE section_moderator ADD CONSTRAINT FK_87DC2EDD823E37A FOREIGN KEY (section_id) REFERENCES section (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE section_moderator ADD CONSTRAINT FK_87DC2EDA76ED395 FOREIGN KEY (user_id) REFERENCES `user` (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE section_moderator ADD CONSTRAINT FK_87DC2ED6E6F1246 FOREIGN KEY (assigned_by_id) REFERENCES `user` (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE document DROP FOREIGN KEY FK_D8698A76D823E37A');
        $this->addSql('ALTER TABLE document DROP FOREIGN KEY FK_D8698A767E3C61F9');
        $this->addSql('ALTER TABLE document DROP FOREIGN KEY FK_D8698A769407EE77');
        $this->addSql('ALTER TABLE document_event DROP FOREIGN KEY FK_520F7CD5C33F7837');
        $this->addSql('ALTER TABLE document_event DROP FOREIGN KEY FK_520F7CD54BBC2705');
        $this->addSql('ALTER TABLE document_event DROP FOREIGN KEY FK_520F7CD5A76ED395');
        $this->addSql('ALTER TABLE document_version DROP FOREIGN KEY FK_1B73751FC33F7837');
        $this->addSql('ALTER TABLE document_version DROP FOREIGN KEY FK_1B73751FB03A8386');
        $this->addSql('ALTER TABLE section DROP FOREIGN KEY FK_2D737AEF727ACA70');
        $this->addSql('ALTER TABLE section DROP FOREIGN KEY FK_2D737AEFB03A8386');
        $this->addSql('ALTER TABLE section_moderator DROP FOREIGN KEY FK_87DC2EDD823E37A');
        $this->addSql('ALTER TABLE section_moderator DROP FOREIGN KEY FK_87DC2EDA76ED395');
        $this->addSql('ALTER TABLE section_moderator DROP FOREIGN KEY FK_87DC2ED6E6F1246');
        $this->addSql('DROP TABLE document');
        $this->addSql('DROP TABLE document_event');
        $this->addSql('DROP TABLE document_version');
        $this->addSql('DROP TABLE section');
        $this->addSql('DROP TABLE section_moderator');
        $this->addSql('DROP TABLE `user`');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
