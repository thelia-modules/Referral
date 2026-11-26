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

use Referral\Account\ReferralAccountReader;
use Referral\Service\ReferralClock;
use Referral\Service\ReferralCodeProvider;
use Referral\Service\ReferralSettings;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Thelia\Controller\Front\BaseFrontController;
use Thelia\Core\HttpKernel\Exception\RedirectException;
use Thelia\Domain\Customer\Service\AuthenticationReturnUrl;
use Thelia\Model\Customer;

/**
 * "Referral" in the customer account: the sponsor's code and link, the friends who signed up and what they brought.
 *
 * With the program off the page does not exist (404). The page is `no-store`: it holds the customer's own data
 * and no cookie of its own would keep the shared cache from taking it for a public page.
 */
final class ReferralAccountController extends BaseFrontController
{
    #[Route('/account/referral', name: 'referral.account', methods: ['GET'])]
    public function account(
        ReferralSettings $settings,
        ReferralCodeProvider $codeProvider,
        ReferralAccountReader $reader,
        ReferralClock $clock,
        UrlGeneratorInterface $urlGenerator,
    ): Response {
        if (!$settings->isEnabled()) {
            throw new NotFoundHttpException();
        }

        $customer = $this->signedInCustomer();
        $code = $codeProvider->getOrCreateFor($customer)->getCode();

        $response = $this->render('referral-account.html.twig', [
            'referral' => $reader->read(
                (int) $customer->getId(),
                $code,
                $urlGenerator->generate('referral.link', ['code' => $code], UrlGeneratorInterface::ABSOLUTE_URL),
                $clock->nowToTheSecond(),
            ),
        ]);

        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->remove('Expires');

        return $response;
    }

    /**
     * A guest of the checkout sits in the session like a customer and has no account: signing in, like any visitor.
     */
    private function signedInCustomer(): Customer
    {
        $customer = $this->getSecurityContext()->getCustomerUser();

        if (!$customer instanceof Customer || $customer->isGuest()) {
            throw new RedirectException($this->retrieveUrlFromRouteId('customer_login', [AuthenticationReturnUrl::PARAMETER => $this->getRequest()->getRequestUri()]));
        }

        return $customer;
    }
}
