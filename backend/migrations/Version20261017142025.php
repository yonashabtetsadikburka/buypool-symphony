<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261017142025 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Group B: Campaign (categories, products, product_images, campaigns, price_tiers) and SupplierOrder + PickupPoint + ProductLike';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE campaigns (id BINARY(16) NOT NULL, moq INT NOT NULL, current_quantity INT DEFAULT 0 NOT NULL, starts_at DATETIME NOT NULL, deadline DATETIME NOT NULL, status VARCHAR(30) DEFAULT \'in_corso\' NOT NULL, status_updated_at DATETIME DEFAULT NULL, base_price_cents INT NOT NULL, current_price_cents INT NOT NULL, commission_rate NUMERIC(5, 2) DEFAULT \'10.00\' NOT NULL, confirmed_at DATETIME DEFAULT NULL, closed_at DATETIME DEFAULT NULL, product_id BINARY(16) NOT NULL, opened_by_id BINARY(16) DEFAULT NULL, referrer_id BINARY(16) DEFAULT NULL, pickup_point_id BINARY(16) DEFAULT NULL, confirmed_by_id BINARY(16) DEFAULT NULL, INDEX idx_campaigns_status (status, deadline), INDEX IDX_E37374704584665A (product_id), INDEX IDX_E3737470AB159F5 (opened_by_id), INDEX IDX_E3737470798C22DB (referrer_id), INDEX IDX_E3737470682033F1 (pickup_point_id), INDEX IDX_E37374706F45385D (confirmed_by_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE categories (id BINARY(16) NOT NULL, name VARCHAR(100) NOT NULL, description VARCHAR(255) DEFAULT NULL, UNIQUE INDEX UNIQ_3AF346685E237E06 (name), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE pickup_points (id BINARY(16) NOT NULL, name VARCHAR(200) NOT NULL, address VARCHAR(255) NOT NULL, city VARCHAR(100) NOT NULL, phone VARCHAR(30) DEFAULT NULL, opening_hours VARCHAR(255) DEFAULT NULL, PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE price_tiers (id BINARY(16) NOT NULL, threshold INT NOT NULL, price_cents INT NOT NULL, campaign_id BINARY(16) NOT NULL, UNIQUE INDEX uniq_price_tier_threshold (campaign_id, threshold), INDEX IDX_3D8E33C2F639F774 (campaign_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE product_images (id BINARY(16) NOT NULL, url VARCHAR(500) NOT NULL, position INT DEFAULT 0 NOT NULL, is_main TINYINT DEFAULT 0 NOT NULL, product_id BINARY(16) NOT NULL, INDEX IDX_8263FFCE4584665A (product_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE product_likes (id BINARY(16) NOT NULL, created_at DATETIME NOT NULL, user_id BINARY(16) NOT NULL, product_id BINARY(16) NOT NULL, UNIQUE INDEX uniq_product_like_user (user_id, product_id), INDEX IDX_E8963521A76ED395 (user_id), INDEX IDX_E89635214584665A (product_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE products (id BINARY(16) NOT NULL, name VARCHAR(200) NOT NULL, description LONGTEXT DEFAULT NULL, unit_price_cents INT NOT NULL, base_price_cents INT DEFAULT NULL, min_quantity INT DEFAULT 1 NOT NULL, status VARCHAR(20) DEFAULT \'attivo\' NOT NULL, created_at DATETIME NOT NULL, supplier_id BINARY(16) NOT NULL, category_id BINARY(16) DEFAULT NULL, origin_proposal_id BINARY(16) DEFAULT NULL, INDEX idx_products_supplier (supplier_id), INDEX idx_products_category (category_id), INDEX IDX_B3BA5A5AFCADC62F (origin_proposal_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE supplier_orders (id BINARY(16) NOT NULL, ordered_quantity INT NOT NULL, negotiated_price_cents INT DEFAULT NULL, total_cents INT NOT NULL, status VARCHAR(30) DEFAULT \'inviato\' NOT NULL, ordered_at DATETIME DEFAULT NULL, delivered_at DATETIME DEFAULT NULL, order_number VARCHAR(20) NOT NULL, order_year INT NOT NULL, campaign_id BINARY(16) NOT NULL, supplier_id BINARY(16) NOT NULL, admin_id BINARY(16) DEFAULT NULL, UNIQUE INDEX UNIQ_3E4D2F3AF639F774 (campaign_id), INDEX idx_supplier_order_year (order_year), UNIQUE INDEX uniq_supplier_order_number (order_number), INDEX IDX_3E4D2F3A2ADD6D8C (supplier_id), INDEX IDX_3E4D2F3A642B8210 (admin_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE campaigns ADD CONSTRAINT FK_E37374704584665A FOREIGN KEY (product_id) REFERENCES products (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE campaigns ADD CONSTRAINT FK_E3737470AB159F5 FOREIGN KEY (opened_by_id) REFERENCES users (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE campaigns ADD CONSTRAINT FK_E3737470798C22DB FOREIGN KEY (referrer_id) REFERENCES users (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE campaigns ADD CONSTRAINT FK_E3737470682033F1 FOREIGN KEY (pickup_point_id) REFERENCES pickup_points (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE campaigns ADD CONSTRAINT FK_E37374706F45385D FOREIGN KEY (confirmed_by_id) REFERENCES users (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE price_tiers ADD CONSTRAINT FK_3D8E33C2F639F774 FOREIGN KEY (campaign_id) REFERENCES campaigns (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE product_images ADD CONSTRAINT FK_8263FFCE4584665A FOREIGN KEY (product_id) REFERENCES products (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE product_likes ADD CONSTRAINT FK_E8963521A76ED395 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE product_likes ADD CONSTRAINT FK_E89635214584665A FOREIGN KEY (product_id) REFERENCES products (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE products ADD CONSTRAINT FK_B3BA5A5A2ADD6D8C FOREIGN KEY (supplier_id) REFERENCES suppliers (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE products ADD CONSTRAINT FK_B3BA5A5A12469DE2 FOREIGN KEY (category_id) REFERENCES categories (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE products ADD CONSTRAINT FK_B3BA5A5AFCADC62F FOREIGN KEY (origin_proposal_id) REFERENCES proposals (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE supplier_orders ADD CONSTRAINT FK_3E4D2F3AF639F774 FOREIGN KEY (campaign_id) REFERENCES campaigns (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE supplier_orders ADD CONSTRAINT FK_3E4D2F3A2ADD6D8C FOREIGN KEY (supplier_id) REFERENCES suppliers (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE supplier_orders ADD CONSTRAINT FK_3E4D2F3A642B8210 FOREIGN KEY (admin_id) REFERENCES users (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE campaigns DROP FOREIGN KEY FK_E37374704584665A');
        $this->addSql('ALTER TABLE campaigns DROP FOREIGN KEY FK_E3737470AB159F5');
        $this->addSql('ALTER TABLE campaigns DROP FOREIGN KEY FK_E3737470798C22DB');
        $this->addSql('ALTER TABLE campaigns DROP FOREIGN KEY FK_E3737470682033F1');
        $this->addSql('ALTER TABLE campaigns DROP FOREIGN KEY FK_E37374706F45385D');
        $this->addSql('ALTER TABLE price_tiers DROP FOREIGN KEY FK_3D8E33C2F639F774');
        $this->addSql('ALTER TABLE product_images DROP FOREIGN KEY FK_8263FFCE4584665A');
        $this->addSql('ALTER TABLE product_likes DROP FOREIGN KEY FK_E8963521A76ED395');
        $this->addSql('ALTER TABLE product_likes DROP FOREIGN KEY FK_E89635214584665A');
        $this->addSql('ALTER TABLE products DROP FOREIGN KEY FK_B3BA5A5A2ADD6D8C');
        $this->addSql('ALTER TABLE products DROP FOREIGN KEY FK_B3BA5A5A12469DE2');
        $this->addSql('ALTER TABLE products DROP FOREIGN KEY FK_B3BA5A5AFCADC62F');
        $this->addSql('ALTER TABLE supplier_orders DROP FOREIGN KEY FK_3E4D2F3AF639F774');
        $this->addSql('ALTER TABLE supplier_orders DROP FOREIGN KEY FK_3E4D2F3A2ADD6D8C');
        $this->addSql('ALTER TABLE supplier_orders DROP FOREIGN KEY FK_3E4D2F3A642B8210');
        $this->addSql('DROP TABLE campaigns');
        $this->addSql('DROP TABLE categories');
        $this->addSql('DROP TABLE pickup_points');
        $this->addSql('DROP TABLE price_tiers');
        $this->addSql('DROP TABLE product_images');
        $this->addSql('DROP TABLE product_likes');
        $this->addSql('DROP TABLE products');
        $this->addSql('DROP TABLE supplier_orders');
    }
}
