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

namespace Referral\Privacy;

use Referral\Model\ReferralCodeQuery;
use Referral\Model\ReferralQuery;
use Referral\Model\ReferralRewardQuery;
use Thelia\Domain\Customer\Service\CustomerPersonalDataProviderInterface;
use Thelia\Model\Customer;
use Thelia\Model\CustomerQuery;

/**
 * The referral data of a customer, in the personal data export and the anonymization of the core.
 *
 * Export: the customer's code, the friends they sponsored (first name only: a friend's last name is
 * theirs), their own sign-up as a referee, and their rewards.
 *
 * Anonymization: as a sponsor, the code is deactivated, so nobody can sign up with it any more (the rewards
 * of the friends already referred stay valid and untouched). As a referee, the referral row stays: it holds
 * ids, a status and dates, nothing that names the customer once the account is anonymized, and it is what the
 * statistics of the sponsor are made of.
 */
final readonly class ReferralPersonalDataProvider implements CustomerPersonalDataProviderInterface
{
    public const SECTION = 'referral';

    public function getPersonalDataSectionName(): string
    {
        return self::SECTION;
    }

    /**
     * @return array<string, mixed>
     */
    public function exportPersonalData(Customer $customer): array
    {
        $customerId = (int) $customer->getId();
        $code = ReferralCodeQuery::create()->findOneByCustomerId($customerId);
        $referralAsReferee = ReferralQuery::create()->findOneByRefereeCustomerId($customerId);

        $sponsored = ReferralQuery::create()->filterBySponsorCustomerId($customerId)->orderById()->find();
        $firstnames = [];
        foreach (CustomerQuery::create()->filterById(array_map(static fn ($referral): int => (int) $referral->getRefereeCustomerId(), iterator_to_array($sponsored)))->find() as $referee) {
            $firstnames[(int) $referee->getId()] = $referee->getFirstname();
        }

        $referees = [];
        foreach ($sponsored as $referral) {
            $referees[] = [
                'referee_firstname' => $firstnames[(int) $referral->getRefereeCustomerId()] ?? null,
                'signed_up_at' => $this->date($referral->getCreatedAt()),
                'status' => $referral->getStatus(),
                'status_reason' => $referral->getStatusReason(),
            ];
        }

        $rewards = [];
        foreach (ReferralRewardQuery::create()->filterByCustomerId($customerId)->orderById()->find() as $reward) {
            $rewards[] = [
                'beneficiary' => $reward->getBeneficiary(),
                'coupon_code' => $reward->getCouponCode(),
                'value_type' => $reward->getValueType(),
                'value' => $reward->getValue(),
                'status' => $reward->getStatus(),
                'cancel_reason' => $reward->getCancelReason(),
                'created_at' => $this->date($reward->getCreatedAt()),
            ];
        }

        return [
            'code' => null === $code ? null : ['code' => $code->getCode(), 'active' => (bool) $code->getActive()],
            'as_sponsor' => $referees,
            'as_referee' => null === $referralAsReferee ? null : [
                'signed_up_at' => $this->date($referralAsReferee->getCreatedAt()),
                'status' => $referralAsReferee->getStatus(),
                'status_reason' => $referralAsReferee->getStatusReason(),
            ],
            'rewards' => $rewards,
        ];
    }

    public function anonymizePersonalData(Customer $customer): void
    {
        $code = ReferralCodeQuery::create()->findOneByCustomerId((int) $customer->getId());

        if (null !== $code && $code->getActive()) {
            $code->setActive(0)->save();
        }
    }

    private function date(mixed $date): ?string
    {
        return $date instanceof \DateTimeInterface ? $date->format(\DATE_ATOM) : null;
    }
}
