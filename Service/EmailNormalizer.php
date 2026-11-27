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
 * Brings the spellings of one mailbox to a single form, to spot a sponsor who refers themselves:
 * case never counts, "+suffix" never counts, and at Gmail dots do not count either.
 */
final readonly class EmailNormalizer
{
    private const DOT_INSENSITIVE_DOMAINS = ['gmail.com', 'googlemail.com'];

    public function normalize(string $email): string
    {
        $email = mb_strtolower(trim($email));
        $separator = strrpos($email, '@');

        if (false === $separator) {
            return $email;
        }

        $local = substr($email, 0, $separator);
        $domain = substr($email, $separator + 1);

        $plus = strpos($local, '+');
        if (false !== $plus) {
            $local = substr($local, 0, $plus);
        }

        if (\in_array($domain, self::DOT_INSENSITIVE_DOMAINS, true)) {
            $local = str_replace('.', '', $local);
            $domain = 'gmail.com';
        }

        return $local.'@'.$domain;
    }
}
