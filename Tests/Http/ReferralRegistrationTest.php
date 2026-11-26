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

use FlexyBundle\Form\CustomerRegisterForm;
use Referral\Model\ReferralQuery;
use Referral\Service\ReferralCodeProvider;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Thelia\Domain\Customer\EmailAddress;
use Thelia\Model\Customer;
use Thelia\Model\CustomerQuery;
use Thelia\Test\FixtureFactory;
use Thelia\Test\WebIntegrationTestCase;

/**
 * The sign-up form of the Flexy shop with a referral code, from a browser's point of view: the link
 * a sponsor shares, the field it fills in, and what a refused code says.
 */
final class ReferralRegistrationTest extends WebIntegrationTestCase
{
    private const PASSWORD = 'Str0ng-Passw0rd!xyz';
    private const REFUSAL = 'This referral code cannot be used.';

    private FixtureFactory $factory;
    private Customer $sponsor;
    private string $code;

    protected function setUp(): void
    {
        parent::setUp();

        // Not createFixtureFactory(): it leaves a request on the stack, in front of the ones the client sends.
        $this->factory = new FixtureFactory($this->getPropelConnection());
        $this->sponsor = $this->factory->customer($this->factory->customerTitle(), ['email' => 'jane.doe@gmail.com']);
        $this->code = $this->getService(ReferralCodeProvider::class)->getOrCreateFor($this->sponsor)->getCode();
        $this->limiter('referral_code_check')->create('127.0.0.1')->reset();
        // The registration budgets live in the cache pool, which survives from one run to the next.
        $this->limiter('customer_registration_per_client')->create('127.0.0.1')->reset();
        foreach (['newcomer@example.test', 'plain@example.test', 'unknown@example.test', 'janedoe+me@gmail.com', 'flooder@example.test'] as $email) {
            $this->limiter('customer_registration_per_address')->create(hash('sha256', EmailAddress::normalize($email)))->reset();
        }
    }

    public function testTheLinkOfASponsorFillsTheCodeInTheSignUpForm(): void
    {
        $this->client->request('GET', '/referral/'.strtolower($this->code));
        self::assertResponseRedirects('/customer/register');

        $crawler = $this->client->followRedirect();

        self::assertResponseIsSuccessful();
        self::assertSame($this->code, $this->field($crawler)->attr('value'), 'the field is prefilled, in capitals');
    }

    public function testALinkWithAnUnknownCodeIsKeptWithoutBeingLookedUp(): void
    {
        $this->client->request('GET', '/referral/NOSUCHCODE');
        $crawler = $this->client->followRedirect();

        self::assertSame('NOSUCHCODE', $this->field($crawler)->attr('value'));
    }

    public function testTheSignUpFormWithoutALinkHasAnEmptyField(): void
    {
        $crawler = $this->client->request('GET', '/customer/register');

        self::assertResponseIsSuccessful();
        self::assertSame('', (string) $this->field($crawler)->attr('value'));
    }

    public function testASignUpWithAUsableCodeCreatesTheAccountAndItsReferral(): void
    {
        $this->client->request('GET', '/referral/'.$this->code);
        $crawler = $this->client->followRedirect();

        $this->post($crawler, 'newcomer@example.test', $this->code);

        $customer = CustomerQuery::create()->findOneByEmail('newcomer@example.test');
        self::assertNotNull($customer, 'the account was created');
        $referral = ReferralQuery::create()->findOneByRefereeCustomerId($customer->getId());
        self::assertNotNull($referral);
        self::assertSame($this->sponsor->getId(), $referral->getSponsorCustomerId());
        self::assertSame($this->code, $customer->getSponsor());
    }

    public function testAnUnknownCodeAndTheSponsorOwnAddressAreRefusedWithTheSameMessageAndCreateNothing(): void
    {
        $refusals = [];
        foreach ([
            'unknown code' => ['unknown@example.test', 'ZZZZZZZZZZ'],
            'sponsor alias' => ['janedoe+me@gmail.com', $this->code],
        ] as $label => [$email, $code]) {
            $crawler = $this->client->request('GET', '/customer/register');
            $this->post($crawler, $email, $code);

            self::assertNull(CustomerQuery::create()->findOneByEmail($email), $label.': no account');
            $page = $this->client->followRedirect();
            self::assertStringContainsString(self::REFUSAL, $page->html(), $label.' says why');
            $refusals[$label] = substr_count($page->html(), 'referral code');
        }

        self::assertSame(0, ReferralQuery::create()->count());
        self::assertSame(1, \count(array_unique($refusals)), 'both pages carry the same message');
    }

    public function testASignUpWithoutACodeIsAnOrdinaryAccount(): void
    {
        $crawler = $this->client->request('GET', '/customer/register');

        $this->post($crawler, 'plain@example.test', '');

        self::assertNotNull(CustomerQuery::create()->findOneByEmail('plain@example.test'));
        self::assertSame(0, ReferralQuery::create()->count());
    }

    public function testTooManyCodeAttemptsFromOneAddressAreAnswered(): void
    {
        $crawler = $this->client->request('GET', '/customer/register');
        $limiter = $this->limiter('referral_code_check')->create('127.0.0.1');
        $limiter->consume(10);

        $this->post($crawler, 'flooder@example.test', $this->code);

        self::assertNull(CustomerQuery::create()->findOneByEmail('flooder@example.test'));
        self::assertStringContainsString('Too many attempts, please try again later.', $this->client->followRedirect()->html());
    }

    private function limiter(string $name): RateLimiterFactoryInterface
    {
        $factory = static::getContainer()->get('limiter.'.$name);
        self::assertInstanceOf(RateLimiterFactoryInterface::class, $factory);

        return $factory;
    }

    private function field(Crawler $page): Crawler
    {
        $field = $page->filter('input[name="'.CustomerRegisterForm::getName().'[referral_code]"]');
        self::assertCount(1, $field, 'the page carries the referral code field');

        return $field;
    }

    private function post(Crawler $page, string $email, string $code): void
    {
        $name = CustomerRegisterForm::getName();
        $token = (string) $page->filter('input[name="'.$name.'[_token]"]')->attr('value');

        $this->client->request('POST', '/customer/register', [
            $name => [
                'firstname' => 'Pat',
                'lastname' => 'Newcomer',
                'email' => $email,
                'password' => self::PASSWORD,
                'referral_code' => $code,
                'success_url' => '/customer/informations',
                'error_url' => '',
                '_token' => $token,
            ],
        ]);
    }
}
