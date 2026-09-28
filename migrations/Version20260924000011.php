<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Версия 1.6.0: типы файлов (document_kind, document.kind_id) и таблицы материалов разделов
 * верхнего уровня (material_parameter, material_row, section.materials_enabled).
 *
 * Существующим документам тип проставляется по названию и расширению файла: каталоги и брошюры,
 * паспорта и руководства, опросные листы и ТКП, фотографии; остальным — «Прочее».
 */
final class Version20260924000011 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Типы файлов и таблицы материалов';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE document_kind (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(64) NOT NULL, position INT DEFAULT 0 NOT NULL, is_system TINYINT DEFAULT 0 NOT NULL, created_at DATETIME NOT NULL, UNIQUE INDEX uniq_document_kind_name (name), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE material_row (id INT AUTO_INCREMENT NOT NULL, title VARCHAR(255) NOT NULL, param_values JSON NOT NULL, position INT DEFAULT 0 NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, updated_by_name VARCHAR(128) DEFAULT NULL, section_id INT NOT NULL, kind_id INT DEFAULT NULL, document_id INT DEFAULT NULL, INDEX idx_material_row_section (section_id, position), INDEX idx_material_row_document (document_id), INDEX IDX_320AEA2DD823E37A (section_id), INDEX IDX_320AEA2D30602CA9 (kind_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE material_parameter (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(100) NOT NULL, kind VARCHAR(12) NOT NULL, options JSON NOT NULL, role VARCHAR(16) DEFAULT NULL, position INT DEFAULT 0 NOT NULL, width INT DEFAULT NULL, created_at DATETIME NOT NULL, section_id INT NOT NULL, INDEX idx_material_parameter_section (section_id, position), INDEX IDX_12098BF0D823E37A (section_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE material_row ADD CONSTRAINT FK_320AEA2DD823E37A FOREIGN KEY (section_id) REFERENCES section (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE material_row ADD CONSTRAINT FK_320AEA2D30602CA9 FOREIGN KEY (kind_id) REFERENCES document_kind (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE material_row ADD CONSTRAINT FK_320AEA2DC33F7837 FOREIGN KEY (document_id) REFERENCES document (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE material_parameter ADD CONSTRAINT FK_12098BF0D823E37A FOREIGN KEY (section_id) REFERENCES section (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE document ADD kind_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE document ADD CONSTRAINT FK_D8698A7630602CA9 FOREIGN KEY (kind_id) REFERENCES document_kind (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_D8698A7630602CA9 ON document (kind_id)');
        $this->addSql('ALTER TABLE section ADD materials_enabled TINYINT DEFAULT 0 NOT NULL');

        // Справочник типов: пять стандартных, «Прочее» — служебный.
        $this->addSql("INSERT INTO document_kind (name, position, is_system, created_at) VALUES
            ('Каталоги и брошюры', 1, 0, NOW()),
            ('Паспорта и руководства', 2, 0, NOW()),
            ('Опросные листы и ТКП', 3, 0, NOW()),
            ('Фотографии', 4, 0, NOW()),
            ('Прочее', 5, 1, NOW())");

        // Тип существующих документов — по названию (более поздние правила важнее) и по расширению файла.
        $kind = static fn (string $name): string => \sprintf("(SELECT k.id FROM document_kind k WHERE k.name = '%s')", $name);
        $this->addSql('UPDATE document SET kind_id = '.$kind('Прочее'));
        $this->addSql('UPDATE document SET kind_id = '.$kind('Каталоги и брошюры')." WHERE title LIKE '%каталог%' OR title LIKE '%брошюр%' OR title LIKE '%буклет%' OR title LIKE '%альбом%'");
        $this->addSql('UPDATE document SET kind_id = '.$kind('Паспорта и руководства')." WHERE title LIKE '%паспорт%' OR title LIKE '%руководств%' OR title LIKE '%инструкция по монтажу%' OR title LIKE '%инструкция по эксплуатации%'");
        $this->addSql('UPDATE document SET kind_id = '.$kind('Опросные листы и ТКП')." WHERE title LIKE '%опросн% лист%' OR title LIKE 'ТКП%' OR title LIKE '% ТКП%' OR title LIKE '%коммерческ% предложени%'");
        $this->addSql('UPDATE document d JOIN document_version v ON v.id = d.current_version_id SET d.kind_id = '.$kind('Фотографии')." WHERE LOWER(SUBSTRING_INDEX(v.original_name, '.', -1)) IN ('jpg', 'jpeg', 'png', 'gif', 'webp', 'tif', 'tiff', 'bmp', 'heic', 'heif')");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE material_row DROP FOREIGN KEY FK_320AEA2DD823E37A');
        $this->addSql('ALTER TABLE material_row DROP FOREIGN KEY FK_320AEA2D30602CA9');
        $this->addSql('ALTER TABLE material_row DROP FOREIGN KEY FK_320AEA2DC33F7837');
        $this->addSql('ALTER TABLE material_parameter DROP FOREIGN KEY FK_12098BF0D823E37A');
        $this->addSql('ALTER TABLE document DROP FOREIGN KEY FK_D8698A7630602CA9');
        $this->addSql('DROP INDEX IDX_D8698A7630602CA9 ON document');
        $this->addSql('ALTER TABLE document DROP kind_id');
        $this->addSql('ALTER TABLE section DROP materials_enabled');
        $this->addSql('DROP TABLE material_row');
        $this->addSql('DROP TABLE material_parameter');
        $this->addSql('DROP TABLE document_kind');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
