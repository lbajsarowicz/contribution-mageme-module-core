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

use MageMe\Core\Helper\GenericLicenseHelper;
use MageMe\Core\Model\Declaration\DeclarationRegistry;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Lock\LockManagerInterface;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Psr\Log\LoggerInterface;

/**
 * Asks the licence server again for the licences whose check is due. Never waits: a section another
 * process is already checking is skipped.
 */
class CoverageRefresher
{
    public const LOCK_PREFIX = 'mageme_license_refresh_';

    /** "Check Again" may reach the server at most this often per licence. */
    public const FORCE_INTERVAL_S = 60;

    /** Check Again checked the license and the server gave a verdict. */
    public const CHECK_VERDICT = 'verdict';
    /** Check Again checked the license, and the server could not be reached or gave no verdict. */
    public const CHECK_NO_VERDICT = 'no_verdict';
    /** Check Again did not check: within the rate limit, not an active license, or another check was running. */
    public const CHECK_NOT_RUN = 'not_run';

    /** @var GenericLicenseHelper */
    private $licenseHelper;
    /** @var bool whether the last check refreshLocked() ran got a verdict on the license */
    private $lastCheckGotVerdict = false;
    /** @var string|null why the license server did not grant the license on the last check refreshLocked() ran */
    private $lastRefusal;
    /** @var VersionCoverage */
    private $coverage;
    /** @var DeclarationRegistry */
    private $registry;
    /** @var LockManagerInterface */
    private $lockManager;
    /** @var DateTime */
    private $dateTime;
    /** @var LoggerInterface */
    private $logger;

    public function __construct(
        GenericLicenseHelper $licenseHelper,
        VersionCoverage $coverage,
        DeclarationRegistry $registry,
        LockManagerInterface $lockManager,
        DateTime $dateTime,
        LoggerInterface $logger
    ) {
        $this->licenseHelper = $licenseHelper;
        $this->coverage      = $coverage;
        $this->registry      = $registry;
        $this->lockManager   = $lockManager;
        $this->dateTime      = $dateTime;
        $this->logger        = $logger;
    }

    /**
     * @return string[] sections that were sent to the server
     */
    public function refreshDue(): array
    {
        $refreshed = [];
        foreach ($this->registry->getSectionOwners() as $section => $owner) {
            if (!$this->coverage->isRefreshDue($section, $owner)) {
                continue;
            }
            $stillDue = function () use ($section, $owner): bool {
                return $this->coverage->isRefreshDue($section, $owner);
            };
            if ($this->refreshLocked($section, $owner, $stillDue, true)) {
                $refreshed[] = $section;
            }
        }

        return $refreshed;
    }

    /**
     * The "Check Again" path: skips the schedule, not the per-licence rate limit.
     *
     * The answer leaves out the renewal link: it carries the serial, and this endpoint is open to
     * every admin user, not only to those allowed to see licence serials.
     *
     * @return array{coverage: array<string, mixed>, retry_after: int, checked: bool, reason: string, refusal: ?string}
     * @throws LocalizedException when no installed module owns the section
     */
    public function refreshNow(string $licenseSection): array
    {
        $owner = $this->registry->getSectionOwner($licenseSection);
        if ($owner === null) {
            throw new LocalizedException(__('Unknown license section.'));
        }

        $reason = self::CHECK_NOT_RUN;
        if ($this->retryAfter($licenseSection) === 0 && $this->licenseHelper->isActive($licenseSection)) {
            $allowed = function () use ($licenseSection): bool {
                return $this->retryAfter($licenseSection) === 0;
            };
            if ($this->refreshLocked($licenseSection, $owner, $allowed, false)) {
                $reason = $this->lastCheckGotVerdict ? self::CHECK_VERDICT : self::CHECK_NO_VERDICT;
            }
        }

        $coverage = $this->coverage->getCoverage($licenseSection, $owner)->toArray();
        unset($coverage['renewUrl']);

        return [
            'coverage'    => $coverage,
            'retry_after' => $this->retryAfter($licenseSection),
            // whether this request checked the license, and with what outcome; unless it is CHECK_VERDICT the
            // coverage is the stored one
            'checked'     => $reason === self::CHECK_VERDICT,
            'reason'      => $reason,
            // why the license server did not grant the license; the license itself stays as it was
            'refusal'     => $reason === self::CHECK_VERDICT ? $this->lastRefusal : null,
        ];
    }

    /**
     * @return int seconds until "Check Again" may reach the server for this licence, 0 when it may now
     */
    private function retryAfter(string $licenseSection): int
    {
        $record  = $this->licenseHelper->getLicenseRecord($licenseSection);
        $elapsed = $this->dateTime->gmtTimestamp() - (int)($record[GenericLicenseHelper::PATH_VERIFY_ATTEMPT] ?? 0);

        return $elapsed < self::FORCE_INTERVAL_S ? self::FORCE_INTERVAL_S - $elapsed : 0;
    }

    /**
     * @param callable(): bool $stillNeeded re-evaluated once the lock is held: a process that finished
     *                                      just before may already have done this check
     * @param bool $scheduled               the cron's own check, not "Check Again"
     */
    private function refreshLocked(string $section, string $owner, callable $stillNeeded, bool $scheduled): bool
    {
        $lock = self::LOCK_PREFIX . $section;
        if (!$this->lockManager->lock($lock, 0)) {
            return false;
        }
        try {
            if (!$stillNeeded()) {
                return false;
            }
            $this->lastCheckGotVerdict = false;
            $this->lastRefusal = null;
            try {
                $status = $this->licenseHelper->fetchLiveStatus($section, $owner, true, $scheduled);
                $this->lastCheckGotVerdict = !$status->stale;
                $this->lastRefusal = $status->refusal;
            } catch (\Throwable $e) {
                // Counted like an unanswered check, so the backoff applies instead of a retry on the next run.
                $this->logger->warning(
                    'MageMe licence refresh failed: ' . $e->getMessage(),
                    ['license_section' => $section]
                );
                $this->licenseHelper->recordFailure($section);
            }
        } finally {
            $this->lockManager->unlock($lock);
        }

        return true;
    }
}
