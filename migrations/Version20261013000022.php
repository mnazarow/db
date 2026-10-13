<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Версия 1.8.0: ежедневные итоги статистики — по дням и типам событий (stat_day) и за периоды страницы
 * «Статистика» по документам и сотрудникам (stat_window); заполняются заданием app:stats:aggregate (или первым
 * открытием статистики за день). Индексы для «давно не открывали» и разреза документов по типам.
 */
final class Version20261013000022 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ежедневные итоги статистики';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE stat_day (day DATE NOT NULL, type VARCHAR(24) NOT NULL, cnt INT NOT NULL, PRIMARY KEY (day, type)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        // «Давно не открывали» (сортировка по последнему просмотру) и документы по типам — без перебора таблицы.
        $this->addSql('CREATE INDEX idx_document_status_viewed ON document (status, last_viewed_at)');
        $this->addSql('CREATE INDEX idx_document_type ON document (type)');
        $this->addSql('CREATE TABLE stat_window (period SMALLINT NOT NULL, kind VARCHAR(1) NOT NULL, ref_id INT NOT NULL, views INT NOT NULL, downloads INT NOT NULL, changes INT NOT NULL, score INT NOT NULL, last_at DATETIME DEFAULT NULL, INDEX idx_stat_window_score (period, kind, score), PRIMARY KEY (period, kind, ref_id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_document_status_viewed ON document');
        $this->addSql('DROP INDEX idx_document_type ON document');
        $this->addSql('DROP TABLE stat_window');
        $this->addSql('DROP TABLE stat_day');
        $this->addSql("DELETE FROM setting WHERE name = 'stats.aggregated_on'");
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
