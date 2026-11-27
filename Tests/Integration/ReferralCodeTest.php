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

namespace Referral\Tests\Integration;

use Referral\Model\ReferralCode;
use Referral\Model\ReferralCodeQuery;
use Referral\Referral;
use Referral\Service\ReferralCodeChecker;
use Referral\Service\ReferralCodeGeneratorInterface;
use Referral\Service\ReferralCodeProvider;
use Thelia\Model\ConfigQuery;
use Thelia\Test\ActionIntegrationTestCase;

final class ReferralCodeTest extends ActionIntegrationTestCase
{
    public function testTheCodeOfACustomerIsCreatedOnceAndGivenBackAfterwards(): void
    {
        $customer = $this->factory->customer($this->factory->customerTitle());
        $provider = $this->getService(ReferralCodeProvider::class);

        $first = $provider->getOrCreateFor($customer);
        $second = $provider->getOrCreateFor($customer);

        self::assertSame($first->getId(), $second->getId());
        self::assertSame(10, \strlen($first->getCode()));
        self::assertSame(1, ReferralCodeQuery::create()->filterByCustomerId($customer->getId())->count());
        self::assertSame(1, (int) $first->getActive());
    }

    public function testAnExistingCodeIsReadBackWithoutDrawingAnotherOne(): void
    {
        $customer = $this->factory->customer($this->factory->customerTitle());
        $drawn = 0;
        $generator = new class($drawn) implements ReferralCodeGeneratorInterface {
            public function __construct(private int &$drawn)
            {
            }

            public function generate(int $length = 10): string
            {
                ++$this->drawn;

                return 'CCCCCCCCCC';
            }
        };
        $provider = new ReferralCodeProvider($generator);

        $provider->getOrCreateFor($customer);
        $provider->getOrCreateFor($customer);

        self::assertSame(1, $drawn);
    }

    public function testACollisionOnTheCodeDrawsAnotherOneWithoutLookingTheCodeUpFirst(): void
    {
        $title = $this->factory->customerTitle();
        $taker = $this->factory->customer($title);
        $newcomer = $this->factory->customer($title);
        (new ReferralCode())->setCustomerId($taker->getId())->setCode('AAAAAAAAAA')->save();

        $draws = ['AAAAAAAAAA', 'AAAAAAAAAA', 'BBBBBBBBBB'];
        $generator = new class($draws) implements ReferralCodeGeneratorInterface {
            /** @param list<string> $draws */
            public function __construct(private array $draws)
            {
            }

            public function generate(int $length = 10): string
            {
                return array_shift($this->draws);
            }
        };

        $code = (new ReferralCodeProvider($generator))->getOrCreateFor($newcomer);

        self::assertSame('BBBBBBBBBB', $code->getCode(), 'two collisions were retried');
    }

    public function testACodeIsUsableByAnotherAccountInAnyWritingOfIt(): void
    {
        $sponsor = $this->factory->customer($this->factory->customerTitle());
        $code = $this->getService(ReferralCodeProvider::class)->getOrCreateFor($sponsor);
        $checker = $this->getService(ReferralCodeChecker::class);

        $typed = ' '.substr(strtolower($code->getCode()), 0, 5).'-'.substr(strtolower($code->getCode()), 5).' ';

        self::assertSame($code->getId(), $checker->findUsableCode($typed, 'someone@example.test')?->getId());
        self::assertSame($code->getId(), $checker->findUsableCode($code->getCode(), null)?->getId());
    }

    public function testAnUnknownAnInactiveAndADisabledProgramAllAnswerNull(): void
    {
        $sponsor = $this->factory->customer($this->factory->customerTitle());
        $code = $this->getService(ReferralCodeProvider::class)->getOrCreateFor($sponsor);
        $checker = $this->getService(ReferralCodeChecker::class);

        self::assertNull($checker->findUsableCode('ZZZZZZZZZZ', 'someone@example.test'), 'unknown');
        self::assertNull($checker->findUsableCode('   ', 'someone@example.test'), 'blank');

        $code->setActive(0)->save();
        self::assertNull($checker->findUsableCode($code->getCode(), 'someone@example.test'), 'inactive');

        $code->setActive(1)->save();
        $this->setSetting(Referral::CONFIG_ENABLED, '0');
        self::assertNull($checker->findUsableCode($code->getCode(), 'someone@example.test'), 'program disabled');

        $this->setSetting(Referral::CONFIG_ENABLED, '1');
        self::assertNotNull($checker->findUsableCode($code->getCode(), 'someone@example.test'), 'and usable again');
    }

    public function testTheSponsorCannotRefersThemselvesWhateverTheSpellingOfTheirAddress(): void
    {
        $gmail = $this->factory->customer($this->factory->customerTitle(), ['email' => 'jane.doe@gmail.com']);
        $other = $this->factory->customer($this->factory->customerTitle(), ['email' => 'Pat+shop@Example.test']);
        $provider = $this->getService(ReferralCodeProvider::class);
        $checker = $this->getService(ReferralCodeChecker::class);
        $gmailCode = $provider->getOrCreateFor($gmail)->getCode();
        $otherCode = $provider->getOrCreateFor($other)->getCode();

        self::assertNull($checker->findUsableCode($gmailCode, 'janedoe@gmail.com'), 'gmail dots');
        self::assertNull($checker->findUsableCode($gmailCode, 'jane.doe+alias@GMAIL.com'), 'gmail alias');
        self::assertNull($checker->findUsableCode($otherCode, 'pat@example.test'), 'plus alias at another domain');
        self::assertNotNull($checker->findUsableCode($otherCode, 'pat.x@example.test'), 'dots count at another domain');
    }

    private function setSetting(string $name, string $value): void
    {
        Referral::setConfigValue($name, $value);
        ConfigQuery::resetCache();
    }
}
