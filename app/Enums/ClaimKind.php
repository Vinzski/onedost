<?php

namespace App\Enums;

use Illuminate\Routing\Route;

/**
 * A travel claim after the trip. Both share the same papers; a liquidation
 * settles a cash advance (and adds the Liquidation Report), a reimbursement
 * claims back money the traveller spent from their own pocket.
 */
enum ClaimKind: string
{
    case Liquidation = 'liquidation';
    case Reimbursement = 'reimbursement';

    /** Route name prefix and URL segment: "liquidations" / "reimbursements". */
    public function routePrefix(): string
    {
        return $this->value.'s';
    }

    public function label(): string
    {
        return match ($this) {
            self::Liquidation => 'Liquidation',
            self::Reimbursement => 'Reimbursement',
        };
    }

    /** The kind a request is for, from its route name ("reimbursements.edit" → Reimbursement). */
    public static function fromRoute(?Route $route): self
    {
        return str_starts_with((string) $route?->getName(), self::Reimbursement->routePrefix().'.')
            ? self::Reimbursement
            : self::Liquidation;
    }
}
