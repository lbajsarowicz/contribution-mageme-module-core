<?php
/**
 * Copyright © MageMe. All rights reserved.
 * See LICENSE for license terms, or https://mageme.com/license.
 */

declare(strict_types=1);

namespace MageMe\Core\Api;

/**
 * Whether this Magento installation is a development one, judged by its storefront addresses.
 *
 * @api
 */
interface DevelopmentHostInterface
{
    /**
     * True only when the secure and unsecure base URL of every store view, disabled ones included, is a
     * development host: localhost, a loopback or private IP, a single-label name, a known development
     * suffix (.test, .local, .ddev.site, ...) or a first label such as staging, dev2 or stage-eu.
     * No store views, or a URL that cannot be read, means production.
     */
    public function isDevelopmentInstall(): bool;
}
