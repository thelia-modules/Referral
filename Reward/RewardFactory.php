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

use Referral\Model\Referral as ReferralModel;
use Referral\Model\ReferralReward;
use Referral\Referral;
use Referral\Service\ReferralSettings;

/**
 * Builds the rewards of a referral from the settings of the moment. The rows are not saved.
 */
final readonly class RewardFactory
{
    public const BENEFICIARY_REFEREE = 'referee';
    public const BENEFICIARY_SPONSOR = 'sponsor';

    public function __construct(private ReferralSettings $settings)
    {
    }

    public function referee(ReferralModel $referral): ReferralReward
    {
        return $this->build(
            $referral,
            self::BENEFICIARY_REFEREE,
            $referral->getRefereeCustomerId(),
            Referral::CONFIG_REFEREE_VALUE_TYPE,
            Referral::CONFIG_REFEREE_VALUE,
            Referral::CONFIG_REFEREE_MINIMUM_AMOUNT,
        );
    }

    public function sponsor(ReferralModel $referral): ReferralReward
    {
        return $this->build(
            $referral,
            self::BENEFICIARY_SPONSOR,
            $referral->getSponsorCustomerId(),
            Referral::CONFIG_SPONSOR_VALUE_TYPE,
            Referral::CONFIG_SPONSOR_VALUE,
            Referral::CONFIG_SPONSOR_MINIMUM_AMOUNT,
        );
    }

    private function build(ReferralModel $referral, string $beneficiary, ?int $customerId, string $typeKey, string $valueKey, string $minimumKey): ReferralReward
    {
        return (new ReferralReward())
            ->setReferralId($referral->getId())
            ->setBeneficiary($beneficiary)
            ->setCustomerId($customerId)
            ->setRewardType('coupon')
            ->setValueType($this->settings->get($typeKey))
            ->setValue($this->settings->get($valueKey))
            ->setMinimumAmount($this->settings->get($minimumKey))
            ->setStatus('issued');
    }
}
