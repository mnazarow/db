<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Версия 1.8.0: оргструктура — должность, табельный номер и непосредственный руководитель сотрудника
 * (импорт из 1С:ЗУП или вручную). Руководитель видит ознакомления и просмотры своих сотрудников.
 */
final class Version20261011000016 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Оргструктура: должность, табельный номер, руководитель';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE `user` ADD job_title VARCHAR(128) DEFAULT NULL, ADD employee_number VARCHAR(32) DEFAULT NULL, ADD manager_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE `user` ADD CONSTRAINT FK_8D93D649783E3463 FOREIGN KEY (manager_id) REFERENCES `user` (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_8D93D649783E3463 ON `user` (manager_id)');
        $this->addSql('CREATE INDEX idx_user_employee_number ON `user` (employee_number)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE `user` DROP FOREIGN KEY FK_8D93D649783E3463');
        $this->addSql('DROP INDEX IDX_8D93D649783E3463 ON `user`');
        $this->addSql('DROP INDEX idx_user_employee_number ON `user`');
        $this->addSql('ALTER TABLE `user` DROP job_title, DROP employee_number, DROP manager_id');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
