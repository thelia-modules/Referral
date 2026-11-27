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

/**
 * Draws codes a person can read out loud: no 0/O, 1/I/L-style look-alikes.
 */
final readonly class ReferralCodeGenerator implements ReferralCodeGeneratorInterface
{
    public const ALPHABET = '23456789ABCDEFGHJKLMNPQRSTUVWXYZ';
    public const CODE_LENGTH = 10;

    public function generate(int $length = self::CODE_LENGTH): string
    {
        $lastIndex = \strlen(self::ALPHABET) - 1;
        $code = '';

        for ($position = 0; $position < $length; ++$position) {
            $code .= self::ALPHABET[random_int(0, $lastIndex)];
        }

        return $code;
    }
}
