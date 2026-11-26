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

use Referral\Model\Referral as ReferralModel;
use Referral\Model\ReferralCodeQuery;
use Referral\Model\ReferralQuery;
use Referral\Model\ReferralRewardQuery;
use Referral\Privacy\ReferralPersonalDataProvider;
use Referral\Reward\CouponRewardIssuer;
use Referral\Reward\RewardFactory;
use Referral\Service\ReferralCodeProvider;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Thelia\Core\Event\Customer\CustomerAnonymizeEvent;
use Thelia\Core\Event\TheliaEvents;
use Thelia\Domain\Customer\Service\CustomerPersonalDataExporter;
use Thelia\Model\CouponQuery;
use Thelia\Model\Customer;
use Thelia\Test\ActionIntegrationTestCase;

/**
 * The referral data of a customer in the export and the anonymization of the core.
 */
final class PersonalDataTest extends ActionIntegrationTestCase
{
    public function testTheExportCarriesTheCodeTheSponsoredFriendsByFirstNameAndTheRewards(): void
    {
        [$sponsor, $referral, $referee] = $this->sponsorWithFriend('Amelie', 'Hidden-Surname');

        $export = $this->getService(CustomerPersonalDataExporter::class)->export($sponsor);
        $section = $export[ReferralPersonalDataProvider::SECTION];

        self::assertSame(ReferralCodeQuery::create()->findOneByCustomerId($sponsor->getId())?->getCode(), $section['code']['code']);
        self::assertCount(1, $section['as_sponsor']);
        self::assertSame('Amelie', $section['as_sponsor'][0]['referee_firstname']);
        self::assertSame('rewarded', $section['as_sponsor'][0]['status']);
        self::assertNull($section['as_referee']);
        self::assertCount(1, $section['rewards']);
        self::assertSame('sponsor', $section['rewards'][0]['beneficiary']);
        self::assertStringStartsWith('REF-', $section['rewards'][0]['coupon_code']);
        self::assertStringNotContainsString('Hidden-Surname', json_encode($section, \JSON_THROW_ON_ERROR), 'the friend\'s last name is not the sponsor\'s data');
        self::assertStringNotContainsString($referee->getEmail(), json_encode($section, \JSON_THROW_ON_ERROR));
    }

    public function testTheExportOfAFriendCarriesTheirSignUpAndTheirWelcomeReward(): void
    {
        [, $referral, $referee] = $this->sponsorWithFriend('Amelie', 'Friend');

        $section = $this->getService(CustomerPersonalDataExporter::class)->export($referee)[ReferralPersonalDataProvider::SECTION];

        self::assertSame('rewarded', $section['as_referee']['status']);
        self::assertSame([], $section['as_sponsor']);
        self::assertNull($section['code']);
        self::assertSame('referee', $section['rewards'][0]['beneficiary']);
    }

    public function testACustomerOutsideTheProgramHasAnEmptySection(): void
    {
        $customer = $this->factory->customer($this->factory->customerTitle());

        self::assertSame(
            ['code' => null, 'as_sponsor' => [], 'as_referee' => null, 'rewards' => []],
            $this->getService(CustomerPersonalDataExporter::class)->export($customer)[ReferralPersonalDataProvider::SECTION],
        );
    }

    public function testAnonymizingASponsorDeactivatesTheCodeAndLeavesTheFriendsRewardsValid(): void
    {
        [$sponsor, $referral, $referee] = $this->sponsorWithFriend('Amelie', 'Friend');
        $welcome = ReferralRewardQuery::create()->filterByReferralId($referral->getId())->filterByBeneficiary('referee')->findOne();
        self::assertNotNull($welcome);
        $welcomeCoupon = CouponQuery::create()->findPk($welcome->getCouponId());
        self::assertTrue((bool) $welcomeCoupon?->getIsEnabled());

        $this->anonymize($sponsor);

        self::assertSame(0, (int) ReferralCodeQuery::create()->findOneByCustomerId($sponsor->getId())?->getActive());
        $welcome->reload();
        self::assertSame('issued', $welcome->getStatus());
        self::assertTrue((bool) CouponQuery::create()->findPk($welcome->getCouponId())?->getIsEnabled());
        self::assertSame(1, ReferralQuery::create()->filterByRefereeCustomerId($referee->getId())->count(), 'the referral row stays');
    }

    public function testAnonymizingAFriendKeepsTheReferralForTheStatistics(): void
    {
        [$sponsor, $referral, $referee] = $this->sponsorWithFriend('Amelie', 'Friend');

        $this->anonymize($referee);

        $row = ReferralQuery::create()->findPk($referral->getId());
        self::assertNotNull($row);
        self::assertSame($referee->getId(), $row->getRefereeCustomerId());
        self::assertSame('rewarded', $row->getStatus());
        self::assertSame(1, (int) ReferralCodeQuery::create()->findOneByCustomerId($sponsor->getId())?->getActive(), 'the sponsor\'s code is untouched');
    }

    /**
     * @return array{Customer, ReferralModel, Customer}
     */
    private function sponsorWithFriend(string $firstname, string $lastname): array
    {
        $sponsor = $this->factory->customer($this->factory->customerTitle());
        $code = $this->getService(ReferralCodeProvider::class)->getOrCreateFor($sponsor);
        $referee = $this->factory->customer($this->factory->customerTitle(), ['firstname' => $firstname, 'lastname' => $lastname]);
        $referral = (new ReferralModel())
            ->setSponsorCustomerId($sponsor->getId())
            ->setReferralCodeId($code->getId())
            ->setRefereeCustomerId($referee->getId())
            ->setStatus('rewarded');
        $referral->save();

        $rewards = $this->getService(RewardFactory::class);
        $issuer = $this->getService(CouponRewardIssuer::class);
        $issuer->issue($rewards->referee($referral), $referee);
        $issuer->issue($rewards->sponsor($referral), $sponsor);

        return [$sponsor, $referral, $referee];
    }

    private function anonymize(Customer $customer): void
    {
        $this->getService(EventDispatcherInterface::class)->dispatch(new CustomerAnonymizeEvent($customer), TheliaEvents::CUSTOMER_ANONYMIZE);
    }
}
