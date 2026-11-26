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

namespace Referral\Account;

use FlexyBundle\AccountMenu\AccountMenuItem;
use FlexyBundle\AccountMenu\AccountMenuItemProviderInterface;
use Referral\Referral;
use Referral\Service\ReferralSettings;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The "Referral" tab of the Flexy customer account, only while the program is on: with it off the page answers 404.
 * Runs on every account page: the setting comes from the cached module configuration, no query.
 *
 * `Referral::configureServices()` leaves this class out when the theme is not installed, as its interface would be missing.
 */
final readonly class ReferralAccountMenuItemProvider implements AccountMenuItemProviderInterface
{
    public const SLUG = 'referral';

    public function __construct(
        private ReferralSettings $settings,
        private UrlGeneratorInterface $urlGenerator,
        #[Autowire(service: 'translator')]
        private TranslatorInterface $translator,
    ) {
    }

    public function getItems(): iterable
    {
        if (!$this->settings->isEnabled()) {
            return [];
        }

        return [
            new AccountMenuItem(
                self::SLUG,
                $this->translator->trans('Referral', [], Referral::MESSAGE_DOMAIN.'.fo.default'),
                $this->urlGenerator->generate('referral.account'),
                475,
            ),
        ];
    }
}
