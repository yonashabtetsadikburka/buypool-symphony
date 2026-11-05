<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261106100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Home delivery shipping by the admin: tracking number and shipping date on deliveries (legacy consegne.tracking, data_effettiva)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE deliveries ADD tracking_number VARCHAR(120) DEFAULT NULL, ADD shipped_at DATETIME DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE deliveries DROP tracking_number, DROP shipped_at');
    }
}
