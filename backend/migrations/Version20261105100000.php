<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261105100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Supplier fulfilment: confirmation and shipping data on supplier orders (legacy ordini_fornitore.confermato_il, corriere, tracking, data_spedizione)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE supplier_orders ADD confirmed_at DATETIME DEFAULT NULL, ADD carrier VARCHAR(40) DEFAULT NULL, ADD tracking_number VARCHAR(40) DEFAULT NULL, ADD shipped_on DATE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE supplier_orders DROP confirmed_at, DROP carrier, DROP tracking_number, DROP shipped_on');
    }
}
