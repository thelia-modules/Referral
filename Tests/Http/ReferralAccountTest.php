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

use Referral\Model\Referral as ReferralModel;
use Referral\Model\ReferralCodeQuery;
use Referral\Model\ReferralReward;
use Referral\Model\ReferralRewardQuery;
use Referral\Referral;
use Referral\Reward\CouponRewardIssuer;
use Referral\Reward\RewardFactory;
use Referral\Service\ReferralCodeProvider;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Thelia\Model\ConfigQuery;
use Thelia\Model\CouponCustomerCount;
use Thelia\Model\CouponQuery;
use Thelia\Model\Customer;
use Thelia\Test\FixtureFactory;
use Thelia\Test\Trait\RecordsSqlQueries;
use Thelia\Test\WebIntegrationTestCase;
use Thelia\Tests\Support\Flexy\CustomerSessionInjector;

/**
 * The referral area of the Flexy account as a signed-in customer's browser gets it: the tab, the page of a
 * sponsor, the banner of a referee.
 */
final class ReferralAccountTest extends WebIntegrationTestCase
{
    use RecordsSqlQueries;

    private FixtureFactory $factory;
    private CustomerSessionInjector $injector;
    private Customer $sponsor;
    private string $code;

    protected function setUp(): void
    {
        parent::setUp();

        // Not createFixtureFactory(): it leaves a request on the stack, in front of the ones the client sends.
        $this->factory = new FixtureFactory($this->getPropelConnection());
        $this->injector = new CustomerSessionInjector();
        $this->getService(EventDispatcherInterface::class)->addSubscriber($this->injector);
        $this->sponsor = $this->factory->customer($this->factory->customerTitle());
        $this->code = $this->getService(ReferralCodeProvider::class)->getOrCreateFor($this->sponsor)->getCode();
    }

    protected function tearDown(): void
    {
        $this->injector->clear();
        $this->getService(EventDispatcherInterface::class)->removeSubscriber($this->injector);
        Referral::setConfigValue(Referral::CONFIG_ENABLED, '1');
        ConfigQuery::resetCache();

        parent::tearDown();
    }

    public function testAVisitorWhoIsNotSignedInIsSentToTheLoginPage(): void
    {
        $this->client->request('GET', '/account/referral');

        self::assertResponseRedirects();
        self::assertStringContainsString('/login', (string) $this->client->getResponse()->headers->get('Location'));
        self::assertStringContainsString('referral', urldecode((string) $this->client->getResponse()->headers->get('Location')), 'the page asked for travels with the redirection');
    }

    public function testASponsorSeesTheirCodeAndTheLinkToShare(): void
    {
        $this->injector->setCustomer($this->sponsor);

        $crawler = $this->client->request('GET', '/account/referral');

        self::assertResponseIsSuccessful();
        self::assertSame($this->code, trim($crawler->filter('[data-testid="referral-code"]')->text()));
        $link = (string) $crawler->filter('[data-testid="referral-link"]')->attr('value');
        self::assertStringStartsWith('http', $link, 'absolute');
        self::assertStringEndsWith('/referral/'.$this->code, $link);
        self::assertNotNull($crawler->filter('[data-testid="referral-link"]')->attr('readonly'));
        self::assertCount(0, $crawler->filter('[data-testid="referral-referee"]'));
        self::assertCount(1, $crawler->filter('[data-testid="referral-referees-empty"]'));
        self::assertStringContainsString('no-store', (string) $this->client->getResponse()->headers->get('Cache-Control'));
    }

    public function testTheCodeIsCreatedOnTheFirstVisit(): void
    {
        $newcomer = $this->factory->customer($this->factory->customerTitle());
        self::assertNull(ReferralCodeQuery::create()->findOneByCustomerId($newcomer->getId()));
        $this->injector->setCustomer($newcomer);

        $crawler = $this->client->request('GET', '/account/referral');

        $created = ReferralCodeQuery::create()->findOneByCustomerId($newcomer->getId());
        self::assertNotNull($created);
        self::assertSame($created->getCode(), trim($crawler->filter('[data-testid="referral-code"]')->text()));
    }

    public function testTheRefereesAreListedWithTheirStateAndTheRewardsAreTotalled(): void
    {
        $this->referee('Alice', 'Martin', 'registered');
        $pending = $this->referee('Bruno', 'Durand', 'registered');
        $this->factory->order($pending);
        $this->referee('Chloe', 'Lemaire', 'rewarded', 'amount', '10');
        $this->referee('Denis', 'Petit', 'rewarded', 'percent', '10');
        $this->referee('Emma', 'Roux', 'rewarded', 'percent', '10');
        $this->referee('Farid', 'Benali', 'capped');
        $this->referee('Gaelle', 'Noel', 'cancelled');
        $this->injector->setCustomer($this->sponsor);

        $crawler = $this->client->request('GET', '/account/referral');

        self::assertResponseIsSuccessful();
        $states = [];
        foreach ($crawler->filter('[data-testid="referral-referee"]') as $node) {
            $item = new Crawler($node);
            $states[trim($item->filter('strong')->text())] = [$item->attr('data-state'), trim($item->filter('.Tag')->text())];
        }
        // The latest sign-up first.
        self::assertSame([
            'Gaelle N.' => ['cancelled', 'Cancelled'],
            'Farid B.' => ['capped', 'Monthly limit reached'],
            'Emma R.' => ['rewarded', 'Rewarded'],
            'Denis P.' => ['rewarded', 'Rewarded'],
            'Chloe L.' => ['rewarded', 'Rewarded'],
            'Bruno D.' => ['pending', 'Reward on its way'],
            'Alice M.' => ['signed_up', 'Signed up'],
        ], $states);

        self::assertSame('€10.00, 2 × 10 %', preg_replace('/\s+/u', ' ', trim(str_replace('Total earned', '', $crawler->filter('[data-testid="referral-total"]')->text()))));
        self::assertCount(3, $crawler->filter('[data-testid="referral-reward"]'));
    }

    public function testTheRewardCodesOfTheSponsorSayWhetherTheyAreUsedOrExpired(): void
    {
        $this->referee('Chloe', 'Lemaire', 'rewarded', 'amount', '10');
        $this->referee('Denis', 'Petit', 'rewarded', 'amount', '10');
        $this->referee('Emma', 'Roux', 'rewarded', 'amount', '10');
        $codes = ReferralRewardQuery::create()->filterByBeneficiary('sponsor')->filterByCustomerId($this->sponsor->getId())->orderById()->find()->toArray();
        self::assertCount(3, $codes);

        (new CouponCustomerCount())->setCouponId((int) $codes[1]['CouponId'])->setCustomerId($this->sponsor->getId())->setCount(1)->save();
        $expired = CouponQuery::create()->findPk((int) $codes[2]['CouponId']);
        self::assertNotNull($expired);
        $expired->setExpirationDate(new \DateTime('-1 day'))->save();
        $this->injector->setCustomer($this->sponsor);

        $crawler = $this->client->request('GET', '/account/referral');

        $states = [];
        foreach ($crawler->filter('[data-testid="referral-reward"]') as $node) {
            $item = new Crawler($node);
            $states[trim($item->filter('strong')->text())] = [$item->attr('data-state'), trim($item->filter('.Tag')->text())];
        }
        self::assertSame([
            $codes[2]['CouponCode'] => ['expired', 'Expired'],
            $codes[1]['CouponCode'] => ['used', 'Used'],
            $codes[0]['CouponCode'] => ['unused', 'Unused'],
        ], $states);
        self::assertStringContainsString('Valid until', $crawler->filter('[data-testid="referral-reward"]')->first()->text());
    }

    public function testAnotherSponsorsRefereesAreNotListed(): void
    {
        $other = $this->factory->customer($this->factory->customerTitle());
        $this->referee('Zoe', 'Hidden', 'rewarded', 'amount', '10', $other);
        $this->injector->setCustomer($this->sponsor);

        $crawler = $this->client->request('GET', '/account/referral');

        self::assertCount(0, $crawler->filter('[data-testid="referral-referee"]'));
        self::assertCount(0, $crawler->filter('[data-testid="referral-reward"]'));
        self::assertStringNotContainsString('Zoe', $crawler->html());
    }

    public function testTheTabIsInTheAccountMenuAndTheCurrentOneOnItsPage(): void
    {
        $this->injector->setCustomer($this->sponsor);

        $crawler = $this->client->request('GET', '/account');

        self::assertResponseIsSuccessful();
        $links = $crawler->filter('.Subheader-links a')->each(static fn (Crawler $link): string => (string) $link->attr('href'));
        self::assertSame(['/account', '/account/orders', '/account/addresses', '/account/referral', '/account/purchase-lists', '/account/quick-order'], $links);
        self::assertSame('Referral', trim($crawler->filter('.Subheader-links a[href="/account/referral"]')->text()));

        $crawler = $this->client->request('GET', '/account/referral');
        self::assertCount(1, $crawler->filter('.Subheader-links a[href="/account/referral"][aria-current="page"]'));
    }

    public function testTheRefereeSeesTheirWelcomeCouponWhileItIsUnused(): void
    {
        $referee = $this->referee('Alice', 'Martin', 'registered');
        $reward = $this->welcomeRewardOf($referee);
        $this->injector->setCustomer($referee);

        $crawler = $this->client->request('GET', '/account');

        self::assertResponseIsSuccessful();
        $banner = $crawler->filter('[data-testid="referral-welcome"]');
        self::assertCount(1, $banner);
        $text = preg_replace('/\s+/u', ' ', trim($banner->text()));
        self::assertStringContainsString((string) $reward->getCouponCode(), $text);
        self::assertStringContainsString('€10.00 off your first order from €30.00', $text);
        self::assertStringContainsString('valid until', $text);
    }

    public function testTheBannerIsGoneOnceTheCouponIsUsed(): void
    {
        $referee = $this->referee('Alice', 'Martin', 'registered');
        $reward = $this->welcomeRewardOf($referee);
        (new CouponCustomerCount())->setCouponId((int) $reward->getCouponId())->setCustomerId($referee->getId())->setCount(1)->save();
        $this->injector->setCustomer($referee);

        $crawler = $this->client->request('GET', '/account');

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('[data-testid="referral-welcome"]'));
    }

    public function testTheBannerIsGoneOnceTheCouponIsExpired(): void
    {
        $referee = $this->referee('Alice', 'Martin', 'registered');
        $reward = $this->welcomeRewardOf($referee);
        CouponQuery::create()->findPk((int) $reward->getCouponId())?->setExpirationDate(new \DateTime('-1 day'))->save();
        $this->injector->setCustomer($referee);

        $crawler = $this->client->request('GET', '/account');

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('[data-testid="referral-welcome"]'));
    }

    public function testACustomerWhoIsNoRefereeHasNoBannerAndCostsOneIndexedLookup(): void
    {
        $this->injector->setCustomer($this->sponsor);
        $crawler = null;

        $statements = $this->recordSqlQueries(function () use (&$crawler): void {
            $crawler = $this->client->request('GET', '/account');
        });

        self::assertResponseIsSuccessful();
        self::assertInstanceOf(Crawler::class, $crawler);
        self::assertCount(0, $crawler->filter('[data-testid="referral-welcome"]'));
        self::assertSame(1, $this->countTouchingTheReferralTables($statements), 'the banner reads the reward table once');
    }

    public function testWithTheProgramOffTheTabIsHiddenAndThePageDoesNotExist(): void
    {
        $this->injector->setCustomer($this->sponsor);
        // A first request: a config write before it loses the session.
        $this->client->request('GET', '/account');
        self::assertCount(1, $this->client->getCrawler()->filter('.Subheader-links a[href="/account/referral"]'));

        Referral::setConfigValue(Referral::CONFIG_ENABLED, '0');
        ConfigQuery::resetCache();

        $crawler = $this->client->request('GET', '/account');
        self::assertCount(0, $crawler->filter('.Subheader-links a[href="/account/referral"]'));

        $this->client->request('GET', '/account/referral');
        self::assertResponseStatusCodeSame(404);
    }

    public function testTheLabelsRenderInFrench(): void
    {
        $this->referee('Alice', 'Martin', 'registered');
        $pending = $this->referee('Bruno', 'Durand', 'registered');
        $this->factory->order($pending);
        $this->injector->setCustomer($this->sponsor);

        $crawler = $this->client->request('GET', '/account/referral?lang=fr_FR');

        self::assertResponseIsSuccessful();
        self::assertSame('Parrainage', trim($crawler->filter('.Subheader-links a[href="/account/referral"]')->text()));
        self::assertStringContainsString('Votre code de parrainage', $crawler->html());
        $labels = $crawler->filter('[data-testid="referral-referee"] .Tag')->each(static fn (Crawler $tag): string => trim($tag->text()));
        self::assertSame(['Récompense en route', 'Inscrit'], $labels);
        self::assertStringContainsString('Inscrit le', $crawler->filter('[data-testid="referral-referee"]')->first()->text());
    }

    public function testTheBannerRendersInFrench(): void
    {
        $referee = $this->referee('Alice', 'Martin', 'registered');
        $this->welcomeRewardOf($referee);
        $this->injector->setCustomer($referee);

        $crawler = $this->client->request('GET', '/account?lang=fr_FR');

        $text = preg_replace('/\s+/u', ' ', trim($crawler->filter('[data-testid="referral-welcome"]')->text()));
        self::assertStringContainsString('Bienvenue ! Votre remise de parrainage', (string) $text);
        self::assertStringContainsString('de réduction sur votre première commande dès', (string) $text);
        self::assertStringContainsString('valable jusqu', (string) $text);
    }

    public function testTheRefereeListCostsOneQueryWhateverItsLength(): void
    {
        $this->referee('Alice', 'Martin', 'rewarded', 'amount', '10');
        $this->injector->setCustomer($this->sponsor);
        // A first request settles the session and the caches the page shares with every request.
        $this->client->request('GET', '/account/referral');

        $one = $this->recordSqlQueries(fn () => $this->client->request('GET', '/account/referral'));
        self::assertCount(1, $this->client->getCrawler()->filter('[data-testid="referral-referee"]'));

        foreach (['Bruno', 'Chloe', 'Denis', 'Emma'] as $firstname) {
            $this->referee($firstname, 'Durand', 'rewarded', 'amount', '10');
        }

        $five = $this->recordSqlQueries(fn () => $this->client->request('GET', '/account/referral'));
        self::assertCount(5, $this->client->getCrawler()->filter('[data-testid="referral-referee"]'));

        self::assertSame(1, $this->countTouchingTheReferralTables($one), 'one referee');
        self::assertSame(1, $this->countTouchingTheReferralTables($five), 'five referees');
        self::assertSame(\count($one), \count($five), 'the whole page sends as many queries for five referees as for one');
    }

    /**
     * @param list<string> $statements
     */
    private function countTouchingTheReferralTables(array $statements): int
    {
        return \count(array_filter(
            $statements,
            static fn (string $statement): bool => 1 === preg_match('/`referral(_reward)?`/', $statement),
        ));
    }

    /**
     * A friend who signed up with the code of the sponsor, with the welcome coupon and, for a rewarded one,
     * the coupon of the sponsor.
     */
    private function referee(string $firstname, string $lastname, string $status, ?string $sponsorValueType = null, string $sponsorValue = '10', ?Customer $sponsor = null): Customer
    {
        $sponsor ??= $this->sponsor;
        $referee = $this->factory->customer($this->factory->customerTitle(), ['firstname' => $firstname, 'lastname' => $lastname]);
        $referral = (new ReferralModel())
            ->setSponsorCustomerId($sponsor->getId())
            ->setReferralCodeId(ReferralCodeQuery::create()->findOneByCustomerId($sponsor->getId())?->getId())
            ->setRefereeCustomerId($referee->getId())
            ->setStatus($status);
        $referral->save();

        $rewards = $this->getService(RewardFactory::class);
        $issuer = $this->getService(CouponRewardIssuer::class);
        $issuer->issue($rewards->referee($referral), $referee);

        if (null !== $sponsorValueType) {
            $issuer->issue($rewards->sponsor($referral)->setValueType($sponsorValueType)->setValue($sponsorValue), $sponsor);
        }

        return $referee;
    }

    private function welcomeRewardOf(Customer $referee): ReferralReward
    {
        $reward = ReferralRewardQuery::create()->filterByCustomerId($referee->getId())->filterByBeneficiary('referee')->findOne();
        self::assertInstanceOf(ReferralReward::class, $reward);

        return $reward;
    }
}
