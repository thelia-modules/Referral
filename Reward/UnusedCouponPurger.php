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

use Propel\Runtime\Connection\ConnectionInterface;
use Propel\Runtime\Propel;
use Referral\Model\Map\ReferralRewardTableMap;
use Referral\Service\ReferralClock;

/**
 * Deletes the coupons the module issued that nobody used: the expired ones on a schedule, all of them when
 * the module is deleted. A coupon that was used stays, since the orders that carry its code mention it.
 *
 * A reward keeps its `coupon_code` when its coupon goes: the foreign key sets `coupon_id` to null.
 */
final readonly class UnusedCouponPurger
{
    public const BATCH_SIZE = 200;

    private const UNUSED_COUPON_OF_THE_MODULE = <<<'SQL'
        SELECT DISTINCT cp.id
        FROM `coupon` cp
        JOIN `referral_reward` rw ON rw.coupon_id = cp.id
        WHERE cp.is_used = 0
          AND NOT EXISTS (SELECT 1 FROM `coupon_customer_count` cc WHERE cc.coupon_id = cp.id)
        SQL;

    public function __construct(private ReferralClock $clock)
    {
    }

    /**
     * @return int the number of coupons deleted, or that would be with $dryRun
     */
    public function purgeExpired(bool $dryRun = false, int $batchSize = self::BATCH_SIZE): int
    {
        $connection = Propel::getWriteConnection(ReferralRewardTableMap::DATABASE_NAME);
        $now = $this->clock->nowToTheSecond()->format('Y-m-d H:i:s');
        $select = self::UNUSED_COUPON_OF_THE_MODULE.' AND cp.expiration_date < :now';

        if ($dryRun) {
            $statement = $connection->prepare('SELECT COUNT(*) FROM ('.$select.') unused');
            $statement->execute(['now' => $now]);

            return (int) $statement->fetchColumn();
        }

        $deleted = 0;
        do {
            $statement = $connection->prepare($select.' ORDER BY cp.id LIMIT '.max(1, $batchSize));
            $statement->execute(['now' => $now]);
            $ids = array_map('intval', $statement->fetchAll(\PDO::FETCH_COLUMN));

            if ([] !== $ids) {
                $connection->exec('DELETE FROM `coupon` WHERE id IN ('.implode(',', $ids).')');
                $deleted += \count($ids);
            }
        } while ([] !== $ids);

        return $deleted;
    }

    /**
     * The unused coupons of the rewards one customer is the beneficiary of, expired or not: used ones stay.
     * Called before the customer is deleted, since their rewards then go with them and these coupons
     * could no longer be found.
     *
     * @return int the number of coupons deleted
     */
    public function purgeUnusedOf(int $customerId): int
    {
        $connection = Propel::getWriteConnection(ReferralRewardTableMap::DATABASE_NAME);
        $statement = $connection->prepare(self::UNUSED_COUPON_OF_THE_MODULE.' AND rw.customer_id = :customer');
        $statement->execute(['customer' => $customerId]);
        $ids = array_map('intval', $statement->fetchAll(\PDO::FETCH_COLUMN));

        foreach (array_chunk($ids, self::BATCH_SIZE) as $chunk) {
            $connection->exec('DELETE FROM `coupon` WHERE id IN ('.implode(',', $chunk).')');
        }

        return \count($ids);
    }

    /**
     * Every unused coupon of the module, expired or not. Used while the module's tables still exist.
     */
    public static function deleteAllUnused(ConnectionInterface $connection): int
    {
        $ids = $connection->query(self::UNUSED_COUPON_OF_THE_MODULE)->fetchAll(\PDO::FETCH_COLUMN);

        foreach (array_chunk(array_map('intval', $ids), self::BATCH_SIZE) as $chunk) {
            $connection->exec('DELETE FROM `coupon` WHERE id IN ('.implode(',', $chunk).')');
        }

        return \count($ids);
    }
}
