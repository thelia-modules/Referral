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

/**
 * The welcome coupon of a customer who signed up with a sponsor's code, while it can still be used.
 * One lookup on the index of the reward table (customer, beneficiary); a customer who is no referee costs that and nothing else.
 */
final readonly class RefereeWelcomeReader
{
    private const SQL = <<<'SQL'
        SELECT
            rw.coupon_code,
            rw.value_type,
            rw.value,
            rw.minimum_amount,
            cp.expiration_date,
            cp.is_enabled,
            (COALESCE(cp.is_used, 0) = 1 OR COALESCE(cc.count, 0) > 0) AS used
        FROM `referral_reward` rw
        JOIN `coupon` cp ON cp.id = rw.coupon_id
        LEFT JOIN `coupon_customer_count` cc ON cc.coupon_id = cp.id AND cc.customer_id = :customer
        WHERE rw.customer_id = :customer AND rw.beneficiary = :beneficiary AND rw.status = 'issued'
        LIMIT 1
        SQL;

    public function find(int $customerId, \DateTimeImmutable $now, ?ConnectionInterface $connection = null): ?RefereeWelcome
    {
        $statement = ($connection ?? Propel::getConnection())->prepare(self::SQL);
        $statement->execute(['customer' => $customerId, 'beneficiary' => RewardFactory::BENEFICIARY_REFEREE]);

        /** @var array<string, string|null>|false $row */
        $row = $statement->fetch(\PDO::FETCH_ASSOC);

        if (false === $row || null === $row['coupon_code'] || null === $row['expiration_date'] || !(bool) $row['is_enabled'] || (bool) $row['used']) {
            return null;
        }

        $expiresAt = new \DateTimeImmutable($row['expiration_date']);

        if ($expiresAt <= $now) {
            return null;
        }

        return new RefereeWelcome(
            (string) $row['coupon_code'],
            (string) $row['value_type'],
            (float) $row['value'],
            (float) $row['minimum_amount'],
            $expiresAt,
        );
    }
}
