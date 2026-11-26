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

namespace Referral\Service;

use Psr\Clock\ClockInterface;

/**
 * The time of the module, to the second.
 *
 * MySQL rounds the fraction of a second it is given and MariaDB drops it: a date written with its
 * microseconds may be stored one second later on one of them, possibly in the next month. Every date
 * the module writes, and every bound it compares them with, comes from here.
 */
final readonly class ReferralClock
{
    public function __construct(private ClockInterface $clock)
    {
    }

    public function nowToTheSecond(): \DateTimeImmutable
    {
        $now = $this->clock->now();

        return $now->setTime((int) $now->format('H'), (int) $now->format('i'), (int) $now->format('s'));
    }

    /**
     * @return array{\DateTimeImmutable, \DateTimeImmutable} the first second of the calendar month of
     *                                                       the moment, and the first second of the next one
     */
    public function monthOf(\DateTimeImmutable $moment): array
    {
        return [
            $moment->modify('first day of this month')->setTime(0, 0),
            $moment->modify('first day of next month')->setTime(0, 0),
        ];
    }
}
