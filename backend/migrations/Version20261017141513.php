<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261017141513 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Group E: Proposal (proposals, proposal_tiers, proposal_votes)';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE proposal_tiers (id BINARY(16) NOT NULL, threshold INT NOT NULL, price_cents INT NOT NULL, proposal_id BINARY(16) NOT NULL, UNIQUE INDEX uniq_proposal_tier_threshold (proposal_id, threshold), INDEX IDX_D1B0F203F4792058 (proposal_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE proposal_votes (id BINARY(16) NOT NULL, value VARCHAR(10) NOT NULL, created_at DATETIME NOT NULL, proposal_id BINARY(16) NOT NULL, voter_id BINARY(16) NOT NULL, UNIQUE INDEX uniq_proposal_vote_voter (proposal_id, voter_id), INDEX IDX_967CB36EF4792058 (proposal_id), INDEX IDX_967CB36EEBB4B8AD (voter_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE proposals (id BINARY(16) NOT NULL, title VARCHAR(200) NOT NULL, description LONGTEXT DEFAULT NULL, status VARCHAR(20) DEFAULT \'in_attesa\' NOT NULL, votes_count INT DEFAULT 0 NOT NULL, created_at DATETIME NOT NULL, handled_at DATETIME DEFAULT NULL, requested_moq INT DEFAULT NULL, base_price_cents INT DEFAULT NULL, current_price_cents INT DEFAULT NULL, currency VARCHAR(3) DEFAULT \'EUR\' NOT NULL, delivery_time VARCHAR(100) DEFAULT NULL, reference_url VARCHAR(500) DEFAULT NULL, photo_path VARCHAR(500) DEFAULT NULL, rejection_reason LONGTEXT DEFAULT NULL, proposer_id BINARY(16) NOT NULL, suggested_supplier_id BINARY(16) DEFAULT NULL, handled_by_id BINARY(16) DEFAULT NULL, INDEX idx_proposals_status (status), INDEX IDX_A5BA3A8FB13FA634 (proposer_id), INDEX IDX_A5BA3A8FE58C2EFE (suggested_supplier_id), INDEX IDX_A5BA3A8FFE65AF40 (handled_by_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE proposal_tiers ADD CONSTRAINT FK_D1B0F203F4792058 FOREIGN KEY (proposal_id) REFERENCES proposals (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE proposal_votes ADD CONSTRAINT FK_967CB36EF4792058 FOREIGN KEY (proposal_id) REFERENCES proposals (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE proposal_votes ADD CONSTRAINT FK_967CB36EEBB4B8AD FOREIGN KEY (voter_id) REFERENCES users (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE proposals ADD CONSTRAINT FK_A5BA3A8FB13FA634 FOREIGN KEY (proposer_id) REFERENCES users (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE proposals ADD CONSTRAINT FK_A5BA3A8FE58C2EFE FOREIGN KEY (suggested_supplier_id) REFERENCES suppliers (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE proposals ADD CONSTRAINT FK_A5BA3A8FFE65AF40 FOREIGN KEY (handled_by_id) REFERENCES users (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE proposal_tiers DROP FOREIGN KEY FK_D1B0F203F4792058');
        $this->addSql('ALTER TABLE proposal_votes DROP FOREIGN KEY FK_967CB36EF4792058');
        $this->addSql('ALTER TABLE proposal_votes DROP FOREIGN KEY FK_967CB36EEBB4B8AD');
        $this->addSql('ALTER TABLE proposals DROP FOREIGN KEY FK_A5BA3A8FB13FA634');
        $this->addSql('ALTER TABLE proposals DROP FOREIGN KEY FK_A5BA3A8FE58C2EFE');
        $this->addSql('ALTER TABLE proposals DROP FOREIGN KEY FK_A5BA3A8FFE65AF40');
        $this->addSql('DROP TABLE proposal_tiers');
        $this->addSql('DROP TABLE proposal_votes');
        $this->addSql('DROP TABLE proposals');
    }
}
