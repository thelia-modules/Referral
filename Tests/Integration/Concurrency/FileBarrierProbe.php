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

use Referral\Reward\LockedSectionProbe;

/**
 * A barrier for the processes of the concurrency test: each one that reaches the locked section
 * writes its arrival, then waits until the expected number of processes have arrived, or until the
 * patience runs out. With the lock, the second process cannot arrive while the first one waits: the
 * first one leaves on timeout and the second one arrives once it has committed. Without the lock,
 * they all arrive and leave together.
 *
 * Arrival and departure times are written next to the barrier for the test to read.
 */
final readonly class FileBarrierProbe implements LockedSectionProbe
{
    public const EXPECTED_PROCESSES = 2;
    public const PATIENCE_SECONDS = 4.0;

    public function __construct(private ?string $directory = null)
    {
    }

    public function reached(int $referralId): void
    {
        if (null === $this->directory || '' === $this->directory || !is_dir($this->directory)) {
            return;
        }

        $process = (string) getmypid();
        file_put_contents($this->directory.'/arrived-'.$process, (string) microtime(true));

        $deadline = microtime(true) + self::PATIENCE_SECONDS;
        while (\count(glob($this->directory.'/arrived-*') ?: []) < self::EXPECTED_PROCESSES && microtime(true) < $deadline) {
            usleep(10_000);
        }

        file_put_contents($this->directory.'/left-'.$process, (string) microtime(true));
    }
}
