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
use Referral\Model\Referral as ReferralModel;
use Referral\Model\ReferralCodeQuery;
use Referral\Model\ReferralQuery;
use Referral\Model\ReferralReward;
use Referral\Model\ReferralRewardQuery;
use Referral\Referral;
use Referral\Reward\RewardCanceller;
use Referral\Reward\RewardFactory;
use Referral\Reward\RewardIssuerInterface;
use Referral\Reward\SponsorRewardEngine;
use Referral\Service\ReferralClock;
use Referral\Service\ReferralCodeProvider;
use Referral\Service\ReferralSettings;
use Referral\Service\SameAddressDetector;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\RawMessage;
use Thelia\Core\Event\Order\OrderEvent;
use Thelia\Core\Event\TheliaEvents;
use Thelia\Core\Security\SecurityContext;
use Thelia\Domain\Promotion\Coupon\CouponFactory;
use Thelia\Model\ConfigQuery;
use Thelia\Model\CouponQuery;
use Thelia\Model\Customer;
use Thelia\Model\Order;
use Thelia\Model\OrderStatus;
use Thelia\Model\OrderStatusQuery;
use Thelia\Test\ActionIntegrationTestCase;
use Thelia\Test\Trait\RecordsSqlQueries;

/**
 * The sponsor is rewarded once, when the referee's first order reaches the status the merchant chose,
 * and loses the reward when that order is cancelled or refunded.
 */
final class SponsorRewardTest extends ActionIntegrationTestCase
{
    use RecordsSqlQueries;

    protected function setUp(): void
    {
        parent::setUp();

        // The test shop has no sender address: without one, no mail leaves.
        ConfigQuery::write('store_email', 'shop@example.test');
        ConfigQuery::resetCache();
    }

    protected function tearDown(): void
    {
        $this->getService(SecurityContext::class)->clearCustomerUser();

        parent::tearDown();
    }

    public function testThePaidFirstOrderRewardsTheSponsorWithACouponOfTheirOwnAndAMail(): void
    {
        [$sponsor] = $this->sponsorWithCode();
        [$referee, $referral] = $this->refereeOf($sponsor);
        $order = $this->factory->order($referee);

        $this->moveTo($order, OrderStatus::CODE_PAID);

        $referral = $this->reread($referral);
        self::assertSame('rewarded', $referral->getStatus());
        self::assertNull($referral->getStatusReason());
        self::assertSame($order->getId(), $referral->getTriggerOrderId());
        self::assertNotNull($referral->getRewardedAt());

        $rewards = ReferralRewardQuery::create()->filterByReferralId($referral->getId())->filterByBeneficiary('sponsor')->find();
        self::assertCount(1, $rewards);
        $reward = $rewards->getFirst();
        self::assertInstanceOf(ReferralReward::class, $reward);
        self::assertSame($sponsor->getId(), $reward->getCustomerId());
        self::assertSame('issued', $reward->getStatus());

        $couponFactory = $this->getService(CouponFactory::class);
        $security = $this->getService(SecurityContext::class);
        $security->setCustomerUser($sponsor);
        self::assertTrue($couponFactory->buildCouponFromCode((string) $reward->getCouponCode())->isMatching(), 'the sponsor');
        $security->setCustomerUser($referee);
        self::assertFalse($couponFactory->buildCouponFromCode((string) $reward->getCouponCode())->isMatching(), 'the referee');

        $mails = $this->mailsTo((string) $sponsor->getEmail());
        self::assertCount(1, $mails);
        self::assertSame('Comer placed a first order: here is your reward', $mails[0]->getSubject());
        self::assertStringContainsString('10 €', (string) $mails[0]->getTextBody());
        self::assertStringContainsString((string) $reward->getCouponCode(), (string) $mails[0]->getTextBody());
        foreach ($this->mailsTo((string) $referee->getEmail()) as $mailToTheReferee) {
            self::assertStringNotContainsString((string) $reward->getCouponCode(), (string) $mailToTheReferee->getTextBody(), 'the payment mail of the referee only');
        }
    }

    public function testASecondPaidOrderGivesNoNewReward(): void
    {
        [$sponsor] = $this->sponsorWithCode();
        [$referee, $referral] = $this->refereeOf($sponsor);
        $first = $this->factory->order($referee);
        $second = $this->factory->order($referee);

        $this->moveTo($first, OrderStatus::CODE_PAID);
        $this->moveTo($second, OrderStatus::CODE_PAID);

        self::assertSame(1, $this->sponsorRewardsOf($sponsor));
        self::assertSame($first->getId(), $this->reread($referral)->getTriggerOrderId());
        self::assertCount(1, $this->mailsTo((string) $sponsor->getEmail()));
    }

    public function testANeverPaidFirstOrderDoesNotBlockAPaidSecondOrder(): void
    {
        [$sponsor] = $this->sponsorWithCode();
        [$referee, $referral] = $this->refereeOf($sponsor);
        $this->factory->order($referee);
        $second = $this->factory->order($referee);

        $this->moveTo($second, OrderStatus::CODE_PAID);

        self::assertSame(1, $this->sponsorRewardsOf($sponsor));
        self::assertSame('rewarded', $this->reread($referral)->getStatus());
        self::assertSame($second->getId(), $this->reread($referral)->getTriggerOrderId());
    }

    public function testAnIssuerThatFailsLeavesTheReferralRegisteredAndTheNextStatusChangeRetries(): void
    {
        [$sponsor] = $this->sponsorWithCode();
        [$referee, $referral] = $this->refereeOf($sponsor);
        $order = $this->factory->order($referee);
        $paid = OrderStatusQuery::create()->findOneByCode(OrderStatus::CODE_PAID);
        self::assertInstanceOf(OrderStatus::class, $paid);
        // Paid without any event: the review below is the first one to see it.
        $order->setStatusId($paid->getId())->save();

        $failingIssuer = new class implements RewardIssuerInterface {
            public function supports(string $rewardType): bool
            {
                return true;
            }

            public function issue(ReferralReward $reward, Customer $beneficiary): void
            {
                throw new \RuntimeException('the coupon could not be created');
            }

            public function revoke(ReferralReward $reward): void
            {
            }
        };
        $engine = new SponsorRewardEngine(
            $this->getService(ReferralSettings::class),
            $this->getService(RewardFactory::class),
            $this->getService(RewardCanceller::class),
            $this->getService(SameAddressDetector::class),
            $this->getService(ReferralClock::class),
            [$failingIssuer],
        );

        self::assertNull($engine->review($referral->getId(), $sponsor->getId()));
        self::assertSame('registered', $this->reread($referral)->getStatus());
        self::assertSame(0, $this->sponsorRewardsOf($sponsor));

        // The order goes on: paid, then processing. The listener of the shop has a working issuer.
        $this->moveTo($order, OrderStatus::CODE_PROCESSING);

        self::assertSame('rewarded', $this->reread($referral)->getStatus());
        self::assertSame(1, $this->sponsorRewardsOf($sponsor));
        self::assertSame($order->getId(), $this->reread($referral)->getTriggerOrderId());
    }

    public function testACancelledFirstOrderLetsTheNextOneTrigger(): void
    {
        [$sponsor] = $this->sponsorWithCode();
        [$referee, $referral] = $this->refereeOf($sponsor);
        $first = $this->factory->order($referee);
        $second = $this->factory->order($referee);

        $this->moveTo($first, OrderStatus::CODE_CANCELED);
        self::assertSame('registered', $this->reread($referral)->getStatus());

        $this->moveTo($second, OrderStatus::CODE_PAID);

        self::assertSame('rewarded', $this->reread($referral)->getStatus());
        self::assertSame($second->getId(), $this->reread($referral)->getTriggerOrderId());
        self::assertSame(1, $this->sponsorRewardsOf($sponsor));
    }

    public function testWithTheSentTriggerAPaidOrderWaitsAndASentOneRewards(): void
    {
        $this->setSetting(Referral::CONFIG_TRIGGER_STATUS, 'sent');
        [$sponsor] = $this->sponsorWithCode();
        [$referee, $referral] = $this->refereeOf($sponsor);
        $order = $this->factory->order($referee);

        $this->moveTo($order, OrderStatus::CODE_PAID);
        self::assertSame(0, $this->sponsorRewardsOf($sponsor));
        self::assertSame('registered', $this->reread($referral)->getStatus());

        $this->moveTo($order, OrderStatus::CODE_SENT);
        self::assertSame(1, $this->sponsorRewardsOf($sponsor));
        self::assertSame('rewarded', $this->reread($referral)->getStatus());
    }

    public function testARefundedTriggerOrderTakesTheRewardBackAndDisablesItsCoupon(): void
    {
        [$sponsor] = $this->sponsorWithCode();
        [$referee, $referral] = $this->refereeOf($sponsor);
        $order = $this->factory->order($referee);
        $this->moveTo($order, OrderStatus::CODE_PAID);

        $this->moveTo($order, OrderStatus::CODE_REFUNDED);

        $reward = $this->sponsorRewardOf($referral);
        self::assertSame('cancelled', $reward->getStatus());
        self::assertSame('order_refunded', $reward->getCancelReason());
        self::assertNotNull($reward->getCancelledAt());
        self::assertFalse((bool) CouponQuery::create()->findPk($reward->getCouponId())?->getIsEnabled());
        $referral = $this->reread($referral);
        self::assertSame('cancelled', $referral->getStatus());
        self::assertSame('order_refunded', $referral->getStatusReason());
    }

    public function testACancelledTriggerOrderTakesTheRewardBack(): void
    {
        [$sponsor] = $this->sponsorWithCode();
        [$referee, $referral] = $this->refereeOf($sponsor);
        $order = $this->factory->order($referee);
        $this->moveTo($order, OrderStatus::CODE_PAID);

        $this->moveTo($order, OrderStatus::CODE_CANCELED);

        self::assertSame('order_cancelled', $this->sponsorRewardOf($referral)->getCancelReason());
        self::assertSame('cancelled', $this->reread($referral)->getStatus());
    }

    public function testWithoutCancelOnRefundTheRewardStays(): void
    {
        $this->setSetting(Referral::CONFIG_CANCEL_ON_REFUND, '0');
        [$sponsor] = $this->sponsorWithCode();
        [$referee, $referral] = $this->refereeOf($sponsor);
        $order = $this->factory->order($referee);
        $this->moveTo($order, OrderStatus::CODE_PAID);

        $this->moveTo($order, OrderStatus::CODE_REFUNDED);

        $reward = $this->sponsorRewardOf($referral);
        self::assertSame('issued', $reward->getStatus());
        self::assertTrue((bool) CouponQuery::create()->findPk($reward->getCouponId())?->getIsEnabled());
        self::assertSame('rewarded', $this->reread($referral)->getStatus());
    }

    public function testTheMonthlyCapCapsTheNextRefereeOfTheSameSponsor(): void
    {
        $this->setSetting(Referral::CONFIG_MONTHLY_CAP, '1');
        [$sponsor] = $this->sponsorWithCode();
        [$firstReferee, $firstReferral] = $this->refereeOf($sponsor);
        [$secondReferee, $secondReferral] = $this->refereeOf($sponsor);

        $this->moveTo($this->factory->order($firstReferee), OrderStatus::CODE_PAID);
        $this->moveTo($this->factory->order($secondReferee), OrderStatus::CODE_PAID);

        self::assertSame('rewarded', $this->reread($firstReferral)->getStatus());
        $secondReferral = $this->reread($secondReferral);
        self::assertSame('capped', $secondReferral->getStatus());
        self::assertSame('monthly_cap', $secondReferral->getStatusReason());
        self::assertSame(1, $this->sponsorRewardsOf($sponsor));
        self::assertCount(1, $this->mailsTo((string) $sponsor->getEmail()));
    }

    public function testARewardOfLastMonthOrACancelledOneLeavesRoomUnderTheCap(): void
    {
        $this->setSetting(Referral::CONFIG_MONTHLY_CAP, '1');
        [$sponsor] = $this->sponsorWithCode();
        [, $oldReferral] = $this->refereeOf($sponsor);
        [, $cancelledReferral] = $this->refereeOf($sponsor);
        $lastSecondOfLastMonth = (new \DateTimeImmutable('first day of this month midnight'))->modify('-1 second');
        $this->storedSponsorReward($oldReferral, 'issued', $lastSecondOfLastMonth);
        $this->storedSponsorReward($cancelledReferral, 'cancelled', new \DateTimeImmutable('first day of this month midnight'));
        [$referee, $referral] = $this->refereeOf($sponsor);

        $this->moveTo($this->factory->order($referee), OrderStatus::CODE_PAID);

        self::assertSame('rewarded', $this->reread($referral)->getStatus());
    }

    public function testACapOfZeroMeansNoCap(): void
    {
        $this->setSetting(Referral::CONFIG_MONTHLY_CAP, '0');
        [$sponsor] = $this->sponsorWithCode();

        foreach ([1, 2, 3] as $ignored) {
            [$referee] = $this->refereeOf($sponsor);
            $this->moveTo($this->factory->order($referee), OrderStatus::CODE_PAID);
        }

        self::assertSame(3, $this->sponsorRewardsOf($sponsor));
    }

    public function testAnOrderDeliveredToTheSponsorCancelsTheReferralWhenTheCheckIsOn(): void
    {
        $this->setSetting(Referral::CONFIG_SAME_ADDRESS_CHECK, '1');
        [$sponsor] = $this->sponsorWithCode();
        $this->factory->address($sponsor, null, null, ['address1' => '12, Rue de l\'Église', 'zipcode' => '75 001', 'city' => 'Paris']);
        [$referee, $referral] = $this->refereeOf($sponsor);
        $order = $this->factory->order($referee);
        $order->getOrderAddressRelatedByDeliveryOrderAddressId()
            ->setAddress1('12 rue de l eglise')->setZipcode('75001')->setCity('PARIS ')->save();

        $this->moveTo($order, OrderStatus::CODE_PAID);

        $referral = $this->reread($referral);
        self::assertSame('cancelled', $referral->getStatus());
        self::assertSame('same_address', $referral->getStatusReason());
        self::assertSame(0, $this->sponsorRewardsOf($sponsor));
    }

    public function testAnOrderDeliveredElsewhereOrWithTheCheckOffRewards(): void
    {
        [$sponsor] = $this->sponsorWithCode();
        $this->factory->address($sponsor, null, null, ['address1' => '12 rue de l\'Eglise', 'zipcode' => '75001', 'city' => 'Paris']);

        [$referee, $referral] = $this->refereeOf($sponsor);
        $order = $this->factory->order($referee);
        $order->getOrderAddressRelatedByDeliveryOrderAddressId()->setAddress1('12 rue de l\'Eglise')->setZipcode('75001')->setCity('Paris')->save();
        $this->moveTo($order, OrderStatus::CODE_PAID);
        self::assertSame('rewarded', $this->reread($referral)->getStatus(), 'the check is off');

        $this->setSetting(Referral::CONFIG_SAME_ADDRESS_CHECK, '1');
        [$otherReferee, $otherReferral] = $this->refereeOf($sponsor);
        $otherOrder = $this->factory->order($otherReferee);
        $otherOrder->getOrderAddressRelatedByDeliveryOrderAddressId()->setAddress1('14 rue de l\'Eglise')->setZipcode('75001')->setCity('Paris')->save();
        $this->moveTo($otherOrder, OrderStatus::CODE_PAID);
        self::assertSame('rewarded', $this->reread($otherReferral)->getStatus(), 'another street number');
    }

    public function testAGoneSponsorCancelsTheReferral(): void
    {
        [$sponsor] = $this->sponsorWithCode();
        [$referee, $referral] = $this->refereeOf($sponsor);
        $referral->setSponsorCustomerId(null)->save();

        $this->moveTo($this->factory->order($referee), OrderStatus::CODE_PAID);

        $referral = $this->reread($referral);
        self::assertSame('cancelled', $referral->getStatus());
        self::assertSame('sponsor_gone', $referral->getStatusReason());
        self::assertSame(0, ReferralRewardQuery::create()->filterByReferralId($referral->getId())->filterByBeneficiary('sponsor')->count());
    }

    public function testASponsorWhoseCodeIsInactiveCancelsTheReferral(): void
    {
        [$sponsor, $code] = $this->sponsorWithCode();
        ReferralCodeQuery::create()->filterByCode($code)->update(['Active' => 0]);
        [$referee, $referral] = $this->refereeOf($sponsor);

        $this->moveTo($this->factory->order($referee), OrderStatus::CODE_PAID);

        $referral = $this->reread($referral);
        self::assertSame('cancelled', $referral->getStatus());
        self::assertSame('sponsor_inactive', $referral->getStatusReason());
        self::assertSame(0, $this->sponsorRewardsOf($sponsor));
    }

    public function testAnOrderOfACustomerWhoIsNotARefereeCostsOneQueryOnTheReferralTables(): void
    {
        [$sponsor] = $this->sponsorWithCode();
        $this->refereeOf($sponsor);
        $stranger = $this->factory->customer($this->factory->customerTitle());
        $order = $this->factory->order($stranger);

        $statements = $this->recordSqlQueries(fn () => $this->moveTo($order, OrderStatus::CODE_PAID));

        $onTheModuleTables = array_values(array_filter($statements, static fn (string $statement): bool => 1 === preg_match('/`referral(_code|_reward)?`/', $statement)));
        self::assertCount(1, $onTheModuleTables, implode("\n", $onTheModuleTables));
        self::assertStringContainsString('referee_customer_id', $onTheModuleTables[0]);
        self::assertSame(0, $this->sponsorRewardsOf($sponsor));
    }

    /**
     * Production keeps the instance pool on: a referral loaded earlier in the request must not be
     * reviewed as it was then, but as another process left it.
     */
    public function testTheReviewReadsTheReferralAgainWhateverThePoolHolds(): void
    {
        [$sponsor] = $this->sponsorWithCode();
        [$referee, $referral] = $this->refereeOf($sponsor);
        $order = $this->factory->order($referee);

        Propel::enableInstancePooling();

        try {
            self::assertSame('registered', ReferralQuery::create()->findPk($referral->getId())?->getStatus());
            $this->setSetting(Referral::CONFIG_ENABLED, '0');
            $this->moveTo($order, OrderStatus::CODE_PAID);
            $this->setSetting(Referral::CONFIG_ENABLED, '1');
            // Another process capped it, after the listener had read it as registered.
            $this->getPropelConnection()->exec("UPDATE `referral` SET `status` = 'capped', `status_reason` = 'monthly_cap' WHERE `id` = ".(int) $referral->getId());

            $issued = $this->getService(SponsorRewardEngine::class)->review((int) $referral->getId(), $sponsor->getId());
        } finally {
            Propel::disableInstancePooling();
        }

        self::assertNull($issued);
        self::assertSame(0, $this->sponsorRewardsOf($sponsor));
        self::assertSame('capped', $this->reread($referral)->getStatus());
    }

    public function testTheCancellerTakesARewardBackWithTheReasonOfAnAdministratorOnce(): void
    {
        [$sponsor] = $this->sponsorWithCode();
        [$referee, $referral] = $this->refereeOf($sponsor);
        $this->moveTo($this->factory->order($referee), OrderStatus::CODE_PAID);
        $reward = $this->sponsorRewardOf($referral);
        $canceller = $this->getService(RewardCanceller::class);

        $canceller->cancel($reward, 'Fraud suspected');
        $cancelledAt = $this->sponsorRewardOf($referral)->getCancelledAt('Y-m-d H:i:s');
        $canceller->cancel($reward, 'Second click');

        $reward = $this->sponsorRewardOf($referral);
        self::assertSame('cancelled', $reward->getStatus());
        self::assertSame('Fraud suspected', $reward->getCancelReason());
        self::assertSame($cancelledAt, $reward->getCancelledAt('Y-m-d H:i:s'));
        self::assertFalse((bool) CouponQuery::create()->findPk($reward->getCouponId())?->getIsEnabled());
        self::assertSame('Fraud suspected', $this->reread($referral)->getStatusReason());
    }

    /**
     * @return array{Customer, string}
     */
    private function sponsorWithCode(): array
    {
        $sponsor = $this->factory->customer($this->factory->customerTitle());

        return [$sponsor, $this->getService(ReferralCodeProvider::class)->getOrCreateFor($sponsor)->getCode()];
    }

    /**
     * @return array{Customer, ReferralModel}
     */
    private function refereeOf(Customer $sponsor): array
    {
        $referee = $this->factory->customer($this->factory->customerTitle(), ['firstname' => 'Comer']);
        $referral = (new ReferralModel())
            ->setSponsorCustomerId($sponsor->getId())
            ->setReferralCodeId(ReferralCodeQuery::create()->findOneByCustomerId($sponsor->getId())?->getId())
            ->setRefereeCustomerId($referee->getId())
            ->setStatus('registered');
        $referral->save();

        return [$referee, $referral];
    }

    private function moveTo(Order $order, string $statusCode): void
    {
        $status = OrderStatusQuery::create()->findOneByCode($statusCode);
        self::assertInstanceOf(OrderStatus::class, $status);

        $this->dispatch((new OrderEvent($order))->setStatus($status->getId())->forceStatusTransition(), TheliaEvents::ORDER_UPDATE_STATUS);
    }

    private function reread(ReferralModel $referral): ReferralModel
    {
        $reread = ReferralQuery::create()->findPk($referral->getId());
        self::assertInstanceOf(ReferralModel::class, $reread);

        return $reread;
    }

    private function sponsorRewardOf(ReferralModel $referral): ReferralReward
    {
        $reward = ReferralRewardQuery::create()->filterByReferralId($referral->getId())->filterByBeneficiary('sponsor')->findOne();
        self::assertInstanceOf(ReferralReward::class, $reward);

        return $reward;
    }

    private function sponsorRewardsOf(Customer $sponsor): int
    {
        return ReferralRewardQuery::create()
            ->filterByCustomerId($sponsor->getId())
            ->filterByBeneficiary('sponsor')
            ->filterByStatus('issued')
            ->count();
    }

    private function storedSponsorReward(ReferralModel $referral, string $status, \DateTimeImmutable $createdAt): void
    {
        (new ReferralReward())
            ->setReferralId($referral->getId())
            ->setBeneficiary(RewardFactory::BENEFICIARY_SPONSOR)
            ->setCustomerId($referral->getSponsorCustomerId())
            ->setValueType('amount')
            ->setValue('10')
            ->setStatus($status)
            ->setCreatedAt($createdAt)
            ->save();
        $referral->setStatus('rewarded')->save();
    }

    /**
     * @return list<Email>
     */
    private function mailsTo(string $address): array
    {
        return array_values(array_filter(
            self::getMailerMessages(),
            static fn (RawMessage $message): bool => $message instanceof Email
                && \in_array($address, array_map(static fn (Address $to): string => $to->getAddress(), $message->getTo()), true),
        ));
    }

    private function setSetting(string $name, string $value): void
    {
        Referral::setConfigValue($name, $value);
        ConfigQuery::resetCache();
    }
}
