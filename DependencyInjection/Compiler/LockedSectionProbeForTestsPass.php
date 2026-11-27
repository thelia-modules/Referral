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

namespace Referral\DependencyInjection\Compiler;

use Referral\Reward\LockedSectionProbe;
use Referral\Tests\Integration\Concurrency\FileBarrierProbe;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * In the test environment only, hangs a barrier on the locked section of the sponsor reviews. It
 * does nothing unless REFERRAL_TEST_BARRIER_DIR names a directory, which only the concurrency test
 * gives to the processes it starts. Dev and prod have no probe at all.
 */
final readonly class LockedSectionProbeForTestsPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if ('test' !== $container->getParameter('kernel.environment')) {
            return;
        }

        $container->register(FileBarrierProbe::class, FileBarrierProbe::class)
            ->setArguments(['%env(default::REFERRAL_TEST_BARRIER_DIR)%']);
        $container->setAlias(LockedSectionProbe::class, FileBarrierProbe::class);
    }
}
