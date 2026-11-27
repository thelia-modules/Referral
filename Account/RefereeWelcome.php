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

namespace Referral\Account;

/**
 * The welcome coupon a referee still has to use.
 */
final readonly class RefereeWelcome
{
    public function __construct(
        public string $code,
        public string $valueType,
        public float $value,
        public float $minimumAmount,
        public \DateTimeImmutable $expiresAt,
    ) {
    }
}
