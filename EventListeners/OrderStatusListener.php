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

use Referral\Model\ReferralQuery;
use Referral\Reward\SponsorRewardEngine;
use Referral\Reward\SponsorRewardMail;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Thelia\Core\Event\Order\OrderEvent;
use Thelia\Core\Event\TheliaEvents;

/**
 * Reviews the referral of a referee each time one of their orders changes status, whoever changes it:
 * the back office, the API, a payment module.
 *
 * Runs after the core (priority 128) has committed the new status. An order of a customer who is not
 * a referee costs one query on the unique index of the referee, and nothing else. The order is not
 * trusted as the event carries it: the review reads the orders from the database.
 */
final readonly class OrderStatusListener implements EventSubscriberInterface
{
    public const PRIORITY = 16;

    public function __construct(
        private SponsorRewardEngine $engine,
        private SponsorRewardMail $mail,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            TheliaEvents::ORDER_UPDATE_STATUS => ['reviewTheReferral', self::PRIORITY],
        ];
    }

    public function reviewTheReferral(OrderEvent $event): void
    {
        $customerId = $event->getOrder()->getCustomerId();
        if (null === $customerId) {
            return;
        }

        /** @var array{Id: int|string, Status: string, SponsorCustomerId: int|string|null}|null $referral */
        $referral = ReferralQuery::create()
            ->filterByRefereeCustomerId($customerId)
            ->select(['Id', 'Status', 'SponsorCustomerId'])
            ->findOne();

        // Capped and cancelled are final.
        if (null === $referral || !\in_array($referral['Status'], [SponsorRewardEngine::STATUS_REGISTERED, SponsorRewardEngine::STATUS_REWARDED], true)) {
            return;
        }

        $reward = $this->engine->review(
            (int) $referral['Id'],
            null === $referral['SponsorCustomerId'] ? null : (int) $referral['SponsorCustomerId'],
        );

        if (null !== $reward) {
            $this->mail->send($reward);
        }
    }
}
