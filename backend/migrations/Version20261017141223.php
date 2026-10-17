<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261017141223 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Group A: Identity (users, external_identities, password_reset_tokens) and Supplier (suppliers, supplier_invites)';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE external_identities (id BINARY(16) NOT NULL, provider VARCHAR(20) NOT NULL, subject VARCHAR(255) NOT NULL, created_at DATETIME NOT NULL, user_id BINARY(16) NOT NULL, UNIQUE INDEX uniq_external_identity_provider_subject (provider, subject), INDEX IDX_E0C7A33AA76ED395 (user_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE password_reset_tokens (id BINARY(16) NOT NULL, token_hash VARCHAR(255) NOT NULL, expires_at DATETIME NOT NULL, used_at DATETIME DEFAULT NULL, created_at DATETIME NOT NULL, user_id BINARY(16) NOT NULL, UNIQUE INDEX UNIQ_3967A216B3BC57DA (token_hash), INDEX IDX_3967A216A76ED395 (user_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE supplier_invites (id BINARY(16) NOT NULL, email VARCHAR(255) NOT NULL, token_hash VARCHAR(255) NOT NULL, expires_at DATETIME NOT NULL, used_at DATETIME DEFAULT NULL, created_at DATETIME NOT NULL, supplier_id BINARY(16) NOT NULL, UNIQUE INDEX UNIQ_821B1E73B3BC57DA (token_hash), INDEX IDX_821B1E732ADD6D8C (supplier_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE suppliers (id BINARY(16) NOT NULL, name VARCHAR(200) NOT NULL, contact_email VARCHAR(255) DEFAULT NULL, phone VARCHAR(30) DEFAULT NULL, address LONGTEXT DEFAULT NULL, description LONGTEXT DEFAULT NULL, logo_path VARCHAR(500) DEFAULT NULL, is_public TINYINT DEFAULT 1 NOT NULL, partnered_at DATETIME NOT NULL, vat_number VARCHAR(20) DEFAULT NULL, website_url VARCHAR(500) DEFAULT NULL, user_id BINARY(16) DEFAULT NULL, UNIQUE INDEX UNIQ_AC28B95CA76ED395 (user_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE users (id BINARY(16) NOT NULL, first_name VARCHAR(100) NOT NULL, last_name VARCHAR(100) DEFAULT NULL, email VARCHAR(255) NOT NULL, hashed_password VARCHAR(255) DEFAULT NULL, phone VARCHAR(30) DEFAULT NULL, address LONGTEXT DEFAULT NULL, zip_code VARCHAR(10) DEFAULT NULL, city VARCHAR(100) DEFAULT NULL, province VARCHAR(5) DEFAULT NULL, customer_type VARCHAR(20) DEFAULT \'privato\' NOT NULL, role VARCHAR(20) DEFAULT \'cliente\' NOT NULL, status VARCHAR(20) DEFAULT \'attivo\' NOT NULL, stripe_customer_id VARCHAR(100) DEFAULT NULL, vat_number VARCHAR(20) DEFAULT NULL, fiscal_code VARCHAR(16) DEFAULT NULL, created_at DATETIME NOT NULL, privacy_consented_at DATETIME DEFAULT NULL, consent_version VARCHAR(32) DEFAULT NULL, UNIQUE INDEX UNIQ_1483A5E9E7927C74 (email), UNIQUE INDEX UNIQ_1483A5E9708DC647 (stripe_customer_id), INDEX idx_users_role (role), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE external_identities ADD CONSTRAINT FK_E0C7A33AA76ED395 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE password_reset_tokens ADD CONSTRAINT FK_3967A216A76ED395 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE supplier_invites ADD CONSTRAINT FK_821B1E732ADD6D8C FOREIGN KEY (supplier_id) REFERENCES suppliers (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE suppliers ADD CONSTRAINT FK_AC28B95CA76ED395 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE external_identities DROP FOREIGN KEY FK_E0C7A33AA76ED395');
        $this->addSql('ALTER TABLE password_reset_tokens DROP FOREIGN KEY FK_3967A216A76ED395');
        $this->addSql('ALTER TABLE supplier_invites DROP FOREIGN KEY FK_821B1E732ADD6D8C');
        $this->addSql('ALTER TABLE suppliers DROP FOREIGN KEY FK_AC28B95CA76ED395');
        $this->addSql('DROP TABLE external_identities');
        $this->addSql('DROP TABLE password_reset_tokens');
        $this->addSql('DROP TABLE supplier_invites');
        $this->addSql('DROP TABLE suppliers');
        $this->addSql('DROP TABLE users');
    }
}
