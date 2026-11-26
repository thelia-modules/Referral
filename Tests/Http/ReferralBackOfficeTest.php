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

namespace Referral\Tests\Http;

use PHPUnit\Framework\Attributes\DataProvider;
use Referral\Model\Referral as ReferralModel;
use Referral\Model\ReferralReward;
use Referral\Model\ReferralRewardQuery;
use Referral\Referral;
use Referral\Reward\CouponRewardIssuer;
use Referral\Reward\RewardFactory;
use Referral\Service\ReferralCodeProvider;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Thelia\Core\Security\AccessManager;
use Thelia\Core\Security\Resource\AdminResources;
use Thelia\Model\Admin;
use Thelia\Model\ConfigQuery;
use Thelia\Model\CouponQuery;
use Thelia\Model\Customer;
use Thelia\Model\CustomerQuery;
use Thelia\Model\ModuleQuery;
use Thelia\Model\ProfileModule;
use Thelia\Test\FixtureFactory;
use Thelia\Test\Trait\RecordsSqlQueries;
use Thelia\Test\WebIntegrationTestCase;
use Thelia\Tests\Support\BackOffice\AdminSessionInjector;

/**
 * The back office of the program: the settings card, the list of referrals and the manual cancellation.
 */
final class ReferralBackOfficeTest extends WebIntegrationTestCase
{
    use RecordsSqlQueries;

    private const CONFIGURATION_URL = '/admin/module/Referral';
    private const LIST_URL = '/admin/module/Referral/referrals';
    private const SAVE_URL = '/admin/module/Referral/settings';

    private AdminSessionInjector $injector;

    /** @var array<string, ?string> */
    private array $previous = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->injector = new AdminSessionInjector();
        $this->getService(EventDispatcherInterface::class)->addSubscriber($this->injector);

        foreach (array_keys(Referral::DEFAULTS) as $name) {
            $this->previous[$name] = Referral::getConfigValue($name);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->previous as $name => $value) {
            null === $value ? ConfigQuery::create()->filterByName($name)->delete() : Referral::setConfigValue($name, $value);
        }
        ConfigQuery::resetCache();
        $this->injector->clear();
        $this->getService(EventDispatcherInterface::class)->removeSubscriber($this->injector);

        parent::tearDown();
    }

    public function testTheConfigurationPageCarriesTheSettingsAndTheLinkToTheList(): void
    {
        $this->loginAs($this->factory()->admin());
        $this->assertPageRenders(self::CONFIGURATION_URL);
        $crawler = $this->client->getCrawler();

        self::assertSame(1, $crawler->filter('[data-testid="referral-settings-card"]')->count());
        self::assertStringContainsString(self::LIST_URL, (string) $crawler->filter('[data-testid="referral-list-link"]')->attr('href'));
        foreach (array_keys(Referral::DEFAULTS) as $name) {
            self::assertSame(1, $crawler->filter('[name="referral_settings_form['.$name.']"]')->count(), $name);
        }
        self::assertStringContainsString('referral code is accepted at sign-up', (string) $this->client->getResponse()->getContent(), 'the checkbox says what it does in its label');
    }

    public function testASavedFormWritesEverySetting(): void
    {
        $this->loginAs($this->factory()->admin());
        $this->assertPageRenders(self::CONFIGURATION_URL);

        $form = $this->client->getCrawler()->filter('#referral-settings-save')->form();
        $this->client->submit($form, [
            'referral_settings_form[enabled]' => false,
            'referral_settings_form[same_address_check]' => true,
            'referral_settings_form[cancel_on_refund]' => false,
            'referral_settings_form[referee_value_type]' => 'percent',
            'referral_settings_form[referee_value]' => '15',
            'referral_settings_form[referee_minimum_amount]' => '40.5',
            'referral_settings_form[sponsor_value_type]' => 'amount',
            'referral_settings_form[sponsor_value]' => '7',
            'referral_settings_form[sponsor_minimum_amount]' => '0',
            'referral_settings_form[coupon_validity_days]' => '30',
            'referral_settings_form[trigger_status]' => 'sent',
            'referral_settings_form[monthly_cap]' => '0',
        ]);

        self::assertTrue($this->client->getResponse()->isRedirect());
        ConfigQuery::resetCache();
        self::assertSame('0', Referral::getConfigValue(Referral::CONFIG_ENABLED));
        self::assertSame('percent', Referral::getConfigValue(Referral::CONFIG_REFEREE_VALUE_TYPE));
        self::assertSame('15', Referral::getConfigValue(Referral::CONFIG_REFEREE_VALUE));
        self::assertSame('40.5', Referral::getConfigValue(Referral::CONFIG_REFEREE_MINIMUM_AMOUNT));
        self::assertSame('7', Referral::getConfigValue(Referral::CONFIG_SPONSOR_VALUE));
        self::assertSame('30', Referral::getConfigValue(Referral::CONFIG_COUPON_VALIDITY_DAYS));
        self::assertSame('sent', Referral::getConfigValue(Referral::CONFIG_TRIGGER_STATUS));
        self::assertSame('0', Referral::getConfigValue(Referral::CONFIG_MONTHLY_CAP));
        self::assertSame('0', Referral::getConfigValue(Referral::CONFIG_CANCEL_ON_REFUND));
        self::assertSame('1', Referral::getConfigValue(Referral::CONFIG_SAME_ADDRESS_CHECK));

        $this->client->followRedirect();
        self::assertSame(1, $this->client->getCrawler()->filter('[data-testid="bo-flash-success"]')->count());
    }

    /**
     * @return iterable<string, array{array<string, string>}>
     */
    public static function invalidSettings(): iterable
    {
        yield 'zero sponsor value' => [['referral_settings_form[sponsor_value]' => '0']];
        yield 'zero referee value' => [['referral_settings_form[referee_value]' => '0']];
        yield 'negative value' => [['referral_settings_form[sponsor_value]' => '-1']];
        yield 'percent above 100' => [['referral_settings_form[referee_value_type]' => 'percent', 'referral_settings_form[referee_value]' => '101']];
        yield 'zero validity' => [['referral_settings_form[coupon_validity_days]' => '0']];
        yield 'negative cap' => [['referral_settings_form[monthly_cap]' => '-1']];
        yield 'negative minimum' => [['referral_settings_form[referee_minimum_amount]' => '-5']];
        yield 'unknown trigger' => [['referral_settings_form[trigger_status]' => 'delivered']];
    }

    /**
     * @param array<string, string> $values
     */
    #[DataProvider('invalidSettings')]
    public function testAnInvalidFormWritesNothing(array $values): void
    {
        Referral::setConfigValue(Referral::CONFIG_SPONSOR_VALUE, '10');
        Referral::setConfigValue(Referral::CONFIG_REFEREE_VALUE, '10');
        $this->loginAs($this->factory()->admin());
        $this->assertPageRenders(self::CONFIGURATION_URL);

        $form = $this->client->getCrawler()->filter('#referral-settings-save')->form();
        foreach ($values as $field => $value) {
            if ('referral_settings_form[trigger_status]' === $field) {
                // A select refuses a value it does not list: post the field by hand.
                $form->disableValidation();
            }
        }
        $this->client->submit($form, $values);

        self::assertTrue($this->client->getResponse()->isRedirect());
        self::assertSame('10', Referral::getConfigValue(Referral::CONFIG_SPONSOR_VALUE));
        self::assertSame('10', Referral::getConfigValue(Referral::CONFIG_REFEREE_VALUE));
        self::assertSame('90', Referral::getConfigValue(Referral::CONFIG_COUPON_VALIDITY_DAYS));
        self::assertSame('paid', Referral::getConfigValue(Referral::CONFIG_TRIGGER_STATUS));
        $this->client->followRedirect();
        self::assertSame(1, $this->client->getCrawler()->filter('[data-testid="bo-flash-error"]')->count());
    }

    public function testTheListShowsTheReferralsWithTheirStatusReasonOrderAndRewards(): void
    {
        Referral::setConfigValue(Referral::CONFIG_MONTHLY_CAP, '3');
        $sponsor = $this->customer('Sponsor', 'Seller');
        $rewarded = $this->referral($sponsor, 'Rewarded', 'Friend', 'rewarded', null, true);
        $capped = $this->referral($sponsor, 'Capped', 'Friend', 'capped', 'monthly_cap');
        $cancelled = $this->referral($sponsor, 'Gone', 'Friend', 'cancelled', 'order_refunded');
        $order = $this->factory()->order(CustomerQuery::create()->findPk($rewarded->getRefereeCustomerId()));
        $rewarded->setTriggerOrderId($order->getId())->save();

        $this->loginAs($this->factory()->admin());
        $this->assertPageRenders(self::LIST_URL);
        $crawler = $this->client->getCrawler();

        self::assertSame(3, $crawler->filter('[data-testid="referral-row"]')->count());
        $rewardedRow = $crawler->filter('[data-referral-id="'.$rewarded->getId().'"]');
        self::assertStringContainsString('Sponsor Seller', $rewardedRow->text());
        self::assertStringContainsString('Rewarded Friend', $rewardedRow->text());
        self::assertStringContainsString($order->getRef(), $rewardedRow->text());
        self::assertSame('Rewarded', trim($rewardedRow->filter('[data-testid="referral-status"]')->text()));
        self::assertStringContainsString('REF-', $rewardedRow->filter('[data-testid="referral-reward"]')->text());
        self::assertSame(
            $this->customerUrl($sponsor),
            $rewardedRow->filter('td a')->first()->attr('href'),
            'the sponsor links to the customer sheet',
        );

        self::assertStringContainsString(
            'Monthly limit of 3 rewards reached for this sponsor',
            $crawler->filter('[data-referral-id="'.$capped->getId().'"] [data-testid="referral-reason"]')->text(),
        );
        self::assertStringContainsString(
            'The order that triggered the reward was refunded',
            $crawler->filter('[data-referral-id="'.$cancelled->getId().'"] [data-testid="referral-reason"]')->text(),
        );
    }

    public function testTheCountersHeadTheList(): void
    {
        $sponsor = $this->customer('Count', 'Sponsor');
        $this->referral($sponsor, 'One', 'Friend', 'rewarded', null, true);
        $second = $this->referral($sponsor, 'Two', 'Friend', 'rewarded', null, true);
        $reward = ReferralRewardQuery::create()->filterByReferralId($second->getId())->filterByBeneficiary('sponsor')->findOne();
        $this->getService(\Referral\Reward\RewardCanceller::class)->cancel($reward, 'test');

        $this->loginAs($this->factory()->admin());
        $this->assertPageRenders(self::LIST_URL);
        $crawler = $this->client->getCrawler();

        $issued = ReferralRewardQuery::create()->filterByStatus('issued')->count();
        $cancelled = ReferralRewardQuery::create()->filterByStatus('cancelled')->count();
        self::assertSame((string) \Referral\Model\ReferralQuery::create()->count(), trim($crawler->filter('[data-testid="referral-counter-referrals"]')->text()));
        self::assertSame((string) $issued, trim($crawler->filter('[data-testid="referral-counter-issued"]')->text()));
        self::assertSame((string) $cancelled, trim($crawler->filter('[data-testid="referral-counter-cancelled"]')->text()));
        self::assertGreaterThanOrEqual(1, $cancelled);
    }

    public function testTheStatusFilterKeepsTheMatchingReferralsOnly(): void
    {
        $sponsor = $this->customer('Filter', 'Sponsor');
        $this->referral($sponsor, 'Plain', 'Registered', 'registered');
        $capped = $this->referral($sponsor, 'Plain', 'Capped', 'capped', 'monthly_cap');

        $this->loginAs($this->factory()->admin());
        $this->assertPageRenders(self::LIST_URL.'?status=capped');
        $rows = $this->client->getCrawler()->filter('[data-testid="referral-row"]');

        self::assertGreaterThanOrEqual(1, $rows->count());
        foreach ($rows as $row) {
            self::assertInstanceOf(\DOMElement::class, $row);
            self::assertSame('capped', $row->getAttribute('data-status'));
        }
        self::assertSame(1, $this->client->getCrawler()->filter('[data-referral-id="'.$capped->getId().'"]')->count());
        self::assertSame(0, $this->client->getCrawler()->filter('td:contains("Plain Registered")')->count());
    }

    public function testTheListIsPaginated(): void
    {
        $sponsor = $this->customer('Page', 'Sponsor');
        for ($i = 0; $i < 22; ++$i) {
            $this->referral($sponsor, 'Paged'.$i, 'Friend', 'registered');
        }

        $this->loginAs($this->factory()->admin());
        $this->assertPageRenders(self::LIST_URL);
        self::assertSame(20, $this->client->getCrawler()->filter('[data-testid="referral-row"]')->count());
        self::assertSame(1, $this->client->getCrawler()->filter('[data-testid="referral-pagination"]')->count());

        $this->assertPageRenders(self::LIST_URL.'?page=2');
        self::assertGreaterThanOrEqual(1, $this->client->getCrawler()->filter('[data-testid="referral-row"]')->count());
        self::assertLessThanOrEqual(20, $this->client->getCrawler()->filter('[data-testid="referral-row"]')->count());
    }

    public function testThePageCostsTheSameNumberOfQueriesForTwoReferralsAndForTwenty(): void
    {
        $sponsor = $this->customer('Budget', 'Sponsor');
        $this->referral($sponsor, 'B1', 'Friend', 'rewarded', null, true);
        $this->referral($sponsor, 'B2', 'Friend', 'capped', 'monthly_cap');
        $this->loginAs($this->factory()->admin());
        $this->assertPageRenders(self::LIST_URL);

        $small = $this->referralQueries();

        for ($i = 0; $i < 18; ++$i) {
            $this->referral($sponsor, 'Bm'.$i, 'Friend', 0 === $i % 2 ? 'rewarded' : 'registered', null, 0 === $i % 2);
        }
        $this->assertPageRenders(self::LIST_URL);
        self::assertGreaterThanOrEqual(20, $this->client->getCrawler()->filter('[data-testid="referral-row"]')->count());

        $large = $this->referralQueries();
        self::assertSame($small, $large, 'The queries on the referral tables do not grow with the page.');
        self::assertLessThanOrEqual(5, $large, 'Counters, total, rows, rewards.');
    }

    public function testAnAdministratorCancelsAnIssuedRewardWithAReason(): void
    {
        [$reward, $coupon] = $this->issuedReward();
        $this->loginAs($this->factory()->admin());
        $this->assertPageRenders(self::LIST_URL);

        $form = $this->client->getCrawler()->filter('[data-reward-id="'.$reward->getId().'"] form')->form();
        $this->client->submit($form, ['reason' => 'Fraud suspected']);

        self::assertTrue($this->client->getResponse()->isRedirect());
        $reward->reload();
        self::assertSame('cancelled', $reward->getStatus());
        self::assertSame('Fraud suspected', $reward->getCancelReason());
        self::assertFalse((bool) CouponQuery::create()->findPk($coupon)?->getIsEnabled());
        $this->client->followRedirect();
        self::assertSame(1, $this->client->getCrawler()->filter('[data-testid="bo-flash-success"]')->count());
    }

    public function testACancellationWithoutAReasonIsRefused(): void
    {
        [$reward] = $this->issuedReward();
        $this->loginAs($this->factory()->admin());
        $this->assertPageRenders(self::LIST_URL);

        $form = $this->client->getCrawler()->filter('[data-reward-id="'.$reward->getId().'"] form')->form();
        foreach (['', '   ', str_repeat('x', 256)] as $reason) {
            $this->client->submit($form, ['reason' => $reason]);
            $reward->reload();
            self::assertSame('issued', $reward->getStatus(), 'reason "'.mb_substr($reason, 0, 5).'"');
            $this->client->followRedirect();
            self::assertSame(1, $this->client->getCrawler()->filter('[data-testid="bo-flash-error"]')->count());
            $this->assertPageRenders(self::LIST_URL);
            $form = $this->client->getCrawler()->filter('[data-reward-id="'.$reward->getId().'"] form')->form();
        }
    }

    public function testACancellationWithoutOrWithABadTokenIsRefused(): void
    {
        [$reward] = $this->issuedReward();
        $this->loginAs($this->factory()->admin());
        $this->assertPageRenders(self::LIST_URL);
        $url = '/admin/module/Referral/rewards/'.$reward->getId().'/cancel';

        $this->client->request('POST', $url, ['reason' => 'No token']);
        self::assertResponseStatusCodeSame(403);
        $this->client->request('POST', $url, ['reason' => 'Bad token', '_token' => 'nope']);
        self::assertResponseStatusCodeSame(403);
        $reward->reload();
        self::assertSame('issued', $reward->getStatus());
    }

    public function testAnAdministratorWhoOnlyViewsSeesTheListButCannotSaveNorCancel(): void
    {
        [$reward] = $this->issuedReward();
        $this->loginAs($this->viewOnlyAdmin());

        $this->assertPageRenders(self::CONFIGURATION_URL);
        self::assertSame(0, $this->client->getCrawler()->filter('#referral-settings-save')->count(), 'No save button without UPDATE.');

        $this->assertPageRenders(self::LIST_URL);
        self::assertSame(0, $this->client->getCrawler()->filter('[data-testid="referral-cancel"]')->count(), 'No cancel form without UPDATE.');
        self::assertGreaterThanOrEqual(1, $this->client->getCrawler()->filter('[data-testid="referral-row"]')->count());

        $this->client->request('POST', self::SAVE_URL, ['referral_settings_form' => ['sponsor_value' => '99']]);
        self::assertSame(403, $this->client->getResponse()->getStatusCode());
        self::assertNotSame('99', Referral::getConfigValue(Referral::CONFIG_SPONSOR_VALUE));

        $this->client->request('POST', '/admin/module/Referral/rewards/'.$reward->getId().'/cancel', ['reason' => 'Nope', '_token' => 'x']);
        self::assertSame(403, $this->client->getResponse()->getStatusCode());
        $reward->reload();
        self::assertSame('issued', $reward->getStatus());
    }

    public function testAnAdministratorWithoutTheModuleRightCannotSeeTheList(): void
    {
        $this->loginAs($this->factory()->restrictedAdmin([AdminResources::CUSTOMER => [AccessManager::VIEW]]));

        $this->client->request('GET', self::LIST_URL);

        self::assertSame(403, $this->client->getResponse()->getStatusCode());
    }

    public function testTheScreensRenderInFrench(): void
    {
        $this->loginAs($this->factory()->admin());
        $this->client->request('GET', self::LIST_URL.'?lang=fr');

        $html = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('Récompenses émises', $html);
        self::assertStringContainsString('Parrain', $html);
        $this->client->request('GET', self::LIST_URL.'?lang=en');
    }

    private function referralQueries(): int
    {
        $statements = $this->recordSqlQueries(fn () => $this->client->request('GET', self::LIST_URL));

        return \count(array_filter($statements, static fn (string $statement): bool => 1 === preg_match('/`referral(_reward)?`/', $statement)));
    }

    /**
     * @return array{ReferralReward, int}
     */
    private function issuedReward(): array
    {
        $referral = $this->referral($this->customer('Cancel', 'Sponsor'), 'Cancel', 'Friend', 'rewarded', null, true);
        $reward = ReferralRewardQuery::create()->filterByReferralId($referral->getId())->filterByBeneficiary('sponsor')->findOne();
        self::assertInstanceOf(ReferralReward::class, $reward);

        return [$reward, (int) $reward->getCouponId()];
    }

    private function customer(string $firstname, string $lastname): Customer
    {
        $factory = $this->factory();

        return $factory->customer($factory->customerTitle(), ['firstname' => $firstname, 'lastname' => $lastname]);
    }

    private function customerUrl(Customer $customer): string
    {
        return '/admin/customer/update?customer_id='.$customer->getId();
    }

    private function referral(Customer $sponsor, string $firstname, string $lastname, string $status, ?string $reason = null, bool $withSponsorReward = false): ReferralModel
    {
        $referee = $this->customer($firstname, $lastname);
        $code = $this->getService(ReferralCodeProvider::class)->getOrCreateFor($sponsor);
        $referral = (new ReferralModel())
            ->setSponsorCustomerId($sponsor->getId())
            ->setReferralCodeId($code->getId())
            ->setRefereeCustomerId($referee->getId())
            ->setStatus($status)
            ->setStatusReason($reason);
        $referral->save();

        if ($withSponsorReward) {
            $rewards = $this->getService(RewardFactory::class);
            $issuer = $this->getService(CouponRewardIssuer::class);
            $issuer->issue($rewards->referee($referral), $referee);
            $issuer->issue($rewards->sponsor($referral), $sponsor);
        }

        return $referral;
    }

    private function viewOnlyAdmin(): Admin
    {
        $admin = $this->factory()->restrictedAdmin([AdminResources::MODULE => [AccessManager::VIEW]]);
        $module = ModuleQuery::create()->findOneByCode('Referral');
        self::assertNotNull($module);
        $access = new AccessManager(0);
        $access->build([AccessManager::VIEW]);
        (new ProfileModule())
            ->setProfileId($admin->getProfileId())
            ->setModuleId($module->getId())
            ->setAccess($access->getAccessValue())
            ->save($this->getPropelConnection());

        return $admin;
    }

    /** Not createFixtureFactory(): it leaves a request on the stack, in front of the ones the client sends. */
    private function factory(): FixtureFactory
    {
        return new FixtureFactory($this->getPropelConnection());
    }

    private function loginAs(Admin $admin): void
    {
        $admin->eraseCredentials();
        $this->injector->setAdmin($admin);
    }
}
