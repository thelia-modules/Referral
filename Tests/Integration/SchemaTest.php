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

use Propel\Runtime\Exception\PropelException;
use Referral\Model\Referral;
use Referral\Model\ReferralCode;
use Referral\Model\ReferralQuery;
use Referral\Model\ReferralReward;
use Referral\Model\ReferralRewardQuery;
use Thelia\Test\IntegrationTestCase;

/**
 * The three tables are what every later lot writes into: a row survives a round trip with its
 * own values and the defaults of the contract, and a customer can be referred only once.
 */
final class SchemaTest extends IntegrationTestCase
{
    public function testACodeAReferralAndARewardAreReadBackWithTheirValues(): void
    {
        $connection = $this->getPropelConnection();
        $factory = $this->createFixtureFactory();
        $title = $factory->customerTitle();
        $sponsor = $factory->customer($title);
        $referee = $factory->customer($title);

        $code = (new ReferralCode())->setCustomerId($sponsor->getId())->setCode('SCHEMA01');
        $code->save($connection);

        $referral = (new Referral())
            ->setSponsorCustomerId($sponsor->getId())
            ->setReferralCodeId($code->getId())
            ->setRefereeCustomerId($referee->getId());
        $referral->save($connection);

        $reward = (new ReferralReward())
            ->setReferralId($referral->getId())
            ->setBeneficiary('sponsor')
            ->setCustomerId($sponsor->getId())
            ->setValueType('amount')
            ->setValue('10.500000');
        $reward->save($connection);

        $reloadedReferral = ReferralQuery::create()->findPk($referral->getId(), $connection);
        self::assertInstanceOf(Referral::class, $reloadedReferral);
        self::assertSame('registered', $reloadedReferral->getStatus());
        self::assertNull($reloadedReferral->getRewardedAt());
        self::assertNotNull($reloadedReferral->getCreatedAt());

        $reloadedReward = ReferralRewardQuery::create()->findPk($reward->getId(), $connection);
        self::assertInstanceOf(ReferralReward::class, $reloadedReward);
        self::assertSame('coupon', $reloadedReward->getRewardType());
        self::assertSame('issued', $reloadedReward->getStatus());
        self::assertSame(10.5, (float) $reloadedReward->getValue());
        self::assertSame(0.0, (float) $reloadedReward->getMinimumAmount());
    }

    public function testACustomerCannotBeReferredTwice(): void
    {
        $connection = $this->getPropelConnection();
        $factory = $this->createFixtureFactory();
        $title = $factory->customerTitle();
        $sponsor = $factory->customer($title);
        $referee = $factory->customer($title);

        (new Referral())->setSponsorCustomerId($sponsor->getId())->setRefereeCustomerId($referee->getId())->save($connection);

        $this->expectException(PropelException::class);

        (new Referral())->setSponsorCustomerId($sponsor->getId())->setRefereeCustomerId($referee->getId())->save($connection);
    }
}
