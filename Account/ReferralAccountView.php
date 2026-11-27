<?php

declare(strict_types=1);

/*
 * This file is part of the Thelia package.
 * http://www.thelia.net
 *
 * (c) OpenStudio <info@thelia.net>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Referral\Account;

/**
 * What the account page shows a sponsor, already shaped: no model, no query left for the template.
 *
 * A referee's `state` is one of `signed_up`, `pending`, `rewarded`, `capped`, `cancelled`.
 * `amountTotal` sums the reward coupons worth an amount, `percentCounts` counts the ones worth a percentage by value.
 */
final readonly class ReferralAccountView
{
    /**
     * @param list<array{name: string, signedUpAt: \DateTimeImmutable|null, state: string}>                                 $referees
     * @param list<array{code: string, valueType: string, value: float, expiresAt: \DateTimeImmutable|null, state: string}> $rewards       state: `unused`, `used` or `expired`
     * @param list<array{value: float, count: int}>                                                                         $percentCounts
     */
    public function __construct(
        public string $code,
        public string $link,
        public array $referees,
        public array $rewards,
        public float $amountTotal,
        public array $percentCounts,
    ) {
    }
}
