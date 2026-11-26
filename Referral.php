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

use FlexyBundle\AccountMenu\AccountMenuItemProviderInterface;
use Propel\Runtime\Connection\ConnectionInterface;
use Propel\Runtime\Propel;
use Referral\DependencyInjection\Compiler\LockedSectionProbeForTestsPass;
use Referral\DependencyInjection\Compiler\PublicServicesForTestsPass;
use Referral\DependencyInjection\Compiler\RegisterModuleTranslationsPass;
use Referral\Reward\RewardIssuerInterface;
use Referral\Reward\UnusedCouponPurger;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ServicesConfigurator;
use Thelia\Core\Install\Database;
use Thelia\Model\LangQuery;
use Thelia\Model\Message;
use Thelia\Model\MessageQuery;
use Thelia\Model\ModuleConfigQuery;
use Thelia\Module\BaseModule;

/**
 * Customer referral program: a sponsor shares a code, the referee registers with it,
 * and both are rewarded with a personal coupon.
 */
class Referral extends BaseModule
{
    public const MESSAGE_DOMAIN = 'referral';

    public const MESSAGE_SPONSOR_REWARD = 'referral_sponsor_reward';

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
        // The tables decide, not the flag alone: a flag that survived a deletion must not keep the tables
        // from being created, and tables that survived it (data kept) must not be dropped by the script.
        if (!self::tablesExist($con ?? Propel::getConnection())) {
            $database = new Database($con);
            $database->insertSql(null, [__DIR__.'/Config/thelia.sql']);
        }

        self::setConfigValue('is_initialized', '1');

        $this->ensureSettings();
        $this->ensureMessages($con);
    }

    private static function tablesExist(ConnectionInterface $connection): bool
    {
        return false !== $connection->query("SHOW TABLES LIKE 'referral_reward'")->fetchColumn();
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

    /**
     * Creates the messages of the module, and gives the ones that already exist the languages of the shop
     * they lack: a title or a subject that is there is kept. The text of a message is its template,
     * `templates/email/default/<name>.html.twig` and `.txt.twig`; its subject is compiled by Twig.
     *
     * @throws \Propel\Runtime\Exception\PropelException
     */
    private function ensureMessages(?ConnectionInterface $con = null): void
    {
        $messages = [
            self::MESSAGE_SPONSOR_REWARD => [
                'referral-sponsor-reward.html', 'referral-sponsor-reward.txt',
                ['Referral - Sponsor reward', 'Parrainage - Récompense du parrain'],
                ['{{ referee_firstname }} placed a first order: here is your reward', '{{ referee_firstname }} a passé une première commande : voici votre récompense'],
            ],
        ];

        foreach ($messages as $name => [$htmlTemplate, $textTemplate, $titles, $subjects]) {
            $message = MessageQuery::create()->findOneByName($name) ?? (new Message())
                ->setName($name)
                ->setHtmlTemplateFileName($htmlTemplate)
                ->setTextTemplateFileName($textTemplate)
                ->setSecured(0);

            foreach (LangQuery::create()->find($con) as $lang) {
                $locale = $lang->getLocale();
                $index = str_starts_with($locale, 'fr') ? 1 : 0;

                $message->setLocale($locale);

                if ('' === trim((string) $message->getTitle())) {
                    $message->setTitle($titles[$index]);
                }

                if ('' === trim((string) $message->getSubject())) {
                    $message->setSubject($subjects[$index]);
                }
            }

            $message->save($con);
        }
    }

    /**
     * The messages always go: they are the module's own text. With `$deleteModuleData` (the box of the
     * deletion dialog), the data goes too, in the order the foreign keys ask for: the coupons that were
     * never used (found through the rewards, so before the tables are dropped), the settings, then the
     * tables. `is_initialized` goes with the settings, so a reinstall creates the tables again. A coupon
     * that was used stays: the orders carry its code.
     *
     * Without it, tables, settings and coupons are kept together, `is_initialized` included: a reinstall
     * finds the program as it was.
     */
    public function destroy(?ConnectionInterface $con = null, $deleteModuleData = false): void
    {
        foreach ([self::MESSAGE_SPONSOR_REWARD] as $name) {
            MessageQuery::create()->findOneByName($name)?->delete($con);
        }

        if (!$deleteModuleData) {
            return;
        }

        $connection = $con ?? Propel::getConnection();

        if (self::tablesExist($connection)) {
            UnusedCouponPurger::deleteAllUnused($connection);
        }

        ModuleConfigQuery::create()->filterByModuleId(self::getModuleId())->delete($connection);

        $database = new Database($connection);
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
            [new LockedSectionProbeForTestsPass(), PassConfig::TYPE_BEFORE_OPTIMIZATION],
            [new PublicServicesForTestsPass(), PassConfig::TYPE_BEFORE_REMOVING],
        ];
    }

    /**
     * The referral code a visitor types is checked against the database: a budget per client keeps
     * the form from being used to walk through the codes.
     */
    public static function loadConfiguration(ContainerBuilder $container): void
    {
        $container->loadFromExtension('framework', [
            'rate_limiter' => [
                'referral_code_check' => [
                    'policy' => 'sliding_window',
                    'limit' => 10,
                    'interval' => '15 minutes',
                ],
            ],
        ]);
    }

    public static function configureServices(ServicesConfigurator $servicesConfigurator): void
    {
        $servicesConfigurator->instanceof(RewardIssuerInterface::class)->tag('referral.reward_issuer');

        $servicesConfigurator->load(self::getModuleCode().'\\', __DIR__)
            ->exclude([
                __DIR__.'/I18n/*',
                __DIR__.'/Tests/*',
                // The account tab belongs to the Flexy theme: without it the interface does not exist and the class could not be loaded.
                ...(interface_exists(AccountMenuItemProviderInterface::class) ? [] : [__DIR__.'/Account/ReferralAccountMenuItemProvider.php']),
            ])
            ->autowire(true)
            ->autoconfigure(true);
    }
}
