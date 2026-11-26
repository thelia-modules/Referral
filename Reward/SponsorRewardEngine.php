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
use Propel\Runtime\Exception\PropelException;
use Propel\Runtime\Propel;
use Referral\Model\Map\ReferralTableMap;
use Referral\Model\Referral as ReferralModel;
use Referral\Model\ReferralQuery;
use Referral\Model\ReferralReward;
use Referral\Model\ReferralRewardQuery;
use Referral\Referral;
use Referral\Service\ReferralClock;
use Referral\Service\ReferralSettings;
use Referral\Service\SameAddressDetector;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Thelia\Log\Tlog;
use Thelia\Model\Customer;
use Thelia\Model\CustomerQuery;
use Thelia\Model\OrderQuery;
use Thelia\Model\OrderStatus;
use Thelia\Model\OrderStatusQuery;

/**
 * Decides, each time an order of a referee changes status, what becomes of the referral: the sponsor
 * is rewarded once the referee's first order reaches the status the merchant chose, and the reward is
 * taken back when that order is cancelled or refunded.
 *
 * Everything is decided under two row locks, always taken in this order: the sponsor's referral code,
 * then the referral. The first one makes the monthly cap hold: two referees of the same sponsor are
 * reviewed one after the other, each counting the rewards the other committed. The second one makes
 * two status changes of the same referee meet the referral one after the other. The locks are the
 * first statements of the transaction, so that what is read afterwards is what the other transactions
 * committed before they were granted.
 */
final readonly class SponsorRewardEngine
{
    public const STATUS_REGISTERED = 'registered';
    public const STATUS_REWARDED = 'rewarded';
    public const STATUS_CAPPED = 'capped';
    public const STATUS_CANCELLED = 'cancelled';

    public const REASON_MONTHLY_CAP = 'monthly_cap';
    public const REASON_SAME_ADDRESS = 'same_address';
    public const REASON_SPONSOR_GONE = 'sponsor_gone';
    public const REASON_SPONSOR_INACTIVE = 'sponsor_inactive';
    public const REASON_ORDER_CANCELLED = 'order_cancelled';
    public const REASON_ORDER_REFUNDED = 'order_refunded';

    private const NO_REFERRAL_CODE = 'none';

    /**
     * @param iterable<RewardIssuerInterface> $issuers
     */
    public function __construct(
        private ReferralSettings $settings,
        private RewardFactory $rewardFactory,
        private RewardCanceller $canceller,
        private SameAddressDetector $sameAddressDetector,
        private ReferralClock $clock,
        #[AutowireIterator('referral.reward_issuer')]
        private iterable $issuers,
        // Autowired by its type: only the test environment defines one.
        private ?LockedSectionProbe $probe = null,
    ) {
    }

    /**
     * Reviews a referral after a status change of one of its referee's orders.
     *
     * @return ReferralReward|null the sponsor's reward when this review issued it
     */
    public function review(int $referralId, ?int $sponsorCustomerId): ?ReferralReward
    {
        $connection = Propel::getWriteConnection(ReferralTableMap::DATABASE_NAME);
        $connection->beginTransaction();

        try {
            $sponsorCodeActivity = null === $sponsorCustomerId
                ? self::NO_REFERRAL_CODE
                : $this->lockTheReferralCodeOf($sponsorCustomerId, $connection);
            $referral = $this->lockTheReferral($referralId, $connection);

            $this->probe?->reached($referralId);

            $issued = null;
            // A sponsor deleted since the first read leaves no code to lock, and is told apart below.
            if ($referral instanceof ReferralModel && \in_array($referral->getSponsorCustomerId(), [null, $sponsorCustomerId], true)) {
                $issued = match ($referral->getStatus()) {
                    self::STATUS_REGISTERED => $this->rewardTheSponsorIfTheFirstOrderValidates($referral, $sponsorCodeActivity, $connection),
                    self::STATUS_REWARDED => $this->takeTheRewardBackIfTheTriggerOrderWasUndone($referral, $connection),
                    default => null,
                };
            }

            $connection->commit();

            return $issued;
        } catch (PropelException $exception) {
            $connection->rollBack();
            // The unique index (referral, beneficiary) is the last guard against a second sponsor reward.
            Tlog::getInstance()->warning('Referral: the review of referral #'.$referralId.' was undone ('.$exception::class.': '.$exception->getMessage().')');

            return null;
        } catch (\Throwable $exception) {
            $connection->rollBack();
            Tlog::getInstance()->error('Referral: the review of referral #'.$referralId.' failed ('.$exception::class.': '.$exception->getMessage().')');

            return null;
        }
    }

    /**
     * @return string "1" or "0" as the code's active flag, or "none" when the sponsor has no code
     */
    private function lockTheReferralCodeOf(int $sponsorCustomerId, ConnectionInterface $connection): string
    {
        $statement = $connection->prepare('SELECT `active` FROM `referral_code` WHERE `customer_id` = :sponsor FOR UPDATE');
        $statement->bindValue(':sponsor', $sponsorCustomerId, \PDO::PARAM_INT);
        $statement->execute();
        $active = $statement->fetchColumn();
        $statement->closeCursor();

        // The statement wrapper of Propel answers an empty string for no row at all.
        return null === $active || false === $active || '' === $active ? self::NO_REFERRAL_CODE : (string) (int) $active;
    }

    /**
     * Read under the lock, never from the instance pool: an object loaded earlier in the request may
     * carry a status another transaction has changed since.
     */
    private function lockTheReferral(int $referralId, ConnectionInterface $connection): ?ReferralModel
    {
        ReferralTableMap::removeInstanceFromPool((string) $referralId);

        return ReferralQuery::create()->filterById($referralId)->lockForUpdate()->findOne($connection);
    }

    private function rewardTheSponsorIfTheFirstOrderValidates(ReferralModel $referral, string $sponsorCodeActivity, ConnectionInterface $connection): ?ReferralReward
    {
        if (!$this->settings->isEnabled()) {
            return null;
        }

        $firstOrder = $this->firstValidatingOrderOf($referral->getRefereeCustomerId(), $connection);
        if (null === $firstOrder) {
            return null;
        }

        $now = $this->clock->nowToTheSecond();
        $referral->setTriggerOrderId($firstOrder['id']);
        $sponsorCustomerId = $referral->getSponsorCustomerId();
        $sponsor = null === $sponsorCustomerId ? null : CustomerQuery::create()->findPk($sponsorCustomerId, $connection);

        if (null === $sponsorCustomerId || !$sponsor instanceof Customer) {
            return $this->close($referral, self::STATUS_CANCELLED, self::REASON_SPONSOR_GONE, $connection);
        }

        if ('1' !== $sponsorCodeActivity) {
            return $this->close($referral, self::STATUS_CANCELLED, self::REASON_SPONSOR_INACTIVE, $connection);
        }

        if ('1' === $this->settings->get(Referral::CONFIG_SAME_ADDRESS_CHECK)
            && $this->sameAddressDetector->isDeliveredToTheSponsor($firstOrder['id'], $sponsorCustomerId, $connection)) {
            return $this->close($referral, self::STATUS_CANCELLED, self::REASON_SAME_ADDRESS, $connection);
        }

        if ($this->theSponsorReachedTheMonthlyCap($sponsorCustomerId, $now, $connection)) {
            return $this->close($referral, self::STATUS_CAPPED, self::REASON_MONTHLY_CAP, $connection);
        }

        $reward = $this->rewardFactory->sponsor($referral)
            ->setCreatedAt($now)
            ->setUpdatedAt($now);
        $this->issue($reward, $sponsor);

        $referral
            ->setStatus(self::STATUS_REWARDED)
            ->setStatusReason(null)
            ->setRewardedAt($now)
            ->save($connection);

        return $reward;
    }

    private function takeTheRewardBackIfTheTriggerOrderWasUndone(ReferralModel $referral, ConnectionInterface $connection): null
    {
        $triggerOrderId = $referral->getTriggerOrderId();
        if ('1' !== $this->settings->get(Referral::CONFIG_CANCEL_ON_REFUND) || null === $triggerOrderId) {
            return null;
        }

        $status = $this->statusOfOrder($triggerOrderId, $connection);
        $reason = match (true) {
            null === $status => null,
            $status->isCancelled() => self::REASON_ORDER_CANCELLED,
            $status->isRefunded() => self::REASON_ORDER_REFUNDED,
            default => null,
        };

        if (null === $reason) {
            return null;
        }

        $reward = ReferralRewardQuery::create()
            ->filterByReferralId($referral->getId())
            ->filterByBeneficiary(RewardFactory::BENEFICIARY_SPONSOR)
            ->findOne($connection);

        if ($reward instanceof ReferralReward) {
            $this->canceller->cancel($reward, $reason);

            return null;
        }

        $this->close($referral, self::STATUS_CANCELLED, $reason, $connection);

        return null;
    }

    /**
     * The referee's earliest order that reached the validating state of the trigger. An order that never
     * reached it (payment failed, abandoned, still pending) and a cancelled or refunded one are ignored:
     * they neither trigger nor block the order that follows. Read as rows, never from the instance pool.
     *
     * @return array{id: int}|null
     */
    private function firstValidatingOrderOf(int $refereeCustomerId, ConnectionInterface $connection): ?array
    {
        /** @var list<array{Id: int|string, StatusId: int|string|null}> $orders */
        $orders = OrderQuery::create()
            ->filterByCustomerId($refereeCustomerId)
            ->orderById()
            ->select(['Id', 'StatusId'])
            ->find($connection)
            ->getData();

        foreach ($orders as $order) {
            $status = null === $order['StatusId'] ? null : OrderStatusQuery::create()->findPk((int) $order['StatusId'], $connection);
            if ($status instanceof OrderStatus && !$status->isCancelled() && !$status->isRefunded() && $this->validatesTheReferral($status)) {
                return ['id' => (int) $order['Id']];
            }
        }

        return null;
    }

    /**
     * "paid" counts an order paid, being processed or sent; "sent" counts a sent order only. A custom
     * status declared equivalent to one of them counts as it.
     */
    private function validatesTheReferral(OrderStatus $status): bool
    {
        return 'sent' === $this->settings->get(Referral::CONFIG_TRIGGER_STATUS)
            ? $status->isSent(false)
            : $status->isPaid(false);
    }

    private function statusOfOrder(int $orderId, ConnectionInterface $connection): ?OrderStatus
    {
        // Two columns: Propel answers a single selected column as bare values.
        /** @var list<array{Id: int|string, StatusId: int|string|null}> $rows */
        $rows = OrderQuery::create()->filterById($orderId)->select(['Id', 'StatusId'])->find($connection)->getData();
        $statusId = $rows[0]['StatusId'] ?? null;

        return null === $statusId ? null : OrderStatusQuery::create()->findPk((int) $statusId, $connection);
    }

    /**
     * The sponsor's rewards still standing, issued in the calendar month of the moment. A cap of 0
     * means no cap. Coupon uses are not counted here: the core keeps them.
     */
    private function theSponsorReachedTheMonthlyCap(int $sponsorCustomerId, \DateTimeImmutable $now, ConnectionInterface $connection): bool
    {
        $cap = (int) $this->settings->get(Referral::CONFIG_MONTHLY_CAP);
        if ($cap <= 0) {
            return false;
        }

        [$monthStart, $nextMonthStart] = $this->clock->monthOf($now);

        $issued = ReferralRewardQuery::create()
            ->filterByCustomerId($sponsorCustomerId)
            ->filterByBeneficiary(RewardFactory::BENEFICIARY_SPONSOR)
            ->filterByStatus('issued')
            // Every date of the module is written to the second: the last second of the month is the bound.
            ->filterByCreatedAt([
                'min' => $monthStart->format('Y-m-d H:i:s'),
                'max' => $nextMonthStart->modify('-1 second')->format('Y-m-d H:i:s'),
            ])
            ->count($connection);

        return $issued >= $cap;
    }

    private function issue(ReferralReward $reward, Customer $sponsor): void
    {
        foreach ($this->issuers as $issuer) {
            if ($issuer->supports($reward->getRewardType())) {
                $issuer->issue($reward, $sponsor);

                return;
            }
        }

        throw new \LogicException('No issuer for the reward type '.$reward->getRewardType());
    }

    private function close(ReferralModel $referral, string $status, string $reason, ConnectionInterface $connection): null
    {
        $referral
            ->setStatus($status)
            ->setStatusReason($reason)
            ->save($connection);

        return null;
    }
}
