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

use Propel\Runtime\Propel;
use Referral\Model\Map\ReferralRewardTableMap;
use Referral\Model\Map\ReferralTableMap;
use Referral\Model\Referral as ReferralModel;
use Referral\Model\ReferralQuery;
use Referral\Model\ReferralReward;
use Referral\Model\ReferralRewardQuery;
use Referral\Service\ReferralClock;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Takes a reward back: the row stays, marked cancelled with its reason, and its coupon is disabled
 * when it was never used. Taking back the sponsor's reward cancels the referral with the same reason:
 * a referral is "rewarded" only as long as its sponsor's reward stands.
 *
 * The reason is a code of the module (order_cancelled, order_refunded) or the free text of an
 * administrator. A reward already cancelled is left as it is.
 */
final readonly class RewardCanceller
{
    public const STATUS_CANCELLED = 'cancelled';

    /** The size of the reason columns. */
    private const REASON_MAX_LENGTH = 255;

    /**
     * @param iterable<RewardIssuerInterface> $issuers
     */
    public function __construct(
        private ReferralClock $clock,
        #[AutowireIterator('referral.reward_issuer')]
        private iterable $issuers,
    ) {
    }

    public function cancel(ReferralReward $reward, string $reason): void
    {
        $connection = Propel::getWriteConnection(ReferralRewardTableMap::DATABASE_NAME);
        $connection->beginTransaction();

        try {
            // Same order as the reviews: the referral first, then its reward.
            $referral = $this->lockedReferral((int) $reward->getReferralId());
            $locked = $this->lockedReward((int) $reward->getId());

            if (!$locked instanceof ReferralReward || self::STATUS_CANCELLED === $locked->getStatus()) {
                $connection->commit();

                return;
            }

            $now = $this->clock->nowToTheSecond();
            $locked
                ->setStatus(self::STATUS_CANCELLED)
                ->setCancelReason(mb_substr($reason, 0, self::REASON_MAX_LENGTH))
                ->setCancelledAt($now)
                ->save($connection);

            if (RewardFactory::BENEFICIARY_SPONSOR === $locked->getBeneficiary() && $referral instanceof ReferralModel) {
                $referral
                    ->setStatus(self::STATUS_CANCELLED)
                    ->setStatusReason(mb_substr($reason, 0, self::REASON_MAX_LENGTH))
                    ->save($connection);
            }

            foreach ($this->issuers as $issuer) {
                if ($issuer->supports($locked->getRewardType())) {
                    $issuer->revoke($locked);
                    break;
                }
            }

            $connection->commit();
        } catch (\Throwable $exception) {
            $connection->rollBack();

            throw $exception;
        }

        $reward->reload();
    }

    private function lockedReferral(int $referralId): ?ReferralModel
    {
        ReferralTableMap::removeInstanceFromPool((string) $referralId);

        return ReferralQuery::create()->filterById($referralId)->lockForUpdate()->findOne();
    }

    private function lockedReward(int $rewardId): ?ReferralReward
    {
        ReferralRewardTableMap::removeInstanceFromPool((string) $rewardId);

        return ReferralRewardQuery::create()->filterById($rewardId)->lockForUpdate()->findOne();
    }
}
