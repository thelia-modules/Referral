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

/**
 * Told when a review holds the locks of a sponsor and of a referral, before it reads anything.
 *
 * No service implements it outside the test environment: the reviews of a shop run without one. A
 * test hangs a barrier on it to make two reviews meet there, which is what proves the lock.
 */
interface LockedSectionProbe
{
    public function reached(int $referralId): void;
}
