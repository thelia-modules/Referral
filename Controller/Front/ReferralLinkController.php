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

namespace Referral\Controller\Front;

use Referral\Service\ReferralCodeProvider;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Thelia\Controller\Front\BaseFrontController;

/**
 * The link a sponsor shares: it keeps the code for the sign-up form and sends the visitor there.
 * Nothing is looked up here, the code is checked when the form is sent.
 */
final class ReferralLinkController extends BaseFrontController
{
    public const SESSION_KEY = 'referral.code';

    #[Route('/referral/{code}', name: 'referral.link', requirements: ['code' => '[A-Za-z0-9-]{4,20}'], methods: ['GET'])]
    public function link(string $code, Request $request, ReferralCodeProvider $provider): RedirectResponse
    {
        $request->getSession()->set(self::SESSION_KEY, $provider->normalize($code));

        return $this->generateRedirectFromRoute('customer_register');
    }
}
