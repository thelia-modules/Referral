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

use Referral\Referral;
use Symfony\Component\HttpFoundation\Request;
use Thelia\Core\Translation\Translator;

/**
 * Translates a back-office string of the module in the language of the administrator.
 *
 * The strings are in the main catalogue of the module (the domain the Thelia translator knows, as StockAlert does): its
 * template catalogues are registered with the framework translator only.
 *
 * The Thelia translator reads the language of the shop session when no locale is given, which is the
 * front-office language: the back office puts the administrator's language on the request instead.
 */
final class BackOfficeTranslation
{
    public const DOMAIN = Referral::MESSAGE_DOMAIN;

    /**
     * @param array<string, string|int> $parameters
     */
    public static function trans(string $identifier, ?Request $request, array $parameters = []): string
    {
        return Translator::getInstance()->trans($identifier, $parameters, self::DOMAIN, $request?->getLocale());
    }
}
