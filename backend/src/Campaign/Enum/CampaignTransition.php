<?php

namespace App\Campaign\Enum;

enum CampaignTransition: string
{
    case ReachMoq = 'reach_moq';
    case FailOnDeadline = 'fail_on_deadline';
    case LostMoq = 'lost_moq';
    case Allocate = 'allocate';
    case SendToSupplier = 'send_to_supplier';
    case Deliver = 'deliver';
    case Cancel = 'cancel';
}
