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
 * DISCLAIMER
 *
 * Do not edit or add to this file if you wish to upgrade this extension to a newer
 * version in the future.
 *
 * Copyright (c) MageMe (https://mageme.com)
 **/

declare(strict_types=1);

namespace MageMe\Core\Helper;

use Exception;
use MageMe\Core\Model\License\ActivationError;
use MageMe\Core\Model\License\CoverageNotifier;
use MageMe\Core\Model\License\CoverageRefresher;
use MageMe\Core\Model\License\CoverageVerdict;
use MageMe\Core\Model\License\InstalledVersion;
use MageMe\Core\Model\License\LicenseStatus;
use MageMe\Core\Model\License\RefreshPolicy;
use MageMe\Core\Model\License\StatusMapper;
use Magento\Config\Model\ResourceModel\Config;
use Magento\Config\Model\ResourceModel\Config\Data\CollectionFactory as ConfigCollectionFactory;
use Magento\Framework\App\Config\ReinitableConfigInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\ProductMetadataInterface;
use Magento\Framework\HTTP\Client\Curl;
use Magento\Framework\Lock\LockManagerInterface;
use Magento\Framework\Stdlib\DateTime\DateTime;

/**
 * Stateless license helper. All operations parametrised by $licenseSection.
 *
 * Replaces per-module <Module>\Helper\LicenseHelper subclasses. License-trigger
 * is now resolved through info.mageme.com/modules.json catalog (see RemoteCatalog +
 * LicenseMetaResolver), not via PHP class presence.
 */
class GenericLicenseHelper
{
    public const URL = 'https://license.mageme.com';

    /** Identifies our own traffic in the license-server logs, and to any WAF in front of it. */
    public const USER_AGENT = 'MageMe-Core (+https://mageme.com)';

    /** A user is waiting on the round trip, but not forever. */
    public const CONNECT_TIMEOUT_S = 5;
    public const REQUEST_TIMEOUT_S = 10;

    /** Nobody is waiting on a background refresh, and a slow server must not hold a PHP worker. */
    public const BACKGROUND_CONNECT_TIMEOUT_S = 2;
    public const BACKGROUND_REQUEST_TIMEOUT_S = 5;

    /**
     * Activate and Deactivate wait this long for a background check of the same licence to finish,
     * so its answer cannot land on top of theirs. Past it they go ahead.
     */
    public const MANUAL_LOCK_WAIT_S = self::BACKGROUND_REQUEST_TIMEOUT_S + 1;

    public const ACTION_ACTIVATE   = 'activate';
    public const ACTION_DEACTIVATE = 'deactivate';
    public const ACTION_VERIFY     = 'verify';
    public const ACTION_ACCEPT_UPGRADE = 'accept_upgrade';

    /**
     * Refusals that are about the licence itself. A check takes only these as a verdict; any other refusal (a
     * rate limit, the server's own failure, an incomplete request) says nothing about the licence.
     */
    public const LICENCE_REFUSAL_CODES = [
        ActivationError::CODE_NOT_FOUND,
        ActivationError::CODE_WRONG_PRODUCT,
        ActivationError::CODE_DISABLED,
        ActivationError::CODE_IN_USE,
        ActivationError::CODE_PLATFORM,
    ];

    public const ERROR_RENEWAL_REQUIRED = 'renewal_required';
    public const ERROR_NOT_UPGRADABLE   = 'not_upgradable';

    public const PATH_LICENSE        = '/license';
    public const PATH_ACTIVE         = self::PATH_LICENSE . '/active';
    public const PATH_SERIAL         = self::PATH_LICENSE . '/serial';
    public const PATH_ACCESS_TOKEN   = self::PATH_LICENSE . '/access_token';
    public const PATH_VERIFIED_TIME  = self::PATH_LICENSE . '/verified_time';
    public const PATH_DEVELOPMENT    = self::PATH_LICENSE . '/development';
    public const PATH_VERIFY_ATTEMPT = self::PATH_LICENSE . '/verify_attempt';
    public const PATH_VALID_UNTIL    = self::PATH_LICENSE . '/valid_until';

    /** Server verdict on the installed owner-module version, as received: ok | version_not_covered | version_unknown | '' */
    public const PATH_COVERAGE_CODE    = self::PATH_LICENSE . '/coverage_code';
    public const PATH_COVERED_UNTIL    = self::PATH_LICENSE . '/covered_until';
    /** The owner-module version the last answered check was made for. */
    public const PATH_COVERAGE_VERSION = self::PATH_LICENSE . '/coverage_version';
    public const PATH_VERIFY_FAILURES  = self::PATH_LICENSE . '/verify_failures';
    public const PATH_NEXT_CHECK_AT    = self::PATH_LICENSE . '/next_check_at';
    /** Newest version in the installed major that the licence covers, sent with version_not_covered. */
    public const PATH_LATEST_COVERED   = self::PATH_LICENSE . '/latest_covered_version';
    /** The uncovered version the admin inbox was already told about. */
    public const PATH_NOTIFIED_VERSION = self::PATH_LICENSE . '/notified_version';
    /** The id of that inbox message; the message is retired by it. */
    public const PATH_NOTIFIED_MESSAGE = self::PATH_LICENSE . '/notified_message_id';
    /** 1 when the installed version belongs to a newer product generation the serial is not upgraded to. */
    public const PATH_UPGRADE_REQUIRED = self::PATH_LICENSE . '/upgrade_required';
    /** How that upgrade is obtained: free | renewal | '' (unknown or not required). */
    public const PATH_UPGRADE_KIND     = self::PATH_LICENSE . '/upgrade_kind';

    private const COVERAGE_CODES = [
        RefreshPolicy::CODE_OK,
        RefreshPolicy::CODE_NOT_COVERED,
        RefreshPolicy::CODE_UNKNOWN,
    ];

    /** @var Curl */
    protected $curl;
    /** @var Config */
    protected $config;
    /** @var ScopeConfigInterface */
    protected $scopeConfig;
    /** @var ReinitableConfigInterface */
    protected $reinitableConfig;
    /** @var ProductMetadataInterface */
    protected $productMetadata;
    /** @var DateTime */
    protected $dateTime;
    /** @var ConfigCollectionFactory */
    protected $configCollectionFactory;
    /** @var StatusMapper */
    protected $statusMapper;
    /** @var InstalledVersion */
    protected $installedVersion;
    /** @var RefreshPolicy */
    protected $refreshPolicy;
    /** @var LockManagerInterface */
    protected $lockManager;
    /** @var CoverageNotifier */
    protected $coverageNotifier;
    /** @var ActivationError */
    protected $activationError;

    /**
     * True once sendRequest() has an answer from the license server itself. A timeout, a gateway
     * error or a body that is not a license answer leaves it false.
     *
     * @var bool
     */
    private $lastRequestAnswered = false;

    public function __construct(
        Curl                      $curl,
        Config                    $config,
        ScopeConfigInterface      $scopeConfig,
        ReinitableConfigInterface $reinitableConfig,
        ProductMetadataInterface  $productMetadata,
        DateTime                  $dateTime,
        ConfigCollectionFactory   $configCollectionFactory,
        StatusMapper              $statusMapper,
        InstalledVersion          $installedVersion,
        RefreshPolicy             $refreshPolicy,
        LockManagerInterface      $lockManager,
        CoverageNotifier          $coverageNotifier,
        ActivationError           $activationError
    ) {
        $this->curl                    = $curl;
        $this->config                  = $config;
        $this->scopeConfig             = $scopeConfig;
        $this->reinitableConfig        = $reinitableConfig;
        $this->productMetadata         = $productMetadata;
        $this->dateTime                = $dateTime;
        $this->configCollectionFactory = $configCollectionFactory;
        $this->statusMapper            = $statusMapper;
        $this->installedVersion        = $installedVersion;
        $this->refreshPolicy           = $refreshPolicy;
        $this->lockManager             = $lockManager;
        $this->coverageNotifier        = $coverageNotifier;
        $this->activationError         = $activationError;
    }

    public function activateLicense(string $licenseSection, string $ownerModuleName, string $serial): array
    {
        $lock   = CoverageRefresher::LOCK_PREFIX . $licenseSection;
        $locked = $this->lockManager->lock($lock, self::MANUAL_LOCK_WAIT_S);
        try {
            $params = $this->getRequestParams(self::ACTION_ACTIVATE, $licenseSection, $ownerModuleName, $serial);
            $result = $this->sendRequest($params, false);
            if (!$this->lastRequestAnswered) {
                $result['status'] = $this->getStatus($licenseSection)->toArray();

                return $result;
            }

            $this->saveLicenseConfig($licenseSection, self::PATH_SERIAL, $serial);

            if ($result['success']) {
                $result['messages'][] = __('License successfully activated.');
                $result['is_active']  = true;
                $this->saveLicenseConfig($licenseSection, self::PATH_ACTIVE, 1);
                $this->saveLicenseConfig($licenseSection, self::PATH_ACCESS_TOKEN, (string)($result['access_token'] ?? ''));
                $this->saveLicenseConfig($licenseSection, self::PATH_VERIFIED_TIME, $this->dateTime->gmtTimestamp());
                $this->saveLicenseConfig($licenseSection, self::PATH_DEVELOPMENT, empty($result['dev']) ? 0 : 1);
                if (!empty($result['dev'])) {
                    $result['warnings'][] = __('Development license detected. Please do not use for production.');
                }
                $this->saveLicenseConfig($licenseSection, self::PATH_VALID_UNTIL, (string)$this->statusMapper->fromApiResponse($result)->validUntil);
                $this->recordAnswer($licenseSection, $ownerModuleName, (string)($params['module_version'] ?? ''), $result);
            } else {
                $this->saveLicenseConfig($licenseSection, self::PATH_ACTIVE, 0);
                $this->clearCoverage($licenseSection, $ownerModuleName);
                $result = $this->activationError->apply($result, $ownerModuleName);
            }

            $this->reinitableConfig->reinit();
            unset($result['access_token']);
            $result['status'] = $this->getStatus($licenseSection)->toArray();
            return $result;
        } finally {
            if ($locked) {
                $this->lockManager->unlock($lock);
            }
        }
    }

    public function deactivateLicense(string $licenseSection, string $ownerModuleName): array
    {
        $lock   = CoverageRefresher::LOCK_PREFIX . $licenseSection;
        $locked = $this->lockManager->lock($lock, self::MANUAL_LOCK_WAIT_S);
        try {
            $params = $this->getRequestParams(self::ACTION_DEACTIVATE, $licenseSection, $ownerModuleName);
            $result = $this->sendRequest($params, false);

            // Only an answer about the license frees it here: a deactivation the server confirms, or a refusal
            // saying it holds no such installation (a license code). Anything else leaves the license as it was,
            // so it keeps working and can be deactivated again.
            if (!$this->lastRequestAnswered || !$this->isLicenceVerdict($result)) {
                $result['success']   = false;
                $result['is_active'] = $this->isActive($licenseSection);
                if (!$this->lastRequestAnswered) {
                    $result['errors'] = [__('Could not reach the license server. Please try again.')];
                } elseif ($result['errors'] === []) {
                    $result['errors'] = [__('The license server refused the request.')];
                }
                unset($result['access_token']);
                $result['status'] = $this->getStatus($licenseSection)->toArray();

                return $result;
            }

            $this->saveLicenseConfig($licenseSection, self::PATH_ACTIVE, 0);
            $this->saveLicenseConfig($licenseSection, self::PATH_DEVELOPMENT, 0);
            $this->saveLicenseConfig($licenseSection, self::PATH_VALID_UNTIL, '');
            $this->clearCoverage($licenseSection, $ownerModuleName);
            $this->saveLicenseConfig($licenseSection, self::PATH_ACCESS_TOKEN, (string)($result['access_token'] ?? ''));
            if (!$result['success']) {
                $result['warnings'][] = __('The license server no longer holds this installation, so the license is switched off here as well.');
                $result['errors']     = [];
                $result['success']    = true;
            }
            $result['messages'][] = __('License successfully deactivated!');
            $result['is_active']  = false;
            $this->reinitableConfig->reinit();

            unset($result['access_token']);
            $result['status'] = $this->getStatus($licenseSection)->toArray();
            return $result;
        } finally {
            if ($locked) {
                $this->lockManager->unlock($lock);
            }
        }
    }

    /**
     * Accepts the license agreement of the product generation the serial has a free upgrade to. The
     * answer carries the new coverage verdict, which is stored as after a verify; a refusal leaves the
     * stored licence as it was.
     *
     * @param string $adminEmail the admin user who accepts, '' when unknown
     * @param string $assentText the text of the box the admin checked
     * @return array<string, mixed>
     */
    public function acceptUpgrade(string $licenseSection, string $ownerModuleName, string $adminEmail, string $assentText): array
    {
        $lock   = CoverageRefresher::LOCK_PREFIX . $licenseSection;
        $locked = $this->lockManager->lock($lock, self::MANUAL_LOCK_WAIT_S);
        try {
            $params = $this->getRequestParams(self::ACTION_ACCEPT_UPGRADE, $licenseSection, $ownerModuleName) + [
                'eula'        => '1',
                'admin_email' => $adminEmail,
                'assent_text' => $assentText,
            ];
            $result = $this->sendRequest($params, false);
            unset($result['access_token']);

            if (!$this->lastRequestAnswered) {
                // The server may have recorded the acceptance before the answer was lost.
                $result['errors'] = [__('We could not confirm the acceptance. Click Recheck License Status.')];

                return $result;
            }

            if (!$result['success']) {
                return $this->explainRefusedAcceptance($result, $ownerModuleName);
            }

            $this->saveLicenseConfig($licenseSection, self::PATH_VERIFIED_TIME, $this->dateTime->gmtTimestamp());
            $this->recordAnswer($licenseSection, $ownerModuleName, (string)($params['module_version'] ?? ''), $result);

            return $result;
        } finally {
            if ($locked) {
                $this->lockManager->unlock($lock);
            }
        }
    }

    /**
     * @param array<string, mixed> $result
     * @return array<string, mixed>
     */
    private function explainRefusedAcceptance(array $result, string $ownerModuleName): array
    {
        $code = $result['error_code'] ?? null;
        if ($code === self::ERROR_RENEWAL_REQUIRED) {
            $result['errors'] = [__('This license needs a support renewal before the upgrade.')];

            return $result;
        }
        if ($code === self::ERROR_NOT_UPGRADABLE) {
            $result['errors'] = [__('This serial has no free upgrade to accept.')];

            return $result;
        }

        $result = $this->activationError->apply($result, $ownerModuleName);
        if ($result['errors'] === []) {
            $result['errors'] = [__('The license server refused the request.')];
        }

        return $result;
    }

    public function verifyLicense(string $licenseSection, string $ownerModuleName)
    {
        $lastVerifiedTime = (int)$this->getLicenseConfig($licenseSection, self::PATH_VERIFIED_TIME);
        $currentTime      = $this->dateTime->gmtTimestamp();

        if (($currentTime - $lastVerifiedTime) > 86400 * 14
            && $this->isActive($licenseSection)) {
            $this->fetchLiveStatus($licenseSection, $ownerModuleName);
        }
    }

    public function isActive(string $licenseSection): bool
    {
        return $this->getLicenseConfig($licenseSection, self::PATH_ACTIVE) && $this->getSerial($licenseSection);
    }

    /** @return string|null */
    public function getSerial(string $licenseSection)
    {
        $value = $this->getLicenseConfig($licenseSection, self::PATH_SERIAL);
        return $value !== '' ? $value : null;
    }

    public function getStatus(string $licenseSection): LicenseStatus
    {
        return $this->statusMapper->fromStoredConfig([
            'active'      => $this->getLicenseConfig($licenseSection, self::PATH_ACTIVE),
            'development' => $this->getLicenseConfig($licenseSection, self::PATH_DEVELOPMENT),
            'valid_until' => $this->getLicenseConfig($licenseSection, self::PATH_VALID_UNTIL),
        ]);
    }

    /**
     * @param bool $background true for the automatic refresh: shorter timeouts, and the config cache is
     *                         left alone because every reader of the verdict reads it uncached
     * @param bool $scheduled  ignored: no check switches the license on or off, whoever runs it
     */
    public function fetchLiveStatus(
        string $licenseSection,
        string $ownerModuleName,
        bool $background = false,
        bool $scheduled = false
    ): LicenseStatus {
        $params = $this->getRequestParams(self::ACTION_VERIFY, $licenseSection, $ownerModuleName);
        $result = $this->sendRequest($params, $background);

        // An unanswered request, or a refusal that names no license code (a passing fault, a rate limit), is a
        // failed check: the stored state stands, marked stale so the caller can say the check did not go through,
        // and the next check is pushed back.
        if (!$this->lastRequestAnswered || !$this->isLicenceVerdict($result)) {
            $this->recordFailure($licenseSection);
            $stored = $this->getStatus($licenseSection);

            return new LicenseStatus($stored->state, $stored->isActive, $stored->isDev, $stored->validUntil, true);
        }

        // Activate or Deactivate may have run while this request was on the wire; its answer is about
        // a serial or token that is no longer the stored one and must not overwrite them.
        if ((string)$params['serial'] !== (string)$this->getSerial($licenseSection)
            || (string)$params['access_token'] !== (string)$this->getLicenseConfig($licenseSection, self::PATH_ACCESS_TOKEN)) {
            $stored = $this->getStatus($licenseSection);

            return new LicenseStatus($stored->state, $stored->isActive, $stored->isDev, $stored->validUntil, true);
        }

        // No check switches the license on or off, whatever the server answers: only Activate and Deactivate
        // do. A grant refreshes the support date and the coverage; a refusal leaves both and is told as one.
        $answered = $this->statusMapper->fromApiResponse($result);
        $this->saveLicenseConfig($licenseSection, self::PATH_VERIFIED_TIME, $this->dateTime->gmtTimestamp());
        if (!empty($result['success'])) {
            if ($answered->isActive) {
                $this->saveLicenseConfig($licenseSection, self::PATH_VALID_UNTIL, (string)$answered->validUntil);
            }
            $this->recordAnswer($licenseSection, $ownerModuleName, (string)($params['module_version'] ?? ''), $result);
        } else {
            $this->recordRefusal($licenseSection, (string)($params['module_version'] ?? ''));
        }
        if (!$background) {
            $this->reinitableConfig->reinit();
        }

        $stored = $this->getStatus($licenseSection);

        return new LicenseStatus(
            $stored->state,
            $stored->isActive,
            $stored->isDev,
            $stored->validUntil,
            false,
            $answered->isActive ? null : $this->refusalText($result, $ownerModuleName)
        );
    }

    /**
     * What tells this installation apart for the check schedule (RefreshPolicy): its base URL. Read here and
     * hashed there; it is never sent anywhere for this purpose.
     */
    private function installationKey(): string
    {
        return (string)$this->scopeConfig->getValue('web/unsecure/base_url');
    }

    /**
     * Whether an answer is a verdict on the licence: granted, or refused with a code about the licence.
     *
     * @param array<string, mixed> $result
     */
    private function isLicenceVerdict(array $result): bool
    {
        return !empty($result['success'])
            || in_array($result['error_code'] ?? null, self::LICENCE_REFUSAL_CODES, true);
    }

    /**
     * Everything stored under <section>/license/, read in one uncached query, so a verdict written a
     * moment ago is what the caller sees.
     *
     * @return array<string, string> path id (e.g. {@see self::PATH_COVERAGE_CODE}) => value
     */
    public function getLicenseRecord(string $licenseSection): array
    {
        $prefix     = $licenseSection . self::PATH_LICENSE . '/';
        $collection = $this->configCollectionFactory->create();
        $collection->addFieldToFilter('path', ['like' => $prefix . '%']);

        $record = [];
        foreach ($collection->getItems() as $item) {
            $path = (string)$item->getData('path');
            // LIKE treats "_" in a section name as a wildcard; keep exact prefix matches only.
            if (strpos($path, $prefix) !== 0) {
                continue;
            }
            $record[substr($path, strlen($licenseSection))] = (string)$item->getData('value');
        }

        return $record;
    }

    public function getInstalledVersion(string $ownerModuleName): string
    {
        return $this->installedVersion->get($ownerModuleName);
    }

    /**
     * @param string $action
     * @param string $licenseSection
     * @param string $ownerModuleName
     * @param string|null $serial
     * @return array
     */
    private function getRequestParams(string $action, string $licenseSection, string $ownerModuleName, $serial = null): array
    {
        if (!$serial) {
            $serial = $this->getSerial($licenseSection);
        }

        $params = [
            'action'         => $action,
            'serial'         => $serial,
            'platform'       => $this->getPlatformCode(),
            'magento_edition'=> $this->productMetadata->getEdition(),
            'magento_version'=> $this->productMetadata->getVersion(),
            'module_name'    => $ownerModuleName,
            'access_token'   => (string)$this->getLicenseConfig($licenseSection, self::PATH_ACCESS_TOKEN),
            'activated_url'  => (string)$this->scopeConfig->getValue('web/unsecure/base_url'),
        ];

        // Without a version the server answers the way it always has, with no coverage verdict.
        $moduleVersion = $this->getInstalledVersion($ownerModuleName);
        if ($moduleVersion !== '') {
            $params['module_version'] = $moduleVersion;
        }

        return $params;
    }

    /**
     * Stores the coverage verdict of an answered request next to the activation, never instead of it:
     * an uncovered version keeps its serial and token, so the next check after a renewal lifts the lock.
     *
     * @param string $checkedVersion the module_version the request carried, '' when none was sent
     * @param array<string, mixed> $result
     */
    private function recordAnswer(string $licenseSection, string $ownerModuleName, string $checkedVersion, array $result): void
    {
        $now  = (int)$this->dateTime->gmtTimestamp();
        $code = isset($result['code']) && in_array($result['code'], self::COVERAGE_CODES, true) ? $result['code'] : '';
        $until = isset($result['covered_until']) && is_string($result['covered_until']) ? $result['covered_until'] : '';

        $this->saveLicenseConfig($licenseSection, self::PATH_COVERAGE_CODE, $code);
        $this->saveLicenseConfig($licenseSection, self::PATH_COVERED_UNTIL, $code !== '' ? $until : '');
        $this->saveLicenseConfig($licenseSection, self::PATH_COVERAGE_VERSION, $checkedVersion);
        $this->saveLicenseConfig(
            $licenseSection,
            self::PATH_LATEST_COVERED,
            $code === RefreshPolicy::CODE_NOT_COVERED ? $this->plainVersion($result['latest_covered_version'] ?? null) : ''
        );
        $upgradeRequired = ($result['upgrade_required'] ?? false) === true;
        $upgradeKind     = $result['upgrade'] ?? '';
        $this->saveLicenseConfig($licenseSection, self::PATH_UPGRADE_REQUIRED, $upgradeRequired ? 1 : 0);
        $this->saveLicenseConfig(
            $licenseSection,
            self::PATH_UPGRADE_KIND,
            $upgradeRequired && in_array($upgradeKind, CoverageVerdict::UPGRADE_KINDS, true) ? $upgradeKind : ''
        );
        $this->saveLicenseConfig($licenseSection, self::PATH_VERIFY_ATTEMPT, $now);
        $this->saveLicenseConfig($licenseSection, self::PATH_VERIFY_FAILURES, 0);
        $this->saveLicenseConfig($licenseSection, self::PATH_NEXT_CHECK_AT, $this->refreshPolicy->nextAfterAnswer($code, $now, $this->installationKey()));

        $this->notifyOnce($licenseSection, $ownerModuleName, $checkedVersion, $code, $until);
    }

    /**
     * @param mixed $value
     */
    private function plainVersion($value): string
    {
        return is_string($value) && preg_match('/^\d+\.\d+\.\d+$/', $value) ? $value : '';
    }

    /**
     * One inbox message per module: repeated checks stay silent; a newly installed version that is not covered
     * either, or losing coverage again after a renewal, replaces the message; coverage retires it.
     */
    private function notifyOnce(string $licenseSection, string $ownerModuleName, string $version, string $code, string $coveredUntil): void
    {
        $notified = (string)$this->getLicenseConfig($licenseSection, self::PATH_NOTIFIED_VERSION);
        if ($code === RefreshPolicy::CODE_OK) {
            if ($notified !== '') {
                $this->retireNotice($licenseSection, $ownerModuleName, $notified);
            }
            return;
        }
        if ($code !== RefreshPolicy::CODE_NOT_COVERED || $version === $notified) {
            return;
        }
        if ($notified !== '' && !$this->retireNotice($licenseSection, $ownerModuleName, $notified)) {
            return;
        }

        try {
            $messageId = $this->coverageNotifier->notifyNotCovered(
                $ownerModuleName,
                $version,
                $coveredUntil !== '' ? $coveredUntil : null,
                (bool)$this->getLicenseConfig($licenseSection, self::PATH_DEVELOPMENT),
                (string)$this->getSerial($licenseSection),
                (bool)$this->getLicenseConfig($licenseSection, self::PATH_UPGRADE_REQUIRED),
                (string)$this->getLicenseConfig($licenseSection, self::PATH_UPGRADE_KIND)
            );
            $this->saveLicenseConfig($licenseSection, self::PATH_NOTIFIED_VERSION, $version);
            $this->saveLicenseConfig($licenseSection, self::PATH_NOTIFIED_MESSAGE, $messageId);
        } catch (\Throwable $e) {
            // The notice is a courtesy; the verdict itself is already stored.
        }
    }

    /**
     * Marks the module's message read and forgets it. When the inbox cannot be reached the message is kept in
     * mind, so the next check retires it before telling anything new.
     */
    private function retireNotice(string $licenseSection, string $ownerModuleName, string $version): bool
    {
        try {
            $this->coverageNotifier->markResolved(
                $ownerModuleName,
                $version,
                (int)$this->getLicenseConfig($licenseSection, self::PATH_NOTIFIED_MESSAGE)
            );
        } catch (\Throwable $e) {
            return false;
        }
        $this->saveLicenseConfig($licenseSection, self::PATH_NOTIFIED_VERSION, '');
        $this->saveLicenseConfig($licenseSection, self::PATH_NOTIFIED_MESSAGE, '');

        return true;
    }

    /**
     * A check that got no answer changes nothing about the licence; it only pushes the next attempt back.
     */
    public function recordFailure(string $licenseSection): void
    {
        $now      = (int)$this->dateTime->gmtTimestamp();
        $failures = (int)$this->getLicenseConfig($licenseSection, self::PATH_VERIFY_FAILURES) + 1;

        $this->saveLicenseConfig($licenseSection, self::PATH_VERIFY_ATTEMPT, $now);
        $this->saveLicenseConfig($licenseSection, self::PATH_VERIFY_FAILURES, $failures);
        $this->saveLicenseConfig($licenseSection, self::PATH_NEXT_CHECK_AT, $this->refreshPolicy->nextAfterFailure($failures, $now, $this->installationKey()));
    }

    /**
     * The server did not grant the license: the coverage it last gave for this version stands, and the next check
     * follows it. For another version than the one it covered, the version is taken as checked with no verdict,
     * so a module update is not checked again until the next check is due.
     */
    private function recordRefusal(string $licenseSection, string $checkedVersion): void
    {
        $now  = (int)$this->dateTime->gmtTimestamp();
        $code = (string)$this->getLicenseConfig($licenseSection, self::PATH_COVERAGE_CODE);
        if ($checkedVersion !== (string)$this->getLicenseConfig($licenseSection, self::PATH_COVERAGE_VERSION)) {
            $code = '';
            $this->saveLicenseConfig($licenseSection, self::PATH_COVERAGE_CODE, '');
            $this->saveLicenseConfig($licenseSection, self::PATH_COVERED_UNTIL, '');
            $this->saveLicenseConfig($licenseSection, self::PATH_COVERAGE_VERSION, $checkedVersion);
        }

        $this->saveLicenseConfig($licenseSection, self::PATH_VERIFY_ATTEMPT, $now);
        $this->saveLicenseConfig($licenseSection, self::PATH_VERIFY_FAILURES, 0);
        $this->saveLicenseConfig($licenseSection, self::PATH_NEXT_CHECK_AT, $this->refreshPolicy->nextAfterAnswer($code, $now, $this->installationKey()));
    }

    /**
     * @param array<string, mixed> $result an answer that does not grant the license
     */
    private function refusalText(array $result, string $ownerModuleName): string
    {
        if (!empty($result['success'])) {
            return (string)__('The license server reports this license as not active.');
        }
        $reason = $this->activationError->apply($result, $ownerModuleName)['errors'][0] ?? '';

        return (string)__('The license server did not grant this license: %1', (string)$reason);
    }

    private function clearCoverage(string $licenseSection, string $ownerModuleName): void
    {
        $notified = (string)$this->getLicenseConfig($licenseSection, self::PATH_NOTIFIED_VERSION);
        if ($notified !== '') {
            // retireNotice() forgets the message itself, and keeps it in mind when the inbox could not be reached.
            $this->retireNotice($licenseSection, $ownerModuleName, $notified);
        }
        foreach ([
            self::PATH_COVERAGE_CODE,
            self::PATH_COVERED_UNTIL,
            self::PATH_COVERAGE_VERSION,
            self::PATH_VERIFY_FAILURES,
            self::PATH_NEXT_CHECK_AT,
            self::PATH_LATEST_COVERED,
            self::PATH_UPGRADE_REQUIRED,
            self::PATH_UPGRADE_KIND,
        ] as $pathId) {
            $this->saveLicenseConfig($licenseSection, $pathId, '');
        }
    }

    private function getPlatformCode(): string
    {
        return 'M2' . ($this->productMetadata->getEdition() === 'Community' ? 'CE' : 'EE');
    }

    /**
     * @param string $licenseSection
     * @param string $pathId
     * @return mixed
     */
    private function getLicenseConfig(string $licenseSection, string $pathId)
    {
        $collection = $this->configCollectionFactory->create();
        $collection->addFieldToFilter('path', ['eq' => $licenseSection . $pathId]);
        return $collection->count() ? $collection->getFirstItem()->getData()['value'] : '';
    }

    /**
     * @param string $licenseSection
     * @param string $pathId
     * @param mixed $value
     */
    private function saveLicenseConfig(string $licenseSection, string $pathId, $value = null)
    {
        $this->config->saveConfig($licenseSection . $pathId, $value, 'default');
    }

    /**
     * A JSON array decodes to a PHP list; the license server always answers with an object.
     * (array_is_list() is PHP 8.1 and this module still supports 7.4.)
     *
     * @param array<mixed> $value
     */
    private function isList(array $value): bool
    {
        return array_keys($value) === range(0, count($value) - 1);
    }

    private function sendRequest(array $params, bool $background): array
    {
        $this->lastRequestAnswered = false;

        $result = [
            'success'   => false,
            'is_active' => false,
            'messages'  => [],
            'warnings'  => [],
            'errors'    => [],
        ];

        $this->curl->setOption(CURLOPT_RETURNTRANSFER, true);
        // The body carries the serial and the access token, so a redirect must not be followed:
        // whatever host the response names would receive them.
        $this->curl->setOption(CURLOPT_FOLLOWLOCATION, false);
        $this->curl->setOption(CURLOPT_CONNECTTIMEOUT, $background ? self::BACKGROUND_CONNECT_TIMEOUT_S : self::CONNECT_TIMEOUT_S);
        $this->curl->setOption(CURLOPT_TIMEOUT, $background ? self::BACKGROUND_REQUEST_TIMEOUT_S : self::REQUEST_TIMEOUT_S);
        $this->curl->setOption(CURLOPT_USERAGENT, self::USER_AGENT);

        try {
            $this->curl->post(static::URL, $params);
        } catch (Exception $e) {
            $result['errors'][] = __('Could not reach the license server. Please try again.');
            return $result;
        }

        $status = (int)$this->curl->getStatus();
        if ($status < 200 || $status > 299) {
            $result['errors'][] = __('Unexpected license server response.');
            return $result;
        }

        $response = json_decode($this->curl->getBody(), true);

        // A licence answer always states success; any other object is a proxy or maintenance page.
        if (!is_array($response) || $this->isList($response) || !array_key_exists('success', $response)) {
            $result['errors'][] = __('Unexpected license server response.');

            return $result;
        }

        $this->lastRequestAnswered = true;

        $result = array_merge($result, $response);
        // The response owns these keys once merged, and callers append to them — a scalar in any of
        // them would turn "License activated." into a fatal.
        foreach (['messages', 'warnings', 'errors'] as $key) {
            if (!is_array($result[$key])) {
                $result[$key] = [];
            }
        }
        $result['success'] = !empty($result['success']);

        return $result;
    }
}
