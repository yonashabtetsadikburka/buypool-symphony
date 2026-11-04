<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261104100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Campaign closure: cancellation reason (legacy collette.motivo_annullamento) and balance payment deadline (legacy prenotazioni.saldo_scadenza)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE campaigns ADD cancellation_reason VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE participations ADD balance_due_at DATETIME DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE campaigns DROP cancellation_reason');
        $this->addSql('ALTER TABLE participations DROP balance_due_at');
    }
}
