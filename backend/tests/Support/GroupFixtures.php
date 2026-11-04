<?php

namespace App\Tests\Support;

use App\Campaign\Entity\Campaign;
use App\Campaign\Entity\PriceTier;
use App\Campaign\Entity\Product;
use App\Campaign\Enum\CampaignStatus;
use App\Delivery\Entity\Delivery;
use App\Delivery\Entity\PickupPoint;
use App\Delivery\Enum\DeliveryMode;
use App\Identity\Entity\User;
use App\Participation\Entity\Participation;
use App\Participation\Enum\ParticipationStatus;
use App\Payment\Entity\Payment;
use App\Payment\Enum\PaymentStatus;
use App\Payment\Enum\PaymentType;
use App\Supplier\Entity\Supplier;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Dati di prova per i test su chiusura, rimborsi e saldi. Chi lo usa ha un `$this->em` e chiama `startFixtures()`
 * dopo aver aperto la transazione che annulla tutto a fine test.
 */
trait GroupFixtures
{
    private Supplier $supplier;
    private PickupPoint $pickupPoint;
    private int $fixtureCounter = 0;

    private function startFixtures(EntityManagerInterface $em): void
    {
        $this->supplier = new Supplier('Fornitore chiusura test');
        $this->pickupPoint = new PickupPoint('Sede chiusura test', 'Via Prova 1', 'Perugia');
        $em->persist($this->supplier);
        $em->persist($this->pickupPoint);
        $em->flush();
    }

    /**
     * Gruppo con scaglioni 10 → 900 e 20 → 800 (o quelli dati), commissione 10%.
     *
     * @param array<int, int>|null $tiers soglia → prezzo in centesimi
     */
    private function group(EntityManagerInterface $em, int $quantity, string $deadline = '-1 hour', ?array $tiers = null, CampaignStatus $status = CampaignStatus::InCorso): Campaign
    {
        $tiers ??= [10 => 900, 20 => 800];
        $product = (new Product($this->supplier, 'Prodotto '.++$this->fixtureCounter, 1000))->setBasePriceCents(1200);
        $campaign = new Campaign($product, (int) array_key_first($tiers), new \DateTimeImmutable($deadline), 1200, 1000);
        $campaign->setPickupPoint($this->pickupPoint)->setCurrentQuantity($quantity)->setStatus($status);
        foreach ($tiers as $threshold => $price) {
            $campaign->addPriceTier(new PriceTier($campaign, $threshold, $price));
        }
        $em->persist($product);
        $em->persist($campaign);
        $em->flush();

        return $campaign;
    }

    /**
     * Cliente con la sua adesione e l'acconto già pagato (PaymentIntent "pi_dep_N", carta "pm_N").
     */
    private function member(EntityManagerInterface $em, Campaign $campaign, ParticipationStatus $status, int $quantity = 3, int $deposit = 540, bool $home = false, bool $withCard = true): Participation
    {
        $n = ++$this->fixtureCounter;
        $user = new User('Cliente', 'm'.$n.'-'.bin2hex(random_bytes(3)).'@closure-test.example');
        if ($withCard) {
            $user->setStripeCustomerId('cus_'.$n);
        }
        $participation = (new Participation($campaign, $user, $quantity))->setDepositCents($deposit)->setStatus($status);
        $delivery = new Delivery($participation, $home ? DeliveryMode::ConsegnaDomicilio : DeliveryMode::RitiroSede);
        $delivery->setDeliveryCostCents($home ? 490 : 0);
        $payment = (new Payment($participation, PaymentType::Acconto, $deposit))
            ->setStripePaymentIntentId('pi_dep_'.$n)
            ->setStripePaymentMethod($withCard ? 'pm_'.$n : null)
            ->setStatus(PaymentStatus::Confermato)
            ->setConfirmedAt(new \DateTimeImmutable());
        $em->persist($user);
        $em->persist($participation);
        $em->persist($delivery);
        $em->persist($payment);
        $em->flush();

        return $participation;
    }
}
