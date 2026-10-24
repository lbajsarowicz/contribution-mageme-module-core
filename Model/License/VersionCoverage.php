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

use MageMe\Core\Api\LicenseCoverageInterface;
use MageMe\Core\Helper\GenericLicenseHelper;
use Magento\Framework\Stdlib\DateTime\DateTime;

class VersionCoverage implements LicenseCoverageInterface
{
    public const RENEW_URL = Urls::RENEW;

    /** @var GenericLicenseHelper */
    private $licenseHelper;
    /** @var DateTime */
    private $dateTime;

    public function __construct(GenericLicenseHelper $licenseHelper, DateTime $dateTime)
    {
        $this->licenseHelper = $licenseHelper;
        $this->dateTime      = $dateTime;
    }

    public function isVersionCovered(string $licenseSection, string $ownerModuleName): bool
    {
        return $this->getCoverage($licenseSection, $ownerModuleName)->isCovered();
    }

    public function getCoverage(string $licenseSection, string $ownerModuleName): CoverageVerdict
    {
        $record    = $this->licenseHelper->getLicenseRecord($licenseSection);
        $installed = $this->licenseHelper->getInstalledVersion($ownerModuleName);
        $serial    = $this->value($record, GenericLicenseHelper::PATH_SERIAL);
        $checkedAt = (int)$this->value($record, GenericLicenseHelper::PATH_VERIFIED_TIME);

        $state = $this->resolveState($record, $installed);
        $upgradeRequired = $state === CoverageVerdict::STATE_NOT_COVERED
            && !in_array($this->value($record, GenericLicenseHelper::PATH_UPGRADE_REQUIRED), ['', '0'], true);

        return new CoverageVerdict(
            $state,
            $state === CoverageVerdict::STATE_INACTIVE
                ? null
                : $this->nullIfEmpty($this->value($record, GenericLicenseHelper::PATH_COVERED_UNTIL)),
            $this->nullIfEmpty($this->value($record, GenericLicenseHelper::PATH_COVERAGE_VERSION)),
            $checkedAt > 0 ? $checkedAt : null,
            $serial !== '' ? Urls::renew($serial) : null,
            $this->isDue($record, $installed),
            $state !== CoverageVerdict::STATE_INACTIVE && !in_array($this->value($record, GenericLicenseHelper::PATH_DEVELOPMENT), ['', '0'], true),
            $state === CoverageVerdict::STATE_NOT_COVERED
                ? $this->nullIfEmpty($this->value($record, GenericLicenseHelper::PATH_LATEST_COVERED))
                : null,
            $upgradeRequired,
            $upgradeRequired ? $this->value($record, GenericLicenseHelper::PATH_UPGRADE_KIND) : ''
        );
    }

    /**
     * An automatic check is due when the licence is active and the stored verdict is for another
     * version, was never computed, or has reached its next check time. After a failed check the
     * backoff wins even over a version change, so an unreachable server is not asked on every run.
     */
    public function isRefreshDue(string $licenseSection, string $ownerModuleName): bool
    {
        return $this->isDue(
            $this->licenseHelper->getLicenseRecord($licenseSection),
            $this->licenseHelper->getInstalledVersion($ownerModuleName)
        );
    }

    /** @param array<string, string> $record */
    private function resolveState(array $record, string $installed): string
    {
        if (!$this->isActive($record)) {
            return CoverageVerdict::STATE_INACTIVE;
        }
        if ($installed === '' || $installed !== $this->value($record, GenericLicenseHelper::PATH_COVERAGE_VERSION)) {
            return CoverageVerdict::STATE_NO_VERDICT;
        }

        switch ($this->value($record, GenericLicenseHelper::PATH_COVERAGE_CODE)) {
            case RefreshPolicy::CODE_OK:
                return CoverageVerdict::STATE_COVERED;
            case RefreshPolicy::CODE_NOT_COVERED:
                return CoverageVerdict::STATE_NOT_COVERED;
            default:
                return CoverageVerdict::STATE_NO_VERDICT;
        }
    }

    /** @param array<string, string> $record */
    private function isDue(array $record, string $installed): bool
    {
        if (!$this->isActive($record)) {
            return false;
        }
        $next    = (int)$this->value($record, GenericLicenseHelper::PATH_NEXT_CHECK_AT);
        $reached = $next <= $this->dateTime->gmtTimestamp();
        if ($this->isBackingOff($record)) {
            return $reached;
        }

        return $installed !== $this->value($record, GenericLicenseHelper::PATH_COVERAGE_VERSION) || $reached;
    }

    /** @param array<string, string> $record */
    private function isBackingOff(array $record): bool
    {
        return (int)$this->value($record, GenericLicenseHelper::PATH_VERIFY_FAILURES) > 0;
    }

    /** @param array<string, string> $record */
    private function isActive(array $record): bool
    {
        $active = $this->value($record, GenericLicenseHelper::PATH_ACTIVE);

        return $active !== '' && $active !== '0' && $this->value($record, GenericLicenseHelper::PATH_SERIAL) !== '';
    }

    /** @param array<string, string> $record */
    private function value(array $record, string $pathId): string
    {
        return isset($record[$pathId]) ? (string)$record[$pathId] : '';
    }

    private function nullIfEmpty(string $value): ?string
    {
        return $value !== '' ? $value : null;
    }
}
