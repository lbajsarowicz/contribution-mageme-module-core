<?php
/**
 * MageMe
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the MageMe.com license that is
 * available through the world-wide-web at this URL:
 * https://mageme.com/license
 *
 * Copyright (c) MageMe (https://mageme.com)
 **/

declare(strict_types=1);

namespace MageMe\Core\Api;

use MageMe\Core\Model\License\CoverageVerdict;

/**
 * Does the activated licence cover the installed version of its owner module.
 *
 * Reads the stored verdict only and never calls the licence server, so it is safe on every admin
 * request. Core refreshes the verdict in the background. Anything short of a stored negative
 * answer counts as covered.
 *
 * @api
 */
interface LicenseCoverageInterface
{
    /**
     * False only when the licence is not active, or the server said the installed version is not covered.
     */
    public function isVersionCovered(string $licenseSection, string $ownerModuleName): bool;

    public function getCoverage(string $licenseSection, string $ownerModuleName): CoverageVerdict;
}
