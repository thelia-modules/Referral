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

namespace Referral\Reward;

use Referral\Model\ReferralReward;
use Thelia\Model\Customer;

interface RewardIssuerInterface
{
    public function supports(string $rewardType): bool;

    /** Fills coupon_id and coupon_code, then saves the reward. */
    public function issue(ReferralReward $reward, Customer $beneficiary): void;

    /** Disables the coupon if it was never used, and keeps the row. */
    public function revoke(ReferralReward $reward): void;
}
