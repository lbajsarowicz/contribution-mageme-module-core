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

namespace MageMe\Core\Model\License;

/**
 * Whether the licence covers the installed version of its owner module, as last stored.
 */
class CoverageVerdict
{
    public const STATE_COVERED     = 'covered';
    public const STATE_NOT_COVERED = 'not_covered';
    public const STATE_INACTIVE    = 'inactive';
    /** Nothing applicable is stored yet, or the server could not tell. Treated as covered. */
    public const STATE_NO_VERDICT  = 'no_verdict';

    public const UPGRADE_FREE    = 'free';
    public const UPGRADE_RENEWAL = 'renewal';
    public const UPGRADE_KINDS   = [self::UPGRADE_FREE, self::UPGRADE_RENEWAL];

    /** @var string */
    public $state;
    /** @var string|null YYYY-MM-DD: versions released up to this day are covered */
    public $coveredUntil;
    /** @var string|null owner-module version of the stored verdict */
    public $checkedVersion;
    /** @var int|null gmt timestamp of the last answered check */
    public $checkedAt;
    /** @var string|null */
    public $renewUrl;
    /** @var bool */
    public $refreshDue;
    /** @var bool a development licence */
    public $isDev;
    /** @var string|null newest version in the installed major that the licence covers; null when none or unknown */
    public $latestCoveredVersion;
    /** @var bool the installed version belongs to a newer product generation the serial is not upgraded to */
    public $upgradeRequired;
    /** @var string how that upgrade is obtained: free | renewal; '' when unknown or not required */
    public $upgradeKind;

    public function __construct(
        string $state,
        ?string $coveredUntil,
        ?string $checkedVersion,
        ?int $checkedAt,
        ?string $renewUrl,
        bool $refreshDue,
        bool $isDev = false,
        ?string $latestCoveredVersion = null,
        bool $upgradeRequired = false,
        string $upgradeKind = ''
    ) {
        $this->state          = $state;
        $this->coveredUntil   = $coveredUntil;
        $this->checkedVersion = $checkedVersion;
        $this->checkedAt      = $checkedAt;
        $this->renewUrl       = $renewUrl;
        $this->refreshDue     = $refreshDue;
        $this->isDev          = $isDev;
        $this->latestCoveredVersion = $latestCoveredVersion;
        $this->upgradeRequired = $upgradeRequired;
        $this->upgradeKind     = $upgradeRequired && in_array($upgradeKind, self::UPGRADE_KINDS, true) ? $upgradeKind : '';
    }

    public function isCovered(): bool
    {
        return $this->state === self::STATE_COVERED || $this->state === self::STATE_NO_VERDICT;
    }

    public function toArray(): array
    {
        return [
            'state'          => $this->state,
            'covered'        => $this->isCovered(),
            'coveredUntil'   => $this->coveredUntil,
            'checkedVersion' => $this->checkedVersion,
            'checkedAt'      => $this->checkedAt,
            'renewUrl'       => $this->renewUrl,
            'refreshDue'     => $this->refreshDue,
            'isDev'          => $this->isDev,
            'latestCoveredVersion' => $this->latestCoveredVersion,
            'upgradeRequired' => $this->upgradeRequired,
            'upgradeKind'     => $this->upgradeKind,
        ];
    }
}
