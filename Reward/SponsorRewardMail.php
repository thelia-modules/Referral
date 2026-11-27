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

use Referral\Model\ReferralQuery;
use Referral\Model\ReferralReward;
use Referral\Referral;
use Thelia\Mailer\MailerFactory;
use Thelia\Model\CouponQuery;
use Thelia\Model\Currency;
use Thelia\Model\Customer;
use Thelia\Model\CustomerQuery;

/**
 * Tells the sponsor their reward is there. Sent once the reward is committed, never inside the
 * transaction that issued it: a failed mail is logged by the core and takes nothing back.
 */
final readonly class SponsorRewardMail
{
    public function __construct(private MailerFactory $mailer)
    {
    }

    public function send(ReferralReward $reward): void
    {
        $sponsorId = $reward->getCustomerId();
        $sponsor = null === $sponsorId ? null : CustomerQuery::create()->findPk($sponsorId);
        if (!$sponsor instanceof Customer) {
            return;
        }

        $referral = ReferralQuery::create()->findPk($reward->getReferralId());
        $referee = null === $referral ? null : CustomerQuery::create()->findPk($referral->getRefereeCustomerId());
        $coupon = null === $reward->getCouponId() ? null : CouponQuery::create()->findPk($reward->getCouponId());

        $this->mailer->sendEmailToCustomer(Referral::MESSAGE_SPONSOR_REWARD, $sponsor, [
            'referee_firstname' => $referee?->getFirstname() ?? '',
            'coupon_code' => (string) $reward->getCouponCode(),
            'reward_value' => (string) $reward->getValue(),
            'reward_value_type' => $reward->getValueType(),
            'minimum_amount' => (string) $reward->getMinimumAmount(),
            'expiration_date' => $coupon?->getExpirationDate(),
            'currency_id' => Currency::getDefaultCurrency()->getId(),
        ]);
    }
}
