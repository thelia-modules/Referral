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

namespace Referral\Tests\Unit\Service;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Referral\Service\EmailNormalizer;

final class EmailNormalizerTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function spellings(): iterable
    {
        yield 'case and spaces' => ['  Jane.Doe@Example.COM ', 'jane.doe@example.com'];
        yield 'plus suffix at another domain' => ['jane+promo@example.com', 'jane@example.com'];
        yield 'dots are kept at another domain' => ['jane.doe+a@example.com', 'jane.doe@example.com'];
        yield 'gmail drops dots' => ['J.a.n.e@gmail.com', 'jane@gmail.com'];
        yield 'gmail drops dots and suffix' => ['j.ane+shop@GMAIL.com', 'jane@gmail.com'];
        yield 'googlemail is gmail' => ['ja.ne@googlemail.com', 'jane@gmail.com'];
        yield 'no at sign' => ['Not-An-Email', 'not-an-email'];
    }

    #[DataProvider('spellings')]
    public function testASpellingIsBroughtToItsCanonicalForm(string $given, string $expected): void
    {
        self::assertSame($expected, (new EmailNormalizer())->normalize($given));
    }
}
