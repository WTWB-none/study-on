<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260517130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add billing type and price to course.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE course ADD type VARCHAR(16) DEFAULT NULL');
        $this->addSql('ALTER TABLE course ADD price DOUBLE PRECISION DEFAULT NULL');
        $this->addSql("UPDATE course SET type = CASE symbolic_code
            WHEN 'python-data-analysis' THEN 'rent'
            WHEN 'ux-writing-basics' THEN 'free'
            WHEN 'sql-for-product-managers' THEN 'buy'
            WHEN 'project-management-essentials' THEN 'buy'
            ELSE 'free'
        END");
        $this->addSql("UPDATE course SET price = CASE symbolic_code
            WHEN 'python-data-analysis' THEN 99.90
            WHEN 'sql-for-product-managers' THEN 159.00
            WHEN 'project-management-essentials' THEN 79.00
            ELSE NULL
        END");
        $this->addSql('ALTER TABLE course ALTER COLUMN type SET NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE course DROP type');
        $this->addSql('ALTER TABLE course DROP price');
    }
}
