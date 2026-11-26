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

namespace Referral\EventListeners;

use Referral\Reward\UnusedCouponPurger;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Thelia\Core\Event\TheliaEvents;
use Thelia\Model\Customer;
use Thelia\Model\Event\CustomerEvent;

/**
 * Deletes the unused coupons of a customer's rewards before the customer goes: their reward rows then
 * cascade away, and the purger could no longer find the coupons they pointed to. Used coupons stay,
 * the orders carry their code.
 *
 * Runs before the core deletes the customer (priority 128, which refuses a customer who has orders:
 * such a customer is left alone here too).
 */
final readonly class CustomerDeletionListener implements EventSubscriberInterface
{
    public const PRIORITY = 256;

    public function __construct(private UnusedCouponPurger $purger)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            TheliaEvents::CUSTOMER_DELETEACCOUNT => ['purgeTheCouponsOf', self::PRIORITY],
        ];
    }

    public function purgeTheCouponsOf(CustomerEvent $event): void
    {
        $customer = $event->getModel();

        if (!$customer instanceof Customer || $customer->hasOrder()) {
            return;
        }

        $this->purger->purgeUnusedOf($customer->getId());
    }
}
