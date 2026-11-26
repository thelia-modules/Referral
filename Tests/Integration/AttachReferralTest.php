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

use Referral\EventListeners\AttachReferralListener;
use Referral\EventListeners\RegisterFormListener;
use Referral\Model\Referral as ReferralModel;
use Referral\Model\ReferralQuery;
use Referral\Model\ReferralReward;
use Referral\Model\ReferralRewardQuery;
use Referral\Referral;
use Referral\Reward\CouponRewardIssuer;
use Referral\Service\ReferralCodeProvider;
use Symfony\Component\HttpFoundation\RequestStack;
use Thelia\Core\Event\Customer\CustomerCreateOrUpdateMinimalEvent;
use Thelia\Core\Event\TheliaEvents;
use Thelia\Core\Security\SecurityContext;
use Thelia\Domain\Promotion\Coupon\CouponFactory;
use Thelia\Domain\Promotion\Coupon\Exception\CouponNoUsageLeftException;
use Thelia\Model\ConfigQuery;
use Thelia\Model\Coupon;
use Thelia\Model\CouponCustomerCountQuery;
use Thelia\Model\CouponQuery;
use Thelia\Model\Customer;
use Thelia\Model\CustomerQuery;
use Thelia\Test\ActionIntegrationTestCase;

/**
 * An account created with a usable code is tied to its sponsor and gets its welcome coupon, which
 * only it can use, once.
 */
final class AttachReferralTest extends ActionIntegrationTestCase
{
    protected function tearDown(): void
    {
        $this->getService(SecurityContext::class)->clearCustomerUser();

        parent::tearDown();
    }

    public function testAnAccountCreatedWithAUsableCodeIsAttachedAndGetsItsWelcomeCoupon(): void
    {
        $this->settingsWithoutMinimumAmount();
        [$sponsor, $code] = $this->sponsorWithCode();

        $referee = $this->register('newcomer@example.test', $code);

        $referral = ReferralQuery::create()->findOneByRefereeCustomerId($referee->getId());
        self::assertInstanceOf(ReferralModel::class, $referral);
        self::assertSame($sponsor->getId(), $referral->getSponsorCustomerId());
        self::assertSame('registered', $referral->getStatus());
        self::assertSame($code, CustomerQuery::create()->findPk($referee->getId())?->getSponsor());

        $rewards = ReferralRewardQuery::create()->filterByReferralId($referral->getId())->find();
        self::assertCount(1, $rewards, 'the referee reward only: the sponsor is rewarded by a later step');
        $reward = $rewards->getFirst();
        self::assertSame('referee', $reward->getBeneficiary());
        self::assertSame($referee->getId(), $reward->getCustomerId());
        self::assertStringStartsWith('REF-', (string) $reward->getCouponCode());
        self::assertSame(12, \strlen((string) $reward->getCouponCode()));
    }

    public function testTheCouponMatchesItsBeneficiaryOnlyAndCanBeUsedOnce(): void
    {
        $this->settingsWithoutMinimumAmount();
        [, $code] = $this->sponsorWithCode();
        $referee = $this->register('newcomer@example.test', $code);
        $stranger = $this->factory->customer($this->factory->customerTitle());
        $couponCode = (string) ReferralRewardQuery::create()->findOneByCustomerId($referee->getId())?->getCouponCode();
        $factory = $this->getService(CouponFactory::class);
        $security = $this->getService(SecurityContext::class);

        $security->setCustomerUser($referee);
        self::assertTrue($factory->buildCouponFromCode($couponCode)->isMatching(), 'the beneficiary');

        $security->setCustomerUser($stranger);
        self::assertFalse($factory->buildCouponFromCode($couponCode)->isMatching(), 'another customer');

        $coupon = CouponQuery::create()->findOneByCode($couponCode);
        self::assertInstanceOf(Coupon::class, $coupon);
        self::assertSame(1, $coupon->getMaxUsage());
        self::assertSame(1, $coupon->getUsagesLeft($referee->getId()));

        self::assertTrue(CouponCustomerCountQuery::create()->takeOneUsage($coupon->getId(), $referee->getId()));

        $security->setCustomerUser($referee);
        self::assertSame(0, $coupon->getUsagesLeft($referee->getId()));
        $this->expectException(CouponNoUsageLeftException::class);
        $factory->buildCouponFromCode($couponCode);
    }

    public function testTheCouponKeepsItsMinimumAmountAsAConditionAndItsValidity(): void
    {
        [, $code] = $this->sponsorWithCode();
        $referee = $this->register('newcomer@example.test', $code);
        $reward = ReferralRewardQuery::create()->findOneByCustomerId($referee->getId());
        $coupon = CouponQuery::create()->findOneByCode((string) $reward?->getCouponCode());

        self::assertInstanceOf(Coupon::class, $coupon);
        self::assertSame('30.000000', $reward->getMinimumAmount());
        $conditions = (string) base64_decode((string) $coupon->getSerializedConditions(), true);
        self::assertStringContainsString('match_for_total_amount', $conditions);
        self::assertStringContainsString('for_some_customers', $conditions);
        self::assertStringNotContainsString('match_for_everyone', $conditions);
        self::assertEqualsWithDelta(time() + 90 * 86400, $coupon->getExpirationDate()->getTimestamp(), 5);
        self::assertTrue((bool) $coupon->getIsEnabled());
        self::assertFalse((bool) $coupon->getIsCumulative());
        self::assertTrue((bool) $coupon->getPerCustomerUsageCount());
    }

    public function testAPercentageRewardIsIssuedAsAPercentageCoupon(): void
    {
        $this->setSetting(Referral::CONFIG_REFEREE_VALUE_TYPE, 'percent');
        $this->setSetting(Referral::CONFIG_REFEREE_VALUE, '15');
        [, $code] = $this->sponsorWithCode();
        $referee = $this->register('newcomer@example.test', $code);
        $coupon = CouponQuery::create()->findOneByCode((string) ReferralRewardQuery::create()->findOneByCustomerId($referee->getId())?->getCouponCode());

        self::assertInstanceOf(Coupon::class, $coupon);
        self::assertSame('thelia.coupon.type.remove_x_percent', $coupon->getType());
        self::assertEquals(['percentage' => 15.0], $coupon->getEffects());
    }

    public function testAnUnusableCodeAttachesNothing(): void
    {
        $sponsor = $this->factory->customer($this->factory->customerTitle(), ['email' => 'sponsor@example.test']);
        $code = $this->getService(ReferralCodeProvider::class)->getOrCreateFor($sponsor)->getCode();

        $self = $this->register('sponsor+again@example.test', $code);
        $unknown = $this->register('other@example.test', 'ZZZZZZZZZZ');

        self::assertSame(0, ReferralQuery::create()->count());
        self::assertSame(0, ReferralRewardQuery::create()->count());
        self::assertNull(CustomerQuery::create()->findPk($self->getId())?->getSponsor());
        self::assertNull(CustomerQuery::create()->findPk($unknown->getId())?->getSponsor());
    }

    public function testASecondAttachmentOfTheSameAccountIsIgnored(): void
    {
        $this->settingsWithoutMinimumAmount();
        [, $first] = $this->sponsorWithCode();
        [, $second] = $this->sponsorWithCode();
        $referee = $this->register('newcomer@example.test', $first);

        $this->postCode($second);
        $this->getService(AttachReferralListener::class)->attach((new CustomerCreateOrUpdateMinimalEvent())->setCustomer($referee));

        self::assertSame(1, ReferralQuery::create()->filterByRefereeCustomerId($referee->getId())->count());
        self::assertSame($first, CustomerQuery::create()->findPk($referee->getId())?->getSponsor());
        self::assertSame(1, ReferralRewardQuery::create()->filterByCustomerId($referee->getId())->count());
    }

    public function testAPostedCustomerIdOrNoCodeAttachesNothing(): void
    {
        [, $code] = $this->sponsorWithCode();

        $this->getService(RequestStack::class)->getMainRequest()?->request->replace(['referral_code' => $code, 'customer_id' => 1]);
        $referee = $this->createAccount('plain@example.test');

        self::assertSame(0, ReferralQuery::create()->count(), 'the code is read from the sign-up form only');
    }

    public function testTheIssuerRevokesAnUnusedCouponAndKeepsAUsedOne(): void
    {
        $this->settingsWithoutMinimumAmount();
        [, $code] = $this->sponsorWithCode();
        $referee = $this->register('newcomer@example.test', $code);
        $reward = ReferralRewardQuery::create()->findOneByCustomerId($referee->getId());
        self::assertInstanceOf(ReferralReward::class, $reward);
        $issuer = $this->getService(CouponRewardIssuer::class);
        self::assertTrue($issuer->supports('coupon'));
        self::assertFalse($issuer->supports('points'));

        $coupon = CouponQuery::create()->findPk($reward->getCouponId());
        CouponCustomerCountQuery::create()->takeOneUsage($coupon->getId(), $referee->getId());
        $issuer->revoke($reward);
        self::assertTrue((bool) CouponQuery::create()->findPk($reward->getCouponId())?->getIsEnabled(), 'a used coupon stays');

        CouponCustomerCountQuery::create()->giveBackOneUsage($coupon->getId(), $referee->getId());
        $issuer->revoke($reward);
        self::assertFalse((bool) CouponQuery::create()->findPk($reward->getCouponId())?->getIsEnabled(), 'an unused coupon is disabled');
        self::assertNotNull(ReferralRewardQuery::create()->findPk($reward->getId()), 'the row stays');
    }

    /**
     * @return array{Customer, string}
     */
    private function sponsorWithCode(): array
    {
        $sponsor = $this->factory->customer($this->factory->customerTitle());

        return [$sponsor, $this->getService(ReferralCodeProvider::class)->getOrCreateFor($sponsor)->getCode()];
    }

    private function register(string $email, string $code): Customer
    {
        $this->postCode($code);

        return $this->createAccount($email);
    }

    private function postCode(string $code): void
    {
        $this->getService(RequestStack::class)->getMainRequest()?->request->replace([
            RegisterFormListener::FORM_NAME => [RegisterFormListener::FIELD => $code],
        ]);
    }

    private function createAccount(string $email): Customer
    {
        $event = new CustomerCreateOrUpdateMinimalEvent();
        $event->bindArray([
            'firstname' => 'New',
            'lastname' => 'Comer',
            'email' => $email,
            'password' => 'Str0ng-Passw0rd!xyz',
            'title' => $this->factory->customerTitle()->getId(),
            'enabled' => true,
        ]);
        $this->dispatcher->dispatch($event, TheliaEvents::CREATE_CUSTOMER_MINIMAL);

        $customer = $event->getCustomer();
        self::assertInstanceOf(Customer::class, $customer);

        return $customer;
    }

    private function settingsWithoutMinimumAmount(): void
    {
        $this->setSetting(Referral::CONFIG_REFEREE_MINIMUM_AMOUNT, '0');
    }

    private function setSetting(string $name, string $value): void
    {
        Referral::setConfigValue($name, $value);
        ConfigQuery::resetCache();
    }
}
