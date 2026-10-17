<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261017142423 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Group C: Participation and Payment (participations, payments, stripe_events)';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE participations (id BINARY(16) NOT NULL, quantity INT NOT NULL, deposit_cents INT DEFAULT 0 NOT NULL, balance_cents INT DEFAULT NULL, commission_cents INT DEFAULT 0 NOT NULL, status VARCHAR(20) DEFAULT \'prenotata\' NOT NULL, created_at DATETIME NOT NULL, paid_at DATETIME DEFAULT NULL, campaign_id BINARY(16) NOT NULL, user_id BINARY(16) NOT NULL, INDEX idx_participations_campaign (campaign_id), UNIQUE INDEX uniq_participation_campaign_user (campaign_id, user_id), INDEX IDX_FDC6C6E8A76ED395 (user_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE payments (id BINARY(16) NOT NULL, type VARCHAR(20) NOT NULL, amount_cents INT NOT NULL, currency VARCHAR(3) DEFAULT \'EUR\' NOT NULL, agency_commission_cents INT DEFAULT 0 NOT NULL, stripe_payment_intent_id VARCHAR(100) DEFAULT NULL, stripe_charge_id VARCHAR(100) DEFAULT NULL, stripe_payment_method VARCHAR(50) DEFAULT NULL, stripe_status VARCHAR(50) DEFAULT NULL, status VARCHAR(20) DEFAULT \'in_attesa\' NOT NULL, created_at DATETIME NOT NULL, confirmed_at DATETIME DEFAULT NULL, participation_id BINARY(16) NOT NULL, UNIQUE INDEX UNIQ_65D29B32FC72F97E (stripe_payment_intent_id), INDEX idx_payments_stripe_intent (stripe_payment_intent_id), INDEX IDX_65D29B326ACE3B73 (participation_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE stripe_events (id BINARY(16) NOT NULL, event_id VARCHAR(100) NOT NULL, event_type VARCHAR(100) NOT NULL, processed TINYINT DEFAULT 0 NOT NULL, received_at DATETIME NOT NULL, payment_id BINARY(16) DEFAULT NULL, UNIQUE INDEX UNIQ_FBCA2A7671F7E88B (event_id), INDEX idx_stripe_events_type (event_type), INDEX IDX_FBCA2A764C3A3BB (payment_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE participations ADD CONSTRAINT FK_FDC6C6E8F639F774 FOREIGN KEY (campaign_id) REFERENCES campaigns (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE participations ADD CONSTRAINT FK_FDC6C6E8A76ED395 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE payments ADD CONSTRAINT FK_65D29B326ACE3B73 FOREIGN KEY (participation_id) REFERENCES participations (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE stripe_events ADD CONSTRAINT FK_FBCA2A764C3A3BB FOREIGN KEY (payment_id) REFERENCES payments (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE participations DROP FOREIGN KEY FK_FDC6C6E8F639F774');
        $this->addSql('ALTER TABLE participations DROP FOREIGN KEY FK_FDC6C6E8A76ED395');
        $this->addSql('ALTER TABLE payments DROP FOREIGN KEY FK_65D29B326ACE3B73');
        $this->addSql('ALTER TABLE stripe_events DROP FOREIGN KEY FK_FBCA2A764C3A3BB');
        $this->addSql('DROP TABLE participations');
        $this->addSql('DROP TABLE payments');
        $this->addSql('DROP TABLE stripe_events');
    }
}
