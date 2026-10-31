<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261031100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Users: company name for b2b customers (legacy utenti.ragione_sociale)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE users ADD company_name VARCHAR(190) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE users DROP company_name');
    }
}
