<?php
/**
 * Copyright © MageMe. All rights reserved.
 * See LICENSE for license terms, or https://mageme.com/license.
 */

declare(strict_types=1);

namespace MageMe\Core\Model\License;

/**
 * The mageme.com pages a license message sends the merchant to.
 *
 * Renewal works without signing in, but only with the serial: the page puts the renewal in the cart and goes to
 * checkout, and without a serial it has nothing to renew. A merchant with no serial at hand is sent to My Products.
 */
class Urls
{
    public const RENEW       = 'https://mageme.com/licenses/renew';
    public const MY_PRODUCTS = 'https://mageme.com/my-products/';
    public const AGREEMENT   = 'https://mageme.com/license';

    public static function renew(string $serial): string
    {
        return self::RENEW . '?' . http_build_query(['serial' => $serial], '', '&', PHP_QUERY_RFC3986);
    }

    /** Whether a link is one that Core's license messages use. */
    public static function isLicenseMessageUrl(string $url): bool
    {
        return strpos($url, self::RENEW . '?serial=') === 0
            || $url === self::MY_PRODUCTS
            || $url === self::AGREEMENT
            || self::legacyModuleOf($url) !== null;
    }

    /**
     * The module a link of the 2.2.0 pre-releases names: those ended with `module=<Module>&v=<version>`.
     */
    public static function legacyModuleOf(string $url): ?string
    {
        return preg_match('/[?&]module=([A-Za-z0-9]+_[A-Za-z0-9]+)&v=[^&]*$/', $url, $m) === 1 ? $m[1] : null;
    }
}
