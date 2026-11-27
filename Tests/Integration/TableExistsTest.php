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

namespace Referral\Tests\Integration;

use Referral\Referral;
use Thelia\Test\IntegrationTestCase;

/**
 * The postActivation and destroy of the module decide on the presence of the tables.
 */
final class TableExistsTest extends IntegrationTestCase
{
    public function testAPresentTableIsFoundAndAMissingOneIsNot(): void
    {
        $connection = $this->getPropelConnection();

        self::assertTrue(Referral::tableExists($connection, 'referral_reward'));
        self::assertFalse(Referral::tableExists($connection, 'referral_table_that_does_not_exist'), 'a missing table is not found');
    }
}
