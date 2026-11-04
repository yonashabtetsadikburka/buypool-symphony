<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261103100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Campaigns: unit price fixed at closure (legacy collette.prezzo_finale)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE campaigns ADD final_price_cents INT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE campaigns DROP final_price_cents');
    }
}
