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

namespace Referral\Admin;

use Propel\Runtime\Propel;

/**
 * Reads a page of the referrals screen in a bounded number of queries, whatever the page size:
 * one for the counters, one for the total of the filter, one for the rows (sponsor, referee and trigger
 * order joined), one for the rewards of those rows.
 */
final readonly class ReferralListReader
{
    private const ROWS_SQL = <<<'SQL'
        SELECT
            r.id,
            r.status,
            r.status_reason,
            r.created_at,
            r.sponsor_customer_id,
            sc.firstname AS sponsor_firstname,
            sc.lastname AS sponsor_lastname,
            r.referee_customer_id,
            ec.firstname AS referee_firstname,
            ec.lastname AS referee_lastname,
            r.trigger_order_id,
            o.ref AS trigger_order_ref
        FROM `referral` r
        LEFT JOIN `customer` sc ON sc.id = r.sponsor_customer_id
        LEFT JOIN `customer` ec ON ec.id = r.referee_customer_id
        LEFT JOIN `order` o ON o.id = r.trigger_order_id
        %s
        ORDER BY r.created_at DESC, r.id DESC
        LIMIT %d OFFSET %d
        SQL;

    private const COUNTERS_SQL = <<<'SQL'
        SELECT
            (SELECT COUNT(*) FROM `referral`) AS referrals,
            (SELECT COUNT(*) FROM `referral_reward` WHERE status = 'issued') AS rewards_issued,
            (SELECT COUNT(*) FROM `referral_reward` WHERE status = 'cancelled') AS rewards_cancelled
        SQL;

    /**
     * @return array{
     *     counters: array{referrals: int, rewardsIssued: int, rewardsCancelled: int},
     *     total: int,
     *     page: int,
     *     rows: list<array<string, mixed>>
     * }
     */
    public function read(?string $status, int $page, int $pageSize): array
    {
        $connection = Propel::getConnection();

        $counters = $connection->query(self::COUNTERS_SQL)->fetch(\PDO::FETCH_ASSOC);

        $where = null === $status ? '' : 'WHERE r.status = :status';
        $parameters = null === $status ? [] : ['status' => $status];

        $count = $connection->prepare('SELECT COUNT(*) FROM `referral` r '.$where);
        $count->execute($parameters);
        $total = (int) $count->fetchColumn();

        $page = max(1, min($page, max(1, (int) ceil($total / $pageSize))));

        $statement = $connection->prepare(\sprintf(self::ROWS_SQL, $where, $pageSize, ($page - 1) * $pageSize));
        $statement->execute($parameters);
        /** @var list<array<string, mixed>> $rows */
        $rows = $statement->fetchAll(\PDO::FETCH_ASSOC);

        return [
            'counters' => [
                'referrals' => (int) $counters['referrals'],
                'rewardsIssued' => (int) $counters['rewards_issued'],
                'rewardsCancelled' => (int) $counters['rewards_cancelled'],
            ],
            'total' => $total,
            'page' => $page,
            'rows' => $this->withRewards($rows),
        ];
    }

    /**
     * @param list<array<string, mixed>> $rows
     *
     * @return list<array<string, mixed>>
     */
    private function withRewards(array $rows): array
    {
        if ([] === $rows) {
            return [];
        }

        $ids = array_map(static fn (array $row): int => (int) $row['id'], $rows);
        $statement = Propel::getConnection()->query(
            'SELECT id, referral_id, beneficiary, value_type, value, coupon_code, status, cancel_reason '
            .'FROM `referral_reward` WHERE referral_id IN ('.implode(',', $ids).') ORDER BY beneficiary DESC, id',
        );

        $byReferral = [];
        foreach ($statement->fetchAll(\PDO::FETCH_ASSOC) as $reward) {
            $byReferral[(int) $reward['referral_id']][] = $reward;
        }

        foreach ($rows as &$row) {
            $row['rewards'] = $byReferral[(int) $row['id']] ?? [];
        }

        return $rows;
    }
}
