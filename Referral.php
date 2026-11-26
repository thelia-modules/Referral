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

namespace Referral;

use Propel\Runtime\Connection\ConnectionInterface;
use Referral\DependencyInjection\Compiler\PublicServicesForTestsPass;
use Referral\DependencyInjection\Compiler\RegisterModuleTranslationsPass;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\Loader\Configurator\ServicesConfigurator;
use Thelia\Core\Install\Database;
use Thelia\Module\BaseModule;

/**
 * Customer referral program: a sponsor shares a code, the referee registers with it,
 * and both are rewarded with a personal coupon.
 */
class Referral extends BaseModule
{
    public const MESSAGE_DOMAIN = 'referral';

    public const CONFIG_ENABLED = 'enabled';
    public const CONFIG_REFEREE_VALUE_TYPE = 'referee_value_type';
    public const CONFIG_REFEREE_VALUE = 'referee_value';
    public const CONFIG_REFEREE_MINIMUM_AMOUNT = 'referee_minimum_amount';
    public const CONFIG_SPONSOR_VALUE_TYPE = 'sponsor_value_type';
    public const CONFIG_SPONSOR_VALUE = 'sponsor_value';
    public const CONFIG_SPONSOR_MINIMUM_AMOUNT = 'sponsor_minimum_amount';
    public const CONFIG_COUPON_VALIDITY_DAYS = 'coupon_validity_days';
    public const CONFIG_TRIGGER_STATUS = 'trigger_status';
    public const CONFIG_MONTHLY_CAP = 'monthly_cap';
    public const CONFIG_CANCEL_ON_REFUND = 'cancel_on_refund';
    public const CONFIG_SAME_ADDRESS_CHECK = 'same_address_check';

    public const DEFAULTS = [
        self::CONFIG_ENABLED => '1',
        self::CONFIG_REFEREE_VALUE_TYPE => 'amount',
        self::CONFIG_REFEREE_VALUE => '10',
        self::CONFIG_REFEREE_MINIMUM_AMOUNT => '30',
        self::CONFIG_SPONSOR_VALUE_TYPE => 'amount',
        self::CONFIG_SPONSOR_VALUE => '10',
        self::CONFIG_SPONSOR_MINIMUM_AMOUNT => '0',
        self::CONFIG_COUPON_VALIDITY_DAYS => '90',
        self::CONFIG_TRIGGER_STATUS => 'paid',
        self::CONFIG_MONTHLY_CAP => '5',
        self::CONFIG_CANCEL_ON_REFUND => '1',
        self::CONFIG_SAME_ADDRESS_CHECK => '0',
    ];

    /**
     * @throws \Propel\Runtime\Exception\PropelException
     */
    public function postActivation(?ConnectionInterface $con = null): void
    {
        if (!self::getConfigValue('is_initialized')) {
            $database = new Database($con);
            $database->insertSql(null, [__DIR__.'/Config/thelia.sql']);
            self::setConfigValue('is_initialized', '1');
        }

        $this->ensureSettings();
    }

    /**
     * Writes the default of each setting the shop has no value for, and nothing else:
     * activating the module again never takes back what the merchant chose.
     */
    private function ensureSettings(): void
    {
        foreach (self::DEFAULTS as $name => $value) {
            if (null === self::getConfigValue($name)) {
                self::setConfigValue($name, $value);
            }
        }
    }

    public function destroy(?ConnectionInterface $con = null, $deleteModuleData = false): void
    {
        $database = new Database($con);
        $database->insertSql(null, [__DIR__.'/Config/destroy.sql']);
    }

    /**
     * TYPE_BEFORE_REMOVING is what makes the pass safe: autowiring has already run, so a
     * definition the container cannot build carries its error and is left private, then removed.
     *
     * @return list<array{0: CompilerPassInterface, 1: string}>
     */
    public static function getCompilers(): array
    {
        return [
            [new RegisterModuleTranslationsPass(__DIR__, self::MESSAGE_DOMAIN), PassConfig::TYPE_BEFORE_OPTIMIZATION],
            [new PublicServicesForTestsPass(), PassConfig::TYPE_BEFORE_REMOVING],
        ];
    }

    public static function configureServices(ServicesConfigurator $servicesConfigurator): void
    {
        $servicesConfigurator->load(self::getModuleCode().'\\', __DIR__)
            ->exclude([__DIR__.'/I18n/*', __DIR__.'/Tests/*'])
            ->autowire(true)
            ->autoconfigure(true);
    }
}
