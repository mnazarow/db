<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Версия 1.8.0: импорт из Confluence — что из Confluence в какие документы и разделы импортировано (для повторного
 * импорта с обновлением и для ссылок между страницами).
 */
final class Version20261020000026 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Импорт из Confluence';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE confluence_page (id INT AUTO_INCREMENT NOT NULL, base_hash VARCHAR(40) NOT NULL, kind VARCHAR(16) NOT NULL, external_id VARCHAR(64) NOT NULL, space_key VARCHAR(255) NOT NULL, title VARCHAR(255) NOT NULL, parent_id VARCHAR(64) DEFAULT NULL, version INT NOT NULL, web_url VARCHAR(1000) DEFAULT NULL, imported_at DATETIME NOT NULL, document_id INT DEFAULT NULL, section_id INT DEFAULT NULL, INDEX idx_confluence_page_title (space_key, title), UNIQUE INDEX uniq_confluence_page (base_hash, kind, external_id), INDEX IDX_99F2CD52C33F7837 (document_id), INDEX IDX_99F2CD52D823E37A (section_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE confluence_page ADD CONSTRAINT FK_99F2CD52C33F7837 FOREIGN KEY (document_id) REFERENCES document (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE confluence_page ADD CONSTRAINT FK_99F2CD52D823E37A FOREIGN KEY (section_id) REFERENCES section (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE confluence_page DROP FOREIGN KEY FK_99F2CD52C33F7837');
        $this->addSql('ALTER TABLE confluence_page DROP FOREIGN KEY FK_99F2CD52D823E37A');
        $this->addSql('DROP TABLE confluence_page');
        $this->addSql("DELETE FROM setting WHERE name LIKE 'confluence.%'");
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
