<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Версия 1.8.0: раздел «Прайс-листы» — прайс-листы, их редакции (загруженные файлы Excel) и позиции.
 */
final class Version20261020000027 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Прайс-листы';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE price_item (id INT AUTO_INCREMENT NOT NULL, position INT NOT NULL, sku VARCHAR(100) DEFAULT NULL, name VARCHAR(1000) NOT NULL, group_name VARCHAR(255) DEFAULT NULL, unit VARCHAR(32) DEFAULT NULL, price NUMERIC(15, 2) DEFAULT NULL, prices JSON NOT NULL, extra JSON NOT NULL, search_text VARCHAR(1500) NOT NULL, revision_id INT NOT NULL, INDEX idx_price_item_revision (revision_id, position), INDEX idx_price_item_sku (revision_id, sku), INDEX IDX_6249D7A41DFA7C8F (revision_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE price_list (id INT AUTO_INCREMENT NOT NULL, title VARCHAR(255) NOT NULL, description LONGTEXT DEFAULT NULL, currency VARCHAR(3) NOT NULL, is_public TINYINT NOT NULL, original_download TINYINT DEFAULT 0 NOT NULL, status VARCHAR(16) NOT NULL, position INT NOT NULL, last_mapping JSON DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, current_revision_id INT DEFAULT NULL, created_by_id INT DEFAULT NULL, INDEX idx_price_list_status (status, position), INDEX IDX_399A0AA2A32ED756 (current_revision_id), INDEX IDX_399A0AA2B03A8386 (created_by_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE price_revision (id INT AUTO_INCREMENT NOT NULL, number INT NOT NULL, status VARCHAR(16) NOT NULL, file_path VARCHAR(255) NOT NULL, original_name VARCHAR(255) NOT NULL, file_size BIGINT NOT NULL, sha256 VARCHAR(64) NOT NULL, sheet_name VARCHAR(255) DEFAULT NULL, mapping JSON DEFAULT NULL, price_columns JSON NOT NULL, extra_columns JSON NOT NULL, item_count INT NOT NULL, stats JSON DEFAULT NULL, effective_from DATE DEFAULT NULL, note LONGTEXT DEFAULT NULL, uploaded_at DATETIME NOT NULL, published_at DATETIME DEFAULT NULL, decision_note LONGTEXT DEFAULT NULL, price_list_id INT NOT NULL, uploaded_by_id INT DEFAULT NULL, decided_by_id INT DEFAULT NULL, INDEX idx_price_revision_list (price_list_id, number), INDEX idx_price_revision_status (status), INDEX IDX_FD52EDB5688DED7 (price_list_id), INDEX IDX_FD52EDBA2B28FE8 (uploaded_by_id), INDEX IDX_FD52EDBE26B496B (decided_by_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE price_item ADD CONSTRAINT FK_6249D7A41DFA7C8F FOREIGN KEY (revision_id) REFERENCES price_revision (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE price_list ADD CONSTRAINT FK_399A0AA2A32ED756 FOREIGN KEY (current_revision_id) REFERENCES price_revision (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE price_list ADD CONSTRAINT FK_399A0AA2B03A8386 FOREIGN KEY (created_by_id) REFERENCES `user` (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE price_revision ADD CONSTRAINT FK_FD52EDB5688DED7 FOREIGN KEY (price_list_id) REFERENCES price_list (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE price_revision ADD CONSTRAINT FK_FD52EDBA2B28FE8 FOREIGN KEY (uploaded_by_id) REFERENCES `user` (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE price_revision ADD CONSTRAINT FK_FD52EDBE26B496B FOREIGN KEY (decided_by_id) REFERENCES `user` (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE price_item DROP FOREIGN KEY FK_6249D7A41DFA7C8F');
        $this->addSql('ALTER TABLE price_list DROP FOREIGN KEY FK_399A0AA2A32ED756');
        $this->addSql('ALTER TABLE price_list DROP FOREIGN KEY FK_399A0AA2B03A8386');
        $this->addSql('ALTER TABLE price_revision DROP FOREIGN KEY FK_FD52EDB5688DED7');
        $this->addSql('ALTER TABLE price_revision DROP FOREIGN KEY FK_FD52EDBA2B28FE8');
        $this->addSql('ALTER TABLE price_revision DROP FOREIGN KEY FK_FD52EDBE26B496B');
        $this->addSql('DROP TABLE price_item');
        $this->addSql('DROP TABLE price_list');
        $this->addSql('DROP TABLE price_revision');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
