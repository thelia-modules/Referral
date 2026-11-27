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

namespace Referral\Command;

use Referral\Reward\UnusedCouponPurger;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Scheduler\Attribute\AsCronTask;

#[AsCommand(
    name: 'referral:purge-expired-coupons',
    description: 'Delete the expired coupons issued by the referral program that were never used',
)]
#[AsCronTask('0 3 * * *', schedule: 'thelia')]
final class PurgeExpiredCouponsCommand extends Command
{
    public function __construct(private readonly UnusedCouponPurger $purger)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Count the coupons that would be deleted, delete nothing');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');
        $count = $this->purger->purgeExpired($dryRun);

        $io->writeln(\sprintf($dryRun ? 'Expired unused coupons that would be deleted: %d' : 'Expired unused coupons deleted: %d', $count));

        return Command::SUCCESS;
    }
}
