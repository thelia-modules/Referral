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

use Referral\Model\ReferralCode;
use Referral\Model\ReferralCodeQuery;
use Thelia\Model\CustomerQuery;

/**
 * Says whether a typed code can be used by a new account. A code that does not exist and a code
 * that cannot be used answer the same way (null), so no caller can tell the two apart.
 */
final readonly class ReferralCodeChecker
{
    public function __construct(
        private ReferralSettings $settings,
        private ReferralCodeProvider $provider,
        private EmailNormalizer $emailNormalizer,
    ) {
    }

    public function findUsableCode(string $raw, ?string $newAccountEmail): ?ReferralCode
    {
        if (!$this->settings->isEnabled()) {
            return null;
        }

        $normalized = $this->provider->normalize($raw);
        if ('' === $normalized) {
            return null;
        }

        $code = ReferralCodeQuery::create()->findOneByCode($normalized);
        if (!$code instanceof ReferralCode || 1 !== (int) $code->getActive()) {
            return null;
        }

        $sponsor = CustomerQuery::create()->findPk($code->getCustomerId());
        if (null === $sponsor) {
            return null;
        }

        if (null !== $newAccountEmail
            && $this->emailNormalizer->normalize((string) $sponsor->getEmail()) === $this->emailNormalizer->normalize($newAccountEmail)) {
            return null;
        }

        return $code;
    }
}
