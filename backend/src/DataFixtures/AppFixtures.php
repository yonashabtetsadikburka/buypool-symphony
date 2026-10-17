<?php

namespace App\DataFixtures;

use App\Campaign\Entity\Campaign;
use App\Campaign\Entity\Category;
use App\Campaign\Entity\PriceTier;
use App\Campaign\Entity\Product;
use App\Campaign\Entity\ProductImage;
use App\Campaign\Enum\CampaignStatus;
use App\Delivery\Entity\Delivery;
use App\Delivery\Entity\PickupPoint;
use App\Delivery\Entity\PickupToken;
use App\Delivery\Enum\DeliveryMode;
use App\Identity\Entity\ExternalIdentity;
use App\Identity\Entity\PasswordResetToken;
use App\Identity\Entity\User;
use App\Identity\Enum\ExternalProvider;
use App\Identity\Enum\UserRole;
use App\Notification\Entity\Notification;
use App\Notification\Enum\NotificationType;
use App\Notification\Enum\ReferenceType;
use App\Participation\Entity\Participation;
use App\Participation\Enum\ParticipationStatus;
use App\Payment\Entity\Payment;
use App\Payment\Entity\StripeEvent;
use App\Payment\Enum\PaymentType;
use App\Proposal\Entity\ProductLike;
use App\Proposal\Entity\Proposal;
use App\Proposal\Entity\ProposalTier;
use App\Proposal\Entity\ProposalVote;
use App\Proposal\Enum\ProposalStatus;
use App\Proposal\Enum\VoteValue;
use App\Supplier\Entity\Supplier;
use App\Supplier\Entity\SupplierInvite;
use App\Supplier\Entity\SupplierOrder;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class AppFixtures extends Fixture
{
    private const DEV_PASSWORD = 'dev-only';

    public function __construct(private readonly UserPasswordHasherInterface $passwordHasher)
    {
    }

    public function load(ObjectManager $manager): void
    {
        $admin = $this->user('Ada', 'Admin', 'admin@example.com', UserRole::Admin);
        $supplierUserOne = $this->user('Bruno', 'Forni', 'supplier1@example.com', UserRole::Fornitore);
        $supplierUserTwo = $this->user('Carla', 'Rossi', 'supplier2@example.com', UserRole::Fornitore);
        $mario = $this->user('Mario', 'Bianchi', 'mario@example.com', UserRole::Cliente);
        $luigi = $this->user('Luigi', 'Verdi', 'luigi@example.com', UserRole::Cliente);
        $giulia = $this->user('Giulia', 'Neri', 'giulia@example.com', UserRole::Cliente);

        foreach ([$admin, $supplierUserOne, $supplierUserTwo, $mario, $luigi, $giulia] as $user) {
            $manager->persist($user);
        }

        $manager->persist(new ExternalIdentity(ExternalProvider::Google, 'google-sub-mario', $mario));
        $manager->persist(new PasswordResetToken($mario, hash('sha256', 'reset-mario'), new \DateTimeImmutable('+1 day')));

        $supplierOne = new Supplier('Alpine Gear Srl');
        $supplierOne->setUser($supplierUserOne);
        $supplierOne->setContactEmail('info@alpinegear.example.com');
        $supplierOne->setAddress('Via delle Alpi 4, Trento');

        $supplierTwo = new Supplier('Casa Verde Srl');
        $supplierTwo->setUser($supplierUserTwo);
        $supplierTwo->setContactEmail('info@casaverde.example.com');
        $supplierTwo->setAddress('Via Emilia 8, Bologna');

        $manager->persist($supplierOne);
        $manager->persist($supplierTwo);

        $manager->persist(new SupplierInvite(
            $supplierTwo,
            'nuovo.fornitore@example.com',
            hash('sha256', 'invite-casverde'),
            new \DateTimeImmutable('+7 days'),
        ));

        $categories = [];
        foreach (['Accessori' => 'Accessori da viaggio', 'Abbigliamento' => 'Capispalla e maglieria', 'Casa' => 'Arredo e cucina'] as $name => $description) {
            $category = new Category($name);
            $category->setDescription($description);
            $manager->persist($category);
            $categories[$name] = $category;
        }

        $pointOne = new PickupPoint('Sede Centrale', 'Via Roma 1', 'Milano');
        $pointOne->setOpeningHours('Lun-Ven 9:00-18:00');
        $pointTwo = new PickupPoint('Punto Nord', 'Corso Italia 22', 'Trento');
        $manager->persist($pointOne);
        $manager->persist($pointTwo);

        $zaino = new Product($supplierOne, 'Zaino da trekking 30L', 4990);
        $zaino->setCategory($categories['Accessori']);
        $zaino->setDescription('Zaino leggero e resistente per escursioni di più giorni.');
        $manager->persist($zaino);
        $manager->persist(new ProductImage($zaino, '/images/zaino-30l-1.jpg'));
        $manager->persist(new ProductImage($zaino, '/images/zaino-30l-2.jpg'));

        $giacca = new Product($supplierOne, 'Giacca imbottita invernale', 8990);
        $giacca->setCategory($categories['Abbigliamento']);
        $manager->persist($giacca);

        $padella = new Product($supplierTwo, 'Padella in ghisa 28cm', 3490);
        $padella->setCategory($categories['Casa']);
        $manager->persist($padella);

        $proposalTenda = new Proposal($mario, 'Tenda da campeggio 4 posti');
        $proposalTenda->setDescription('Tenda impermeabile per famiglie.');
        $proposalTenda->setSuggestedSupplier($supplierOne);
        $proposalTenda->setStatus(ProposalStatus::Pubblicata);
        $proposalTenda->setVotesCount(3);
        $manager->persist($proposalTenda);
        $manager->persist(new ProposalTier($proposalTenda, 10, 12900));
        $manager->persist(new ProposalTier($proposalTenda, 25, 10900));

        $proposalLampada = new Proposal($luigi, 'Lampada da scrivania LED');
        $proposalLampada->setStatus(ProposalStatus::InVotazione);
        $manager->persist($proposalLampada);
        $manager->persist(new ProposalVote($proposalLampada, $mario, VoteValue::Favore));
        $manager->persist(new ProposalVote($proposalLampada, $giulia, VoteValue::Favore));

        $campaignOne = new Campaign($zaino, 20, new \DateTimeImmutable('+14 days'), 4990, 4590);
        $campaignOne->setPickupPoint($pointOne);
        $campaignOne->setOpenedBy($admin);
        $campaignOne->setCurrentQuantity(14);
        $manager->persist($campaignOne);
        $manager->persist(new PriceTier($campaignOne, 10, 4590));
        $manager->persist(new PriceTier($campaignOne, 30, 4290));

        $campaignTwo = new Campaign($padella, 15, new \DateTimeImmutable('-2 days'), 3490, 3290);
        $campaignTwo->setStatus(CampaignStatus::Riuscita);
        $campaignTwo->setPickupPoint($pointTwo);
        $campaignTwo->setCurrentQuantity(22);
        $manager->persist($campaignTwo);

        $campaignThree = new Campaign($giacca, 10, new \DateTimeImmutable('-30 days'), 8990, 8490);
        $campaignThree->setStatus(CampaignStatus::Consegnata);
        $campaignThree->setCurrentQuantity(12);
        $campaignThree->setConfirmedBy($admin);
        $campaignThree->setConfirmedAt(new \DateTimeImmutable('-20 days'));
        $manager->persist($campaignThree);

        $participationMario = new Participation($campaignOne, $mario, 2);
        $participationMario->setDepositCents(9180);
        $participationMario->setCommissionCents(918);
        $manager->persist($participationMario);

        $participationLuigi = new Participation($campaignOne, $luigi, 1);
        $participationLuigi->setStatus(ParticipationStatus::Confermata);
        $manager->persist($participationLuigi);

        $participationGiulia = new Participation($campaignTwo, $giulia, 3);
        $participationGiulia->setStatus(ParticipationStatus::Pagata);
        $participationGiulia->setPaidAt(new \DateTimeImmutable('-5 days'));
        $participationGiulia->setDepositCents(9870);
        $manager->persist($participationGiulia);

        $payment = new Payment($participationMario, PaymentType::Acconto, 9180);
        $payment->setStripePaymentIntentId('pi_fixture_mario_deposit');
        $payment->setAgencyCommissionCents(918);
        $manager->persist($payment);

        $manager->persist(new StripeEvent('evt_fixture_mario_deposit', 'payment_intent.succeeded'));

        $deliveryMario = new Delivery($participationMario, DeliveryMode::RitiroSede);
        $deliveryMario->setPickupPoint($pointOne);
        $manager->persist($deliveryMario);

        $token = new PickupToken($participationMario, hash('sha256', 'pickup-fixture-mario'), 2);
        $manager->persist($token);

        $deliveryGiulia = new Delivery($participationGiulia, DeliveryMode::ConsegnaDomicilio);
        $deliveryGiulia->setDeliveryAddress('Via Verdi 10, Bologna');
        $deliveryGiulia->setDeliveryCostCents(590);
        $manager->persist($deliveryGiulia);

        $order = new SupplierOrder($campaignTwo, $supplierTwo, '2026-000001', 2026, 22, 72380);
        $order->setAdmin($admin);
        $manager->persist($order);

        $manager->persist(new ProductLike($mario, $zaino));
        $manager->persist(new ProductLike($luigi, $zaino));
        $manager->persist(new ProductLike($giulia, $padella));

        $notification = new Notification($mario, NotificationType::MoqRaggiunto, 'MOQ raggiunto', 'La campagna ha raggiunto la quantità minima.');
        $notification->setReference(ReferenceType::Colletta, $campaignOne->getId()->toRfc4122());
        $manager->persist($notification);

        $manager->persist(new Notification($luigi, NotificationType::Sistema, 'Benvenuto su BuyPool', 'Il tuo account è attivo.'));

        $manager->flush();
    }

    private function user(string $firstName, string $lastName, string $email, UserRole $role): User
    {
        $user = new User($firstName, $email);
        $user->setLastName($lastName);
        $user->setRole($role);
        $user->setHashedPassword($this->passwordHasher->hashPassword($user, self::DEV_PASSWORD));
        $user->setPrivacyConsentedAt(new \DateTimeImmutable());

        return $user;
    }
}
