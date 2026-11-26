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

use PHPUnit\Framework\TestCase;
use Referral\Service\ReferralCodeGenerator;
use Referral\Service\ReferralCodeProvider;

final class ReferralCodeGeneratorTest extends TestCase
{
    public function testACodeHasTenCharactersOfTheReadableAlphabet(): void
    {
        $generator = new ReferralCodeGenerator();

        for ($draw = 0; $draw < 200; ++$draw) {
            $code = $generator->generate();
            self::assertSame(10, \strlen($code));
            self::assertSame('', str_replace(str_split('23456789ABCDEFGHJKLMNPQRSTUVWXYZ'), '', $code), $code.' leaves the alphabet');
        }
    }

    public function testTwoDrawsDiffer(): void
    {
        $generator = new ReferralCodeGenerator();

        self::assertNotSame($generator->generate(), $generator->generate());
    }

    public function testATypedCodeIsTrimmedUppercasedAndStrippedOfSpacesAndDashes(): void
    {
        $provider = new ReferralCodeProvider(new ReferralCodeGenerator());

        self::assertSame('ABCD2345EF', $provider->normalize("  abcd-2345 ef\t"));
        self::assertSame('', $provider->normalize(' - '));
    }
}
