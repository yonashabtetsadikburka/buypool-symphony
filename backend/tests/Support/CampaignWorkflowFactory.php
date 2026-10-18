<?php

namespace App\Tests\Support;

use App\Campaign\Entity\Campaign;
use App\Campaign\Entity\Product;
use App\Campaign\Enum\CampaignStatus;
use App\Campaign\Workflow\CampaignGuards;
use App\Supplier\Entity\Supplier;
use App\Supplier\Entity\SupplierOrder;
use App\Supplier\Enum\SupplierOrderStatus;
use App\Supplier\Repository\SupplierOrderRepository;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Workflow\Definition;
use Symfony\Component\Workflow\MarkingStore\MethodMarkingStore;
use Symfony\Component\Workflow\Registry;
use Symfony\Component\Workflow\StateMachine;
use Symfony\Component\Workflow\SupportStrategy\InstanceOfSupportStrategy;
use Symfony\Component\Workflow\Transition;
use Symfony\Component\Yaml\Yaml;

/**
 * Builds the campaign state machine from config/packages/workflow.yaml, so the
 * unit tests always exercise the real definition instead of a duplicated one.
 */
final class CampaignWorkflowFactory
{
    public const MOQ = 10;

    public static function createStateMachine(SupplierOrderRepository $supplierOrderRepository): StateMachine
    {
        $config = Yaml::parseFile(dirname(__DIR__, 2).'/config/packages/workflow.yaml');
        $campaignConfig = $config['framework']['workflows']['campaign'] ?? null;

        if (!\is_array($campaignConfig)
            || !\is_array($campaignConfig['places'] ?? null)
            || !\is_array($campaignConfig['transitions'] ?? null)
        ) {
            throw new \RuntimeException('The campaign workflow could not be read from config/packages/workflow.yaml.');
        }

        // FrameworkBundle expands a state machine transition with several "from"
        // places into one Transition per from->to pair; mirror that here so the
        // unit tests exercise the same definition as the container.
        $transitions = [];
        foreach ($campaignConfig['transitions'] as $name => $transitionConfig) {
            foreach ((array) $transitionConfig['from'] as $from) {
                foreach ((array) $transitionConfig['to'] as $to) {
                    $transitions[] = new Transition((string) $name, [(string) $from], [(string) $to]);
                }
            }
        }

        $definition = new Definition($campaignConfig['places'], $transitions);

        $dispatcher = new EventDispatcher();
        $dispatcher->addSubscriber(new CampaignGuards($supplierOrderRepository));

        return new StateMachine(
            $definition,
            new MethodMarkingStore(singleState: true, property: 'status'),
            $dispatcher,
            'campaign',
        );
    }

    public static function createRegistry(SupplierOrderRepository $supplierOrderRepository): Registry
    {
        $registry = new Registry();
        $registry->addWorkflow(
            self::createStateMachine($supplierOrderRepository),
            new InstanceOfSupportStrategy(Campaign::class),
        );

        return $registry;
    }

    public static function createCampaign(CampaignStatus $status, int $quantity, int $daysToDeadline): Campaign
    {
        $supplier = new Supplier('Example Supplier');
        $product = new Product($supplier, 'Example Product', 1000);
        $campaign = new Campaign(
            $product,
            self::MOQ,
            new \DateTimeImmutable(sprintf('%+d days', $daysToDeadline)),
            1000,
            900,
        );
        $campaign->setStatus($status);
        $campaign->setCurrentQuantity($quantity);

        return $campaign;
    }

    public static function createSupplierOrder(Campaign $campaign, SupplierOrderStatus $status): SupplierOrder
    {
        $order = new SupplierOrder(
            $campaign,
            $campaign->getProduct()->getSupplier(),
            '2026-000001',
            2026,
            self::MOQ,
            9000,
        );
        $order->setStatus($status);

        return $order;
    }
}
