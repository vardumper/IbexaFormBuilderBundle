<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260322000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Creates form_submission table for IbexaFormBuilderBundle';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE form_submission (
                id INT AUTO_INCREMENT NOT NULL,
                content_id INT NOT NULL,
                submitted_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                data JSON NOT NULL,
                ip_address VARCHAR(45) DEFAULT NULL,
                PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE form_submission');
    }
}
