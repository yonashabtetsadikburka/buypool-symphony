<?php

namespace App\Tests\Unit\Campaign;

use App\Campaign\Entity\Campaign;
use App\Campaign\Enum\CampaignStatus;
use App\Campaign\Enum\CampaignTransition;
use App\Supplier\Entity\SupplierOrder;
use App\Supplier\Enum\SupplierOrderStatus;
use App\Supplier\Repository\SupplierOrderRepository;
use App\Tests\Support\CampaignWorkflowFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CampaignStateTransitionMatrixTest extends TestCase
{
    /**
     * @return \Generator<string, array{CampaignStatus, CampaignTransition, int, int, SupplierOrderStatus|null, bool}>
     */
    public static function transitionMatrix(): \Generator
    {
        $evaso = SupplierOrderStatus::Evaso;
        $inviato = SupplierOrderStatus::Inviato;

        // in_corso
        yield 'a running campaign becomes successful once the minimum order quantity is reached' => [CampaignStatus::InCorso, CampaignTransition::ReachMoq, 10, 30, null, true];
        yield 'a running campaign stays running while the minimum order quantity is not reached' => [CampaignStatus::InCorso, CampaignTransition::ReachMoq, 9, 30, null, false];
        yield 'a running campaign fails once the deadline has passed below the minimum order quantity' => [CampaignStatus::InCorso, CampaignTransition::FailOnDeadline, 9, -1, null, true];
        yield 'a running campaign that holds the minimum order quantity never fails on deadline' => [CampaignStatus::InCorso, CampaignTransition::FailOnDeadline, 10, -1, null, false];
        yield 'a running campaign does not fail before its deadline' => [CampaignStatus::InCorso, CampaignTransition::FailOnDeadline, 9, 1, null, false];
        yield 'a running campaign cannot lose a minimum order quantity it never reached' => [CampaignStatus::InCorso, CampaignTransition::LostMoq, 9, 30, null, false];
        yield 'a running campaign is never allocated before becoming successful' => [CampaignStatus::InCorso, CampaignTransition::Allocate, 10, 30, null, false];
        yield 'a running campaign is never sent to the supplier' => [CampaignStatus::InCorso, CampaignTransition::SendToSupplier, 10, 30, null, false];
        yield 'a running campaign is never delivered' => [CampaignStatus::InCorso, CampaignTransition::Deliver, 10, 30, $evaso, false];
        yield 'a running campaign can be cancelled by an administrator' => [CampaignStatus::InCorso, CampaignTransition::Cancel, 0, 30, null, true];

        // riuscita
        yield 'a successful campaign cannot reach the minimum order quantity twice' => [CampaignStatus::Riuscita, CampaignTransition::ReachMoq, 10, 30, null, false];
        yield 'a successful campaign fails when the deadline passes below the minimum order quantity' => [CampaignStatus::Riuscita, CampaignTransition::FailOnDeadline, 9, -1, null, true];
        yield 'a successful campaign that still holds the minimum order quantity never fails on deadline' => [CampaignStatus::Riuscita, CampaignTransition::FailOnDeadline, 10, -1, null, false];
        yield 'a successful campaign does not fail before its deadline' => [CampaignStatus::Riuscita, CampaignTransition::FailOnDeadline, 9, 1, null, false];
        yield 'a successful campaign returns to running when the minimum order quantity is lost while still open' => [CampaignStatus::Riuscita, CampaignTransition::LostMoq, 9, 30, null, true];
        yield 'a successful campaign keeps its status while the minimum order quantity holds' => [CampaignStatus::Riuscita, CampaignTransition::LostMoq, 10, 30, null, false];
        yield 'a successful campaign past its deadline fails instead of returning to running' => [CampaignStatus::Riuscita, CampaignTransition::LostMoq, 9, -1, null, false];
        yield 'a successful campaign is allocated for the supplier order' => [CampaignStatus::Riuscita, CampaignTransition::Allocate, 10, 30, null, true];
        yield 'a successful campaign is never sent to the supplier before allocation' => [CampaignStatus::Riuscita, CampaignTransition::SendToSupplier, 10, 30, null, false];
        yield 'a successful campaign is never delivered before being sent to the supplier' => [CampaignStatus::Riuscita, CampaignTransition::Deliver, 10, 30, $evaso, false];
        yield 'a successful campaign can be cancelled by an administrator' => [CampaignStatus::Riuscita, CampaignTransition::Cancel, 10, 30, null, true];

        // fallita
        yield 'a failed campaign can be cancelled by an administrator' => [CampaignStatus::Fallita, CampaignTransition::Cancel, 0, -1, null, true];
        yield 'a failed campaign never reaches the minimum order quantity again' => [CampaignStatus::Fallita, CampaignTransition::ReachMoq, 10, 30, null, false];
        yield 'a failed campaign never fails twice' => [CampaignStatus::Fallita, CampaignTransition::FailOnDeadline, 0, -1, null, false];
        yield 'a failed campaign never loses a quantity it no longer holds' => [CampaignStatus::Fallita, CampaignTransition::LostMoq, 0, 30, null, false];
        yield 'a failed campaign is never allocated' => [CampaignStatus::Fallita, CampaignTransition::Allocate, 10, -1, null, false];
        yield 'a failed campaign is never sent to the supplier' => [CampaignStatus::Fallita, CampaignTransition::SendToSupplier, 10, -1, null, false];
        yield 'a failed campaign is never delivered' => [CampaignStatus::Fallita, CampaignTransition::Deliver, 10, -1, $evaso, false];

        // ordine_pronto
        yield 'an allocated campaign is sent to the supplier' => [CampaignStatus::OrdinePronto, CampaignTransition::SendToSupplier, 10, -1, null, true];
        yield 'an allocated campaign can be cancelled by an administrator' => [CampaignStatus::OrdinePronto, CampaignTransition::Cancel, 10, -1, null, true];
        yield 'an allocated campaign is never allocated twice' => [CampaignStatus::OrdinePronto, CampaignTransition::Allocate, 10, -1, null, false];
        yield 'an allocated campaign never goes back to running' => [CampaignStatus::OrdinePronto, CampaignTransition::LostMoq, 0, 30, null, false];
        yield 'an allocated campaign never fails on deadline' => [CampaignStatus::OrdinePronto, CampaignTransition::FailOnDeadline, 0, -1, null, false];
        yield 'an allocated campaign cannot reach the minimum order quantity again' => [CampaignStatus::OrdinePronto, CampaignTransition::ReachMoq, 10, 30, null, false];
        yield 'an allocated campaign is never delivered before the supplier fulfils it' => [CampaignStatus::OrdinePronto, CampaignTransition::Deliver, 10, -1, $evaso, false];

        // ordine_fornitore
        yield 'a campaign is delivered once its supplier order is fulfilled' => [CampaignStatus::OrdineFornitore, CampaignTransition::Deliver, 10, -1, $evaso, true];
        yield 'a campaign is never delivered without a supplier order' => [CampaignStatus::OrdineFornitore, CampaignTransition::Deliver, 10, -1, null, false];
        yield 'a campaign is never delivered while its supplier order is still open' => [CampaignStatus::OrdineFornitore, CampaignTransition::Deliver, 10, -1, $inviato, false];
        yield 'a campaign with an open supplier order can be cancelled by an administrator' => [CampaignStatus::OrdineFornitore, CampaignTransition::Cancel, 10, -1, $inviato, true];
        yield 'a campaign with a supplier order is never sent to the supplier twice' => [CampaignStatus::OrdineFornitore, CampaignTransition::SendToSupplier, 10, -1, $inviato, false];
        yield 'a campaign with a supplier order is never allocated twice' => [CampaignStatus::OrdineFornitore, CampaignTransition::Allocate, 10, -1, $inviato, false];
        yield 'a campaign with a supplier order never goes back to running' => [CampaignStatus::OrdineFornitore, CampaignTransition::LostMoq, 0, 30, $inviato, false];
        yield 'a campaign with a supplier order never fails on deadline' => [CampaignStatus::OrdineFornitore, CampaignTransition::FailOnDeadline, 0, -1, $inviato, false];
        yield 'a campaign with a supplier order cannot reach the minimum order quantity again' => [CampaignStatus::OrdineFornitore, CampaignTransition::ReachMoq, 10, 30, $inviato, false];

        // consegnata
        yield 'a delivered campaign can be cancelled by an administrator' => [CampaignStatus::Consegnata, CampaignTransition::Cancel, 10, -1, $evaso, true];
        yield 'a delivered campaign is never delivered twice' => [CampaignStatus::Consegnata, CampaignTransition::Deliver, 10, -1, $evaso, false];
        yield 'a delivered campaign is never sent to the supplier again' => [CampaignStatus::Consegnata, CampaignTransition::SendToSupplier, 10, -1, $evaso, false];
        yield 'a delivered campaign is never allocated again' => [CampaignStatus::Consegnata, CampaignTransition::Allocate, 10, -1, $evaso, false];
        yield 'a delivered campaign never goes back to running' => [CampaignStatus::Consegnata, CampaignTransition::LostMoq, 0, 30, $evaso, false];
        yield 'a delivered campaign never fails on deadline' => [CampaignStatus::Consegnata, CampaignTransition::FailOnDeadline, 0, -1, $evaso, false];
        yield 'a delivered campaign cannot reach the minimum order quantity again' => [CampaignStatus::Consegnata, CampaignTransition::ReachMoq, 10, 30, $evaso, false];

        // annullata
        yield 'a cancelled campaign is never restarted' => [CampaignStatus::Annullata, CampaignTransition::ReachMoq, 10, 30, null, false];
        yield 'a cancelled campaign never fails' => [CampaignStatus::Annullata, CampaignTransition::FailOnDeadline, 0, -1, null, false];
        yield 'a cancelled campaign never loses a quantity' => [CampaignStatus::Annullata, CampaignTransition::LostMoq, 0, 30, null, false];
        yield 'a cancelled campaign is never allocated' => [CampaignStatus::Annullata, CampaignTransition::Allocate, 10, 30, null, false];
        yield 'a cancelled campaign is never sent to the supplier' => [CampaignStatus::Annullata, CampaignTransition::SendToSupplier, 10, 30, null, false];
        yield 'a cancelled campaign is never delivered' => [CampaignStatus::Annullata, CampaignTransition::Deliver, 10, 30, $evaso, false];
        yield 'a cancelled campaign is never cancelled twice' => [CampaignStatus::Annullata, CampaignTransition::Cancel, 0, 30, null, false];
    }

    #[DataProvider('transitionMatrix')]
    public function testOnlyTheLifecycleTransitionsAreAllowedFromEachCampaignStatus(
        CampaignStatus $status,
        CampaignTransition $transition,
        int $quantity,
        int $daysToDeadline,
        ?SupplierOrderStatus $orderStatus,
        bool $expectedAllowed,
    ): void {
        $repository = $this->createStub(SupplierOrderRepository::class);
        $repository->method('findOneBy')->willReturn(
            null === $orderStatus
                ? null
                : CampaignWorkflowFactory::createSupplierOrder(
                    CampaignWorkflowFactory::createCampaign(CampaignStatus::OrdineFornitore, 10, -1),
                    $orderStatus,
                ),
        );

        $workflow = CampaignWorkflowFactory::createStateMachine($repository);
        $campaign = CampaignWorkflowFactory::createCampaign($status, $quantity, $daysToDeadline);

        self::assertSame($expectedAllowed, $workflow->can($campaign, $transition->value));
    }

    public function testTheStateMachineModelsExactlyTheLiveCampaignStates(): void
    {
        $repository = $this->createStub(SupplierOrderRepository::class);
        $definition = CampaignWorkflowFactory::createStateMachine($repository)->getDefinition();

        $enumValues = array_map(static fn (CampaignStatus $status): string => $status->value, CampaignStatus::cases());
        $places = array_keys($definition->getPlaces());

        sort($enumValues);
        sort($places);

        self::assertSame($enumValues, $places);

        foreach ($definition->getTransitions() as $transition) {
            foreach ((array) $transition->getTos() as $to) {
                self::assertContains((string) $to, $enumValues);
            }
            foreach ((array) $transition->getFroms() as $from) {
                self::assertContains((string) $from, $enumValues);
            }
        }
    }
}
