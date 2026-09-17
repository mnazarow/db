<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Версия 1.5.0: связи между документами (document_link) и шаблоны документов
 * с автонумерацией обозначений (document_template).
 */
final class Version20260917000010 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Связи между документами и шаблоны документов';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("CREATE TABLE document_template (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(128) NOT NULL, description LONGTEXT DEFAULT NULL, active TINYINT DEFAULT 1 NOT NULL, type VARCHAR(16) DEFAULT 'file' NOT NULL, title_pattern VARCHAR(200) DEFAULT NULL, code_pattern VARCHAR(64) DEFAULT NULL, body LONGTEXT DEFAULT NULL, tags JSON NOT NULL, validity_months INT DEFAULT NULL, public TINYINT DEFAULT 0 NOT NULL, counter INT DEFAULT 0 NOT NULL, counter_year INT DEFAULT 0 NOT NULL, usage_count INT DEFAULT NULL, created_at DATETIME NOT NULL, section_id INT DEFAULT NULL, created_by_id INT DEFAULT NULL, INDEX IDX_18A1EEDAD823E37A (section_id), INDEX IDX_18A1EEDAB03A8386 (created_by_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB");
        $this->addSql('CREATE TABLE document_link (id INT AUTO_INCREMENT NOT NULL, type VARCHAR(16) NOT NULL, note VARCHAR(255) DEFAULT NULL, created_at DATETIME NOT NULL, source_id INT NOT NULL, target_id INT NOT NULL, created_by_id INT DEFAULT NULL, INDEX idx_link_source (source_id), INDEX idx_link_target (target_id), UNIQUE INDEX uniq_link (source_id, target_id, type), INDEX IDX_91181562B03A8386 (created_by_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE document_template ADD CONSTRAINT FK_18A1EEDAD823E37A FOREIGN KEY (section_id) REFERENCES section (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE document_template ADD CONSTRAINT FK_18A1EEDAB03A8386 FOREIGN KEY (created_by_id) REFERENCES `user` (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE document_link ADD CONSTRAINT FK_91181562953C1C61 FOREIGN KEY (source_id) REFERENCES document (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE document_link ADD CONSTRAINT FK_91181562158E0B66 FOREIGN KEY (target_id) REFERENCES document (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE document_link ADD CONSTRAINT FK_91181562B03A8386 FOREIGN KEY (created_by_id) REFERENCES `user` (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE document_template DROP FOREIGN KEY FK_18A1EEDAD823E37A');
        $this->addSql('ALTER TABLE document_template DROP FOREIGN KEY FK_18A1EEDAB03A8386');
        $this->addSql('ALTER TABLE document_link DROP FOREIGN KEY FK_91181562953C1C61');
        $this->addSql('ALTER TABLE document_link DROP FOREIGN KEY FK_91181562158E0B66');
        $this->addSql('ALTER TABLE document_link DROP FOREIGN KEY FK_91181562B03A8386');
        $this->addSql('DROP TABLE document_template');
        $this->addSql('DROP TABLE document_link');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
