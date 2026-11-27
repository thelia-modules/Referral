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

namespace Referral\Service;

use Propel\Runtime\Connection\ConnectionInterface;
use Thelia\Model\AddressQuery;
use Thelia\Model\OrderAddress;
use Thelia\Model\OrderAddressQuery;
use Thelia\Model\OrderQuery;

/**
 * Tells whether an order is delivered to one of the sponsor's own addresses: the street line, the
 * zip code, the city and the country, compared without case, accents, punctuation or extra spaces.
 */
final readonly class SameAddressDetector
{
    public function isDeliveredToTheSponsor(int $orderId, int $sponsorCustomerId, ConnectionInterface $connection): bool
    {
        // Two columns: Propel answers a single selected column as bare values.
        /** @var list<array{Id: int|string, DeliveryOrderAddressId: int|string|null}> $rows */
        $rows = OrderQuery::create()
            ->filterById($orderId)
            ->select(['Id', 'DeliveryOrderAddressId'])
            ->find($connection)
            ->getData();
        $deliveryAddressId = $rows[0]['DeliveryOrderAddressId'] ?? null;

        $delivery = null === $deliveryAddressId ? null : OrderAddressQuery::create()->findPk((int) $deliveryAddressId, $connection);
        if (!$delivery instanceof OrderAddress) {
            return false;
        }

        $deliveredTo = self::fingerprint($delivery->getAddress1(), $delivery->getZipcode(), $delivery->getCity(), $delivery->getCountryId());

        foreach (AddressQuery::create()->filterByCustomerId($sponsorCustomerId)->find($connection) as $address) {
            if ($deliveredTo === self::fingerprint($address->getAddress1(), $address->getZipcode(), $address->getCity(), $address->getCountryId())) {
                return true;
            }
        }

        return false;
    }

    private static function fingerprint(?string $street, ?string $zipCode, ?string $city, ?int $countryId): string
    {
        return implode('|', [
            self::normalize($street),
            str_replace(' ', '', self::normalize($zipCode)),
            self::normalize($city),
            (string) $countryId,
        ]);
    }

    private static function normalize(?string $text): string
    {
        $text = mb_strtolower(trim((string) $text));
        $withoutAccents = \Normalizer::normalize($text, \Normalizer::FORM_D);
        $text = preg_replace('/\p{Mn}+/u', '', false === $withoutAccents ? $text : $withoutAccents) ?? $text;
        $text = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $text) ?? $text;

        return trim($text);
    }
}
