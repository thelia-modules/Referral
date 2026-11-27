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

namespace Referral\Tests\Integration\Concurrency;

use Referral\Model\Referral as ReferralModel;
use Referral\Model\ReferralCodeQuery;
use Referral\Model\ReferralQuery;
use Referral\Model\ReferralRewardQuery;
use Referral\Referral;
use Referral\Service\ReferralCodeProvider;
use Thelia\Model\CartQuery;
use Thelia\Model\ConfigQuery;
use Thelia\Model\CouponQuery;
use Thelia\Model\CustomerQuery;
use Thelia\Model\Order;
use Thelia\Model\OrderAddressQuery;
use Thelia\Model\OrderQuery;
use Thelia\Model\OrderStatus;
use Thelia\Test\FixtureFactory;
use Thelia\Test\IntegrationTestCase;

/**
 * Two referees of the same sponsor, whose first orders are paid at the same moment by two processes,
 * with a monthly cap of one: exactly one referral is rewarded, the other one is capped.
 *
 * The other connections cannot see what a test writes inside its rolled-back transaction: this test
 * commits its fixtures and deletes them in tearDown(). Each order is paid by its own PHP process
 * (change-order-status.php), started together and released by a start signal once both are booted;
 * a barrier inside the locked section (FileBarrierProbe) makes them meet there when nothing serializes
 * them.
 */
final class SponsorRewardConcurrencyTest extends IntegrationTestCase
{
    protected bool $useTransaction = false;

    private string $workDirectory = '';

    /** @var array<string, string|null> */
    private array $settingsBefore = [];

    /** @var list<int> */
    private array $customerIds = [];

    /** @var list<Order> */
    private array $orders = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->workDirectory = sys_get_temp_dir().'/referral-concurrency-'.bin2hex(random_bytes(6));
        mkdir($this->workDirectory.'/barrier', 0o777, true);

        foreach ([Referral::CONFIG_MONTHLY_CAP => '1', Referral::CONFIG_TRIGGER_STATUS => 'paid', Referral::CONFIG_ENABLED => '1', Referral::CONFIG_SAME_ADDRESS_CHECK => '0'] as $name => $value) {
            $this->settingsBefore[$name] = Referral::getConfigValue($name);
            Referral::setConfigValue($name, $value);
        }
        ConfigQuery::resetCache();
    }

    protected function tearDown(): void
    {
        try {
            $this->deleteTheFixtures();

            foreach ($this->settingsBefore as $name => $value) {
                Referral::setConfigValue($name, $value ?? Referral::DEFAULTS[$name]);
            }
            ConfigQuery::resetCache();

            $this->removeTheWorkDirectory();
        } finally {
            parent::tearDown();
        }
    }

    public function testTwoRefereesOfOneSponsorPaidAtOnceGiveOneRewardAndOneCap(): void
    {
        $factory = new FixtureFactory($this->getPropelConnection());
        $sponsor = $factory->customer($factory->customerTitle());
        $this->customerIds[] = $sponsor->getId();
        $this->getService(ReferralCodeProvider::class)->getOrCreateFor($sponsor);

        $referralIds = [];
        foreach ([1, 2] as $index) {
            $referee = $factory->customer($factory->customerTitle());
            $this->customerIds[] = $referee->getId();
            $referral = (new ReferralModel())
                ->setSponsorCustomerId($sponsor->getId())
                ->setReferralCodeId(ReferralCodeQuery::create()->findOneByCustomerId($sponsor->getId())?->getId())
                ->setRefereeCustomerId($referee->getId())
                ->setStatus('registered');
            $referral->save();
            $referralIds[] = $referral->getId();
            $this->orders[] = $factory->order($referee);
        }

        $results = $this->payTheOrdersAtOnce();

        $statuses = [];
        foreach ($referralIds as $referralId) {
            $referral = ReferralQuery::create()->findPk($referralId);
            $statuses[] = $referral?->getStatus().'/'.$referral?->getStatusReason();
        }
        sort($statuses);

        self::assertSame(['capped/monthly_cap', 'rewarded/'], $statuses, $results);
        self::assertSame(1, ReferralRewardQuery::create()->filterByCustomerId($sponsor->getId())->filterByBeneficiary('sponsor')->count(), $results);

        // Not a lucky order of arrival: both reached the locked section, one after the other.
        $arrivals = $this->barrierMarks('arrived');
        $departures = $this->barrierMarks('left');
        self::assertCount(2, $arrivals, 'both processes reached the locked section: '.$results);
        self::assertGreaterThanOrEqual(min($departures), max($arrivals), 'the second process entered once the first had left: '.$results);
    }

    /**
     * @return string what the processes said, and when each of them reached and left the barrier
     */
    private function payTheOrdersAtOnce(): string
    {
        $script = __DIR__.'/change-order-status.php';
        $startFile = $this->workDirectory.'/start';
        $environment = array_merge(getenv(), [
            'APP_ENV' => 'test',
            'APP_DEBUG' => '0',
            'MAILER_DSN' => 'null://null',
            'REFERRAL_TEST_BARRIER_DIR' => $this->workDirectory.'/barrier',
        ]);

        $processes = [];
        foreach ($this->orders as $index => $order) {
            $readyFile = $this->workDirectory.'/ready-'.$index;
            $process = proc_open(
                [\PHP_BINARY, $script, (string) $order->getId(), OrderStatus::CODE_PAID, $readyFile, $startFile],
                [1 => ['file', $this->workDirectory.'/out-'.$index, 'w'], 2 => ['file', $this->workDirectory.'/err-'.$index, 'w']],
                $pipes,
                null,
                $environment,
            );
            self::assertIsResource($process);
            $processes[$index] = [$process, $readyFile];
        }

        $deadline = microtime(true) + 90;
        foreach ($processes as [, $readyFile]) {
            while (!file_exists($readyFile) && microtime(true) < $deadline) {
                usleep(20_000);
            }
        }
        touch($startFile);

        $report = [];
        foreach ($processes as $index => [$process]) {
            $exitCode = proc_close($process);
            $report[] = \sprintf(
                'process %d: exit %d, ready %s, stderr: %s',
                $index,
                $exitCode,
                file_exists($this->workDirectory.'/ready-'.$index) ? 'yes' : 'no',
                trim((string) file_get_contents($this->workDirectory.'/err-'.$index)),
            );
            self::assertSame(0, $exitCode, implode("\n", $report));
        }

        foreach (glob($this->workDirectory.'/barrier/*') ?: [] as $mark) {
            $report[] = basename($mark).' at '.file_get_contents($mark);
        }

        return implode("\n", $report);
    }

    /**
     * @return list<float> the times the processes wrote at this step of the barrier
     */
    private function barrierMarks(string $step): array
    {
        return array_map(
            static fn (string $mark): float => (float) file_get_contents($mark),
            glob($this->workDirectory.'/barrier/'.$step.'-*') ?: [],
        );
    }

    private function deleteTheFixtures(): void
    {
        if ([] === $this->customerIds) {
            return;
        }

        $couponIds = ReferralRewardQuery::create()
            ->filterByCustomerId($this->customerIds)
            ->select('CouponId')
            ->find()
            ->getData();
        CouponQuery::create()->filterById(array_filter($couponIds))->delete();

        foreach ($this->orders as $order) {
            $addressIds = [$order->getInvoiceOrderAddressId(), $order->getDeliveryOrderAddressId()];
            $cartId = $order->getCartId();
            OrderQuery::create()->filterById($order->getId())->delete();
            OrderAddressQuery::create()->filterById($addressIds)->delete();
            CartQuery::create()->filterById($cartId)->delete();
        }

        // The referral rows, their rewards and the sponsor's code go with the customers.
        CustomerQuery::create()->filterById($this->customerIds)->delete();
    }

    private function removeTheWorkDirectory(): void
    {
        $files = array_merge(glob($this->workDirectory.'/barrier/*') ?: [], glob($this->workDirectory.'/*') ?: []);
        foreach ($files as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        @rmdir($this->workDirectory.'/barrier');
        @rmdir($this->workDirectory);
    }
}
