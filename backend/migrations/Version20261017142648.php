<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261017142648 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Group D: Delivery (pickup_tokens, deliveries)';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE deliveries (id BINARY(16) NOT NULL, mode VARCHAR(30) NOT NULL, delivery_address VARCHAR(255) DEFAULT NULL, delivery_cost_cents INT DEFAULT 0 NOT NULL, status VARCHAR(20) DEFAULT \'in_attesa\' NOT NULL, expected_at DATETIME DEFAULT NULL, delivered_at DATETIME DEFAULT NULL, participation_id BINARY(16) NOT NULL, pickup_point_id BINARY(16) DEFAULT NULL, UNIQUE INDEX UNIQ_6F0785686ACE3B73 (participation_id), INDEX IDX_6F078568682033F1 (pickup_point_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE pickup_tokens (id BINARY(16) NOT NULL, token_hash VARCHAR(64) NOT NULL, assigned_quantity INT NOT NULL, status VARCHAR(20) DEFAULT \'generato\' NOT NULL, created_at DATETIME NOT NULL, scanned_at DATETIME DEFAULT NULL, participation_id BINARY(16) NOT NULL, UNIQUE INDEX UNIQ_382B5D58B3BC57DA (token_hash), INDEX IDX_382B5D586ACE3B73 (participation_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE deliveries ADD CONSTRAINT FK_6F0785686ACE3B73 FOREIGN KEY (participation_id) REFERENCES participations (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE deliveries ADD CONSTRAINT FK_6F078568682033F1 FOREIGN KEY (pickup_point_id) REFERENCES pickup_points (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE pickup_tokens ADD CONSTRAINT FK_382B5D586ACE3B73 FOREIGN KEY (participation_id) REFERENCES participations (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE deliveries DROP FOREIGN KEY FK_6F0785686ACE3B73');
        $this->addSql('ALTER TABLE deliveries DROP FOREIGN KEY FK_6F078568682033F1');
        $this->addSql('ALTER TABLE pickup_tokens DROP FOREIGN KEY FK_382B5D586ACE3B73');
        $this->addSql('DROP TABLE deliveries');
        $this->addSql('DROP TABLE pickup_tokens');
    }
}
