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

use Referral\Model\ReferralReward;
use Referral\Referral;
use Referral\Service\ReferralCodeGenerator;
use Referral\Service\ReferralSettings;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Thelia\Condition\ConditionCollection;
use Thelia\Condition\ConditionFactory;
use Thelia\Condition\Implementation\ForSomeCustomers;
use Thelia\Condition\Implementation\MatchForTotalAmount;
use Thelia\Condition\Operators;
use Thelia\Core\Event\Coupon\CouponCreateOrUpdateEvent;
use Thelia\Core\Event\TheliaEvents;
use Thelia\Domain\Promotion\Coupon\Type\RemoveXPercent;
use Thelia\Model\Coupon;
use Thelia\Model\CouponCustomerCountQuery;
use Thelia\Model\CouponQuery;
use Thelia\Model\Currency;
use Thelia\Model\Customer;
use Thelia\Model\Lang;
use Thelia\Model\LangQuery;

/**
 * Issues a reward as a personal coupon of the core coupon engine: one use, for the beneficiary only.
 * The core counts the use (coupon_customer_count); nothing is counted here.
 */
final readonly class CouponRewardIssuer implements RewardIssuerInterface
{
    private const CODE_PREFIX = 'REF-';
    private const CODE_LENGTH = 8;

    public function __construct(
        private EventDispatcherInterface $dispatcher,
        private ConditionFactory $conditionFactory,
        private ReferralCodeGenerator $codeGenerator,
        private ReferralSettings $settings,
        private TranslatorInterface $translator,
    ) {
    }

    public function supports(string $rewardType): bool
    {
        return 'coupon' === $rewardType;
    }

    public function issue(ReferralReward $reward, Customer $beneficiary): void
    {
        $isPercent = 'percent' === $reward->getValueType();
        $titleKey = RewardFactory::BENEFICIARY_SPONSOR === $reward->getBeneficiary() ? 'Referral reward' : 'Referral welcome offer';
        $code = self::CODE_PREFIX.$this->codeGenerator->generate(self::CODE_LENGTH);
        $defaultLocale = Lang::getDefaultLanguage()->getLocale();
        $validityDays = max(1, (int) $this->settings->get(Referral::CONFIG_COUPON_VALIDITY_DAYS));

        $event = new CouponCreateOrUpdateEvent(
            $code,
            $isPercent ? 'thelia.coupon.type.remove_x_percent' : 'thelia.coupon.type.remove_x_amount',
            $this->translator->trans($titleKey, [], Referral::MESSAGE_DOMAIN, $defaultLocale),
            [$isPercent ? RemoveXPercent::INPUT_PERCENTAGE_NAME : 'amount' => (float) $reward->getValue()],
            null,
            null,
            true,
            (new \DateTime())->setTimestamp(time() + $validityDays * 86400),
            true,
            false,
            false,
            1,
            $defaultLocale,
            [],
            [],
            true,
        );
        $this->dispatcher->dispatch($event, TheliaEvents::COUPON_CREATE);

        // The create action always writes a "for everyone" condition: the real ones come second.
        $coupon = $event->getCouponModel();
        $event->setConditions($this->conditionsFor($reward, $beneficiary));
        $this->dispatcher->dispatch($event, TheliaEvents::COUPON_CONDITION_UPDATE);

        $this->translateTitle($coupon, $titleKey, $defaultLocale);

        $reward
            ->setCouponId($coupon->getId())
            ->setCouponCode($code)
            ->save();
    }

    public function revoke(ReferralReward $reward): void
    {
        $couponId = $reward->getCouponId();
        if (null === $couponId) {
            return;
        }

        $coupon = CouponQuery::create()->findPk($couponId);
        if (!$coupon instanceof Coupon) {
            return;
        }

        $uses = (int) CouponCustomerCountQuery::create()
            ->filterByCouponId($couponId)
            ->withColumn('SUM(count)', 'uses')
            ->select('uses')
            ->findOne();

        if ($uses > 0) {
            return;
        }

        $coupon->setIsEnabled(false)->save();
    }

    private function conditionsFor(ReferralReward $reward, Customer $beneficiary): ConditionCollection
    {
        $conditions = new ConditionCollection();
        $conditions[] = $this->conditionFactory->build(
            'thelia.condition.for_some_customers',
            [ForSomeCustomers::CUSTOMERS_LIST => Operators::IN],
            [ForSomeCustomers::CUSTOMERS_LIST => [$beneficiary->getId()]],
        );

        $minimum = (float) $reward->getMinimumAmount();
        if ($minimum > 0) {
            $conditions[] = $this->conditionFactory->build(
                'thelia.condition.match_for_total_amount',
                [
                    MatchForTotalAmount::CART_TOTAL => Operators::SUPERIOR_OR_EQUAL,
                    MatchForTotalAmount::CART_CURRENCY => Operators::EQUAL,
                ],
                [
                    MatchForTotalAmount::CART_TOTAL => $minimum,
                    MatchForTotalAmount::CART_CURRENCY => Currency::getDefaultCurrency()->getCode(),
                ],
            );
        }

        return $conditions;
    }

    private function translateTitle(Coupon $coupon, string $titleKey, string $defaultLocale): void
    {
        foreach (LangQuery::create()->filterByActive(true)->find() as $lang) {
            if ($lang->getLocale() === $defaultLocale) {
                continue;
            }

            $coupon->setLocale($lang->getLocale())
                ->setShortDescription('')
                ->setDescription('')
                ->setTitle($this->translator->trans($titleKey, [], Referral::MESSAGE_DOMAIN, $lang->getLocale()))
                ->save();
        }
    }
}
