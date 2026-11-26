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

use Propel\Runtime\Connection\ConnectionInterface;
use Propel\Runtime\Propel;
use Referral\Reward\RewardFactory;
use Referral\Reward\SponsorRewardEngine;

/**
 * Reads, in ONE query, the referees of a sponsor with their reward and the state of its coupon: the cost of the
 * account page does not grow with the number of referees.
 */
final readonly class ReferralAccountReader
{
    public const STATE_SIGNED_UP = 'signed_up';
    public const STATE_PENDING = 'pending';

    private const SQL = <<<'SQL'
        SELECT
            r.status,
            r.created_at,
            c.firstname,
            c.lastname,
            EXISTS (
                SELECT 1 FROM `order` o
                JOIN `order_status` os ON os.id = o.status_id
                WHERE o.customer_id = r.referee_customer_id
                  AND os.code NOT IN ('canceled', 'refunded')
                  AND COALESCE(os.equivalent_code, '') NOT IN ('canceled', 'refunded')
            ) AS has_order,
            rw.status AS reward_status,
            rw.value_type,
            rw.value,
            rw.coupon_code,
            cp.expiration_date,
            (COALESCE(cp.is_used, 0) = 1 OR COALESCE(cc.count, 0) > 0) AS used
        FROM `referral` r
        JOIN `customer` c ON c.id = r.referee_customer_id
        LEFT JOIN `referral_reward` rw ON rw.referral_id = r.id AND rw.beneficiary = :beneficiary
        LEFT JOIN `coupon` cp ON cp.id = rw.coupon_id
        LEFT JOIN `coupon_customer_count` cc ON cc.coupon_id = cp.id AND cc.customer_id = :sponsor
        WHERE r.sponsor_customer_id = :sponsor
        ORDER BY r.created_at DESC, r.id DESC
        SQL;

    public function read(int $sponsorCustomerId, string $code, string $link, \DateTimeImmutable $now, ?ConnectionInterface $connection = null): ReferralAccountView
    {
        $statement = ($connection ?? Propel::getConnection())->prepare(self::SQL);
        $statement->execute(['beneficiary' => RewardFactory::BENEFICIARY_SPONSOR, 'sponsor' => $sponsorCustomerId]);

        $referees = [];
        $rewards = [];
        $amountTotal = 0.0;
        $percentCounts = [];

        /** @var array<string, string|null> $row */
        foreach ($statement->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $referees[] = [
                'name' => $this->nameOf((string) $row['firstname'], (string) $row['lastname']),
                'signedUpAt' => $this->dateOf($row['created_at']),
                'state' => $this->stateOf((string) $row['status'], (bool) $row['has_order']),
            ];

            if (null === $row['coupon_code'] || 'issued' !== $row['reward_status']) {
                continue;
            }

            $value = (float) $row['value'];
            $expiresAt = $this->dateOf($row['expiration_date']);
            $rewards[] = [
                'code' => (string) $row['coupon_code'],
                'valueType' => (string) $row['value_type'],
                'value' => $value,
                'expiresAt' => $expiresAt,
                'state' => (bool) $row['used'] ? 'used' : (null !== $expiresAt && $expiresAt <= $now ? 'expired' : 'unused'),
            ];

            if ('percent' === $row['value_type']) {
                $key = (string) $value;
                $percentCounts[$key] = ['value' => $value, 'count' => ($percentCounts[$key]['count'] ?? 0) + 1];
            } else {
                $amountTotal += $value;
            }
        }

        return new ReferralAccountView($code, $link, $referees, $rewards, round($amountTotal, 2), array_values($percentCounts));
    }

    /**
     * Enough for the sponsor to recognise a friend, not enough to identify a customer to a stranger's eyes.
     */
    private function nameOf(string $firstname, string $lastname): string
    {
        $initial = '' === $lastname ? '' : ' '.mb_strtoupper(mb_substr($lastname, 0, 1)).'.';

        return $firstname.$initial;
    }

    private function stateOf(string $referralStatus, bool $hasOrder): string
    {
        return match ($referralStatus) {
            SponsorRewardEngine::STATUS_REWARDED => 'rewarded',
            SponsorRewardEngine::STATUS_CAPPED => 'capped',
            SponsorRewardEngine::STATUS_CANCELLED => 'cancelled',
            default => $hasOrder ? self::STATE_PENDING : self::STATE_SIGNED_UP,
        };
    }

    private function dateOf(?string $value): ?\DateTimeImmutable
    {
        return null === $value ? null : new \DateTimeImmutable($value);
    }
}
