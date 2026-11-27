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

namespace Referral\Hook\Theme;

use Referral\Account\RefereeWelcomeReader;
use Referral\Service\ReferralClock;
use Thelia\Core\Hook\Theme\ThemeHookInterface;
use Twig\Environment;

/**
 * The welcome banner on top of the account home: a referee is reminded of the coupon they have not used yet.
 */
final readonly class ReferralThemeHook implements ThemeHookInterface
{
    public const HOOK_NAME = 'account.top';

    public function __construct(
        private Environment $twig,
        private RefereeWelcomeReader $reader,
        private ReferralClock $clock,
    ) {
    }

    public function supports(string $hookName): bool
    {
        return self::HOOK_NAME === $hookName;
    }

    /**
     * @param array<string, mixed> $parameters
     */
    public function render(string $hookName, array $parameters): string
    {
        $customerId = $this->customerId($parameters['customer'] ?? null);

        if (0 === $customerId) {
            return '';
        }

        $welcome = $this->reader->find($customerId, $this->clock->nowToTheSecond());

        if (null === $welcome) {
            return '';
        }

        return $this->twig->render('@ReferralModule/theme_hook/welcome-banner.html.twig', ['welcome' => $welcome]);
    }

    /**
     * The hook parameter is an API resource: depending on how the theme reads it, an array or an object, and a null property is left out.
     */
    private function customerId(mixed $customer): int
    {
        return match (true) {
            \is_array($customer) => (int) ($customer['id'] ?? 0),
            \is_object($customer) && method_exists($customer, 'getId') => (int) $customer->getId(),
            \is_object($customer) && isset($customer->id) => (int) $customer->id,
            default => 0,
        };
    }
}
