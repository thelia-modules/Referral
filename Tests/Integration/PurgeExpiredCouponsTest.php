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

namespace Referral\Tests\Integration;

use Propel\Runtime\Propel;
use Referral\Command\PurgeExpiredCouponsCommand;
use Referral\Model\Referral as ReferralModel;
use Referral\Reward\CouponRewardIssuer;
use Referral\Reward\RewardFactory;
use Referral\Reward\UnusedCouponPurger;
use Referral\Service\ReferralCodeProvider;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Scheduler\Attribute\AsCronTask;
use Thelia\Core\Event\TheliaEvents;
use Thelia\Model\Coupon;
use Thelia\Model\CouponCustomerCount;
use Thelia\Model\CouponQuery;
use Thelia\Model\CustomerQuery;
use Thelia\Model\Event\CustomerEvent;
use Thelia\Test\ActionIntegrationTestCase;

/**
 * The purge deletes the expired coupons of the program that nobody used, keeps the code on the reward, and
 * leaves every other coupon alone.
 */
final class PurgeExpiredCouponsTest extends ActionIntegrationTestCase
{
    public function testItDeletesTheExpiredUnusedCouponsAndKeepsTheCodeOnTheReward(): void
    {
        $reward = $this->reward(expired: true);
        $code = $reward->getCouponCode();

        $this->purge([]);

        self::assertNull(CouponQuery::create()->findOneByCode($code));
        $reward->reload();
        self::assertNull($reward->getCouponId());
        self::assertSame($code, $reward->getCouponCode(), 'the code stays on the reward');
        self::assertSame('issued', $reward->getStatus());
    }

    public function testItKeepsAnExpiredCouponThatWasUsedAndOneThatIsStillValid(): void
    {
        $used = $this->reward(expired: true);
        $customerId = (int) $used->getCustomerId();
        (new CouponCustomerCount())->setCouponId($used->getCouponId())->setCustomerId($customerId)->setCount(1)->save();
        $valid = $this->reward(expired: false);
        $flagged = $this->reward(expired: true);
        CouponQuery::create()->filterById($flagged->getCouponId())->update(['IsUsed' => 1]);

        $this->purge([]);

        self::assertNotNull(CouponQuery::create()->findPk($used->getCouponId()), 'used: kept');
        self::assertNotNull(CouponQuery::create()->findPk($valid->getCouponId()), 'valid: kept');
        self::assertNotNull(CouponQuery::create()->findPk($flagged->getCouponId()), 'flagged used: kept');
    }

    public function testItNeverTouchesACouponThatIsNotTheModulesOwn(): void
    {
        $foreign = $this->foreignExpiredCoupon();
        $this->reward(expired: true);

        $this->purge([]);

        self::assertNotNull(CouponQuery::create()->findPk($foreign->getId()));
    }

    public function testDryRunCountsAndDeletesNothing(): void
    {
        $reward = $this->reward(expired: true);
        $this->reward(expired: true);
        $before = CouponQuery::create()->filterByCode('REF-%')->count();

        $output = $this->purge(['--dry-run' => true]);

        self::assertMatchesRegularExpression('/would be deleted: [2-9]\d*/', $output);
        self::assertSame($before, CouponQuery::create()->filterByCode('REF-%')->count());
        self::assertNotNull(CouponQuery::create()->findPk($reward->getCouponId()));
    }

    public function testItWorksBatchWise(): void
    {
        $rewards = [$this->reward(expired: true), $this->reward(expired: true), $this->reward(expired: true)];

        $deleted = $this->getService(UnusedCouponPurger::class)->purgeExpired(false, 1);

        self::assertGreaterThanOrEqual(3, $deleted);
        foreach ($rewards as $reward) {
            self::assertNull(CouponQuery::create()->findPk($reward->getCouponId()));
        }
    }

    public function testTheCommandIsScheduledEveryNightOnTheTheliaSchedule(): void
    {
        $attributes = (new \ReflectionClass(PurgeExpiredCouponsCommand::class))->getAttributes(AsCronTask::class);

        self::assertCount(1, $attributes);
        self::assertSame('0 3 * * *', $attributes[0]->newInstance()->expression);
        self::assertSame(['thelia'], (array) $attributes[0]->newInstance()->schedule);
    }

    public function testTheModuleDeletionRemovesEveryUnusedCouponAndSparesTheUsedOne(): void
    {
        $unused = $this->reward(expired: false);
        $used = $this->reward(expired: false);
        (new CouponCustomerCount())->setCouponId($used->getCouponId())->setCustomerId((int) $used->getCustomerId())->setCount(1)->save();

        UnusedCouponPurger::deleteAllUnused(Propel::getConnection());

        self::assertNull(CouponQuery::create()->findPk($unused->getCouponId()));
        self::assertNotNull(CouponQuery::create()->findPk($used->getCouponId()));
    }

    public function testDeletingACustomerDeletesTheirUnusedCouponsAndKeepsTheUsedOnes(): void
    {
        $unused = $this->reward(expired: false);
        $customerId = (int) $unused->getCustomerId();
        $used = $this->anotherRewardOf($customerId);
        (new CouponCustomerCount())->setCouponId($used->getCouponId())->setCustomerId($customerId)->setCount(1)->save();
        $others = $this->reward(expired: false);

        $customer = CustomerQuery::create()->findPk($customerId);
        $this->dispatch(new CustomerEvent($customer), TheliaEvents::CUSTOMER_DELETEACCOUNT);

        self::assertNull(CustomerQuery::create()->findPk($customerId), 'the customer is gone');
        self::assertNull(CouponQuery::create()->findPk($unused->getCouponId()), 'unused: deleted with the customer');
        self::assertNotNull(CouponQuery::create()->findPk($used->getCouponId()), 'used: kept');
        self::assertNotNull(CouponQuery::create()->findPk($others->getCouponId()), 'the coupon of another customer: kept');
    }

    /**
     * @param array<string, mixed> $options
     */
    private function purge(array $options): string
    {
        $tester = new CommandTester($this->getService(PurgeExpiredCouponsCommand::class));
        $tester->execute($options);

        return $tester->getDisplay();
    }

    private function reward(bool $expired): \Referral\Model\ReferralReward
    {
        $sponsor = $this->factory->customer($this->factory->customerTitle());
        $code = $this->getService(ReferralCodeProvider::class)->getOrCreateFor($sponsor);
        $referee = $this->factory->customer($this->factory->customerTitle());
        $referral = (new ReferralModel())
            ->setSponsorCustomerId($sponsor->getId())
            ->setReferralCodeId($code->getId())
            ->setRefereeCustomerId($referee->getId())
            ->setStatus('registered');
        $referral->save();

        $reward = $this->getService(RewardFactory::class)->sponsor($referral);
        $this->getService(CouponRewardIssuer::class)->issue($reward, $sponsor);
        CouponQuery::create()->filterById($reward->getCouponId())->update([
            'ExpirationDate' => new \DateTime($expired ? '-2 days' : '+30 days'),
        ]);

        return $reward;
    }

    /**
     * A second reward of the same customer: they are the sponsor of one more referee.
     */
    private function anotherRewardOf(int $customerId): \Referral\Model\ReferralReward
    {
        $sponsor = CustomerQuery::create()->findPk($customerId);
        $code = $this->getService(ReferralCodeProvider::class)->getOrCreateFor($sponsor);
        $referee = $this->factory->customer($this->factory->customerTitle());
        $referral = (new ReferralModel())
            ->setSponsorCustomerId($customerId)
            ->setReferralCodeId($code->getId())
            ->setRefereeCustomerId($referee->getId())
            ->setStatus('registered');
        $referral->save();

        $reward = $this->getService(RewardFactory::class)->sponsor($referral);
        $this->getService(CouponRewardIssuer::class)->issue($reward, $sponsor);

        return $reward;
    }

    private function foreignExpiredCoupon(): Coupon
    {
        $coupon = new Coupon();
        $coupon->setCode('OTHER-'.bin2hex(random_bytes(4)))
            ->setType('thelia.coupon.type.remove_x_amount')
            ->setSerializedEffects('{}')
            ->setIsEnabled(1)
            ->setExpirationDate(new \DateTime('-2 days'))
            ->setIsAvailableOnSpecialOffers(1)
            ->setIsCumulative(0)
            ->setIsRemovingPostage(0)
            ->setMaxUsage(1)
            ->setIsUsed(0)
            ->setPerCustomerUsageCount(0)
            ->setSerializedConditions('[]')
            ->save();

        return $coupon;
    }
}
