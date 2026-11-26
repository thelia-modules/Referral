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

use Propel\Runtime\Exception\PropelException;
use Referral\Model\ReferralCode;
use Referral\Model\ReferralCodeQuery;
use Thelia\Model\Customer;

/**
 * The one code of a customer, created the first time it is asked for.
 *
 * Uniqueness belongs to the database: the code is inserted and a unique violation draws another one.
 */
final readonly class ReferralCodeProvider
{
    private const MAX_ATTEMPTS = 5;

    public function __construct(private ReferralCodeGeneratorInterface $generator)
    {
    }

    public function getOrCreateFor(Customer $customer): ReferralCode
    {
        $existing = ReferralCodeQuery::create()->findOneByCustomerId($customer->getId());
        if ($existing instanceof ReferralCode) {
            return $existing;
        }

        $lastFailure = null;

        for ($attempt = 0; $attempt < self::MAX_ATTEMPTS; ++$attempt) {
            $code = (new ReferralCode())
                ->setCustomerId($customer->getId())
                ->setCode($this->generator->generate());

            try {
                $code->save();

                return $code;
            } catch (PropelException $exception) {
                $lastFailure = $exception;

                // The customer got a code meanwhile (two requests at once): that one is the answer.
                $concurrent = ReferralCodeQuery::create()->findOneByCustomerId($customer->getId());
                if ($concurrent instanceof ReferralCode) {
                    return $concurrent;
                }
            }
        }

        throw new \RuntimeException('No free referral code after '.self::MAX_ATTEMPTS.' attempts.', 0, $lastFailure);
    }

    public function normalize(string $raw): string
    {
        return strtoupper(str_replace([' ', '-'], '', trim($raw)));
    }
}
