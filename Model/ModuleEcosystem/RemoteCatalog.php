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

namespace MageMe\Core\Model\ModuleEcosystem;

use Exception;
use Magento\Framework\App\Cache\Type\Config as CacheTypeConfig;
use Magento\Framework\App\CacheInterface;
use Magento\Framework\FlagManager;
use Magento\Framework\HTTP\Client\Curl;
use Psr\Log\LoggerInterface;

/**
 * info.mageme.com/modules.json cache.
 * getAll() is read-only (memo + Magento cache, never network); refresh() is the only
 * network entry point and is called from the async admin controller and from the license
 * cron when the cache holds no catalog, never from the PHP render path.
 *
 * An entry may describe several generations of its module: `generations` maps a major to the fields that
 * differ for it. getAll() and get() hand out each entry as the installed generation sees it — those
 * fields over the top-level ones. For an add-on the major is the one of the installed module it extends (named
 * by `is_addon_of`, or else the only entry listing it among its add-ons) when the add-on has a generation under
 * that key — whether the add-on is not installed or one of another major is; otherwise, and for every other module, it is
 * the major of the installed version of the module itself. Anything that names no generation (no `generations`,
 * no key for the major, a value that is not a map, a version that cannot be read, a module not installed and no
 * single installed base) leaves the entry as it is: by the contract of the generator, the top level of an entry
 * that has generations is its legacy generation.
 *
 * The flag FLAG_REFRESH keeps the time of the last refresh attempt and of the last successful one; the panel and the
 * cron ask for a new copy when it is older than REFRESH_INTERVAL_S, or when the cache lost it (at once if the last
 * attempt got it, RESTORE_AFTER_S after that attempt at the earliest), and after an attempt that failed wait
 * RETRY_INTERVAL_S before the next one, so an unreachable catalog server is not asked on every admin page.
 */
class RemoteCatalog
{
    public const API_URL         = 'https://info.mageme.com/modules.json';
    public const CACHE_KEY       = 'MAGEME_modules_catalog';
    public const CACHE_GROUP     = CacheTypeConfig::TYPE_IDENTIFIER;
    public const CACHE_TAG       = 'extensions';
    public const FETCH_TIMEOUT_S = 5;
    public const FLAG_REFRESH       = 'mageme_core_catalog_refresh';
    public const REFRESH_INTERVAL_S = 21600;
    public const RETRY_INTERVAL_S   = 3600;
    /** A cache that does not keep the catalog at all is not answered by a request on every page. */
    public const RESTORE_AFTER_S    = 60;
    /** Vendor_Module — the only key shape a real catalog has. */
    private const MODULE_NAME_PATTERN = '/^[A-Za-z][A-Za-z0-9]*_[A-Za-z][A-Za-z0-9]*$/';

    /** @var CacheInterface */
    private $cache;
    /** @var Curl */
    private $curl;
    /** @var LoggerInterface */
    private $logger;
    /** @var InstalledMajor */
    private $installedMajor;
    /** @var FlagManager */
    private $flagManager;
    /** @var array<string, mixed>|null */
    private $memo = null;

    public function __construct(
        CacheInterface $cache,
        Curl $curl,
        LoggerInterface $logger,
        InstalledMajor $installedMajor,
        FlagManager $flagManager
    ) {
        $this->cache          = $cache;
        $this->curl           = $curl;
        $this->logger         = $logger;
        $this->installedMajor = $installedMajor;
        $this->flagManager    = $flagManager;
    }

    /** @return array<string, array<string, mixed>> */
    public function getAll(): array
    {
        if ($this->memo !== null) {
            return $this->memo;
        }

        $cached = $this->cache->load(self::CACHE_KEY);
        if ($cached) {
            $decoded = json_decode($cached, true);
            if (is_array($decoded)) {
                return $this->memo = $this->forInstalledGenerations($decoded);
            }
        }

        return $this->memo = [];
    }

    /** @return array<string, mixed> */
    public function get(string $moduleName): array
    {
        $all = $this->getAll();
        return isset($all[$moduleName]) ? $all[$moduleName] : [];
    }

    /**
     * Stale cache preserved on failure.
     *
     * The cache has no lifetime, so whatever lands here stays until the next successful refresh —
     * which is why an error page that happens to be valid JSON must never get that far. A non-2xx
     * status and a payload that is not a map of module entries are both refused. A copy identical to the cached one is
     * not written again.
     */
    public function refresh(int $timeout = self::FETCH_TIMEOUT_S): bool
    {
        try {
            $this->markRefresh('attempt');
            $this->curl->setOption(CURLOPT_TIMEOUT, $timeout);
            $this->curl->get(self::API_URL);
            $status = (int)$this->curl->getStatus();
            if ($status < 200 || $status > 299) {
                $this->logger->warning('MageMe RemoteCatalog refresh: HTTP ' . $status);
                return false;
            }
            $decoded = json_decode((string)$this->curl->getBody(), true);
            if (!$this->isCatalog($decoded)) {
                $this->logger->warning('MageMe RemoteCatalog refresh: response is not a module catalog');
                return false;
            }
            $encoded = (string)json_encode($decoded);
            if ($this->cache->load(self::CACHE_KEY) !== $encoded) {
                $this->cache->save($encoded, self::CACHE_KEY, [self::CACHE_TAG]);
            }
            $this->memo = $this->forInstalledGenerations($decoded);
            $this->markRefresh('success');
            return true;
        } catch (Exception $e) {
            $this->logger->info('MageMe RemoteCatalog refresh failed: ' . $e->getMessage());
            return false;
        }
    }

    /** Unix time of the last refresh attempt, null when there was none. */
    public function getLastAttemptAt(): ?int
    {
        return $this->readRefresh('attempt');
    }

    /** Unix time of the last successful refresh, null when there was none. */
    public function getLastSuccessAt(): ?int
    {
        return $this->readRefresh('success');
    }

    /** No attempt yet, or the last one is RETRY_INTERVAL_S old or older. */
    public function mayAttempt(): bool
    {
        $attempt = $this->getLastAttemptAt();

        return $attempt === null || time() - $attempt >= self::RETRY_INTERVAL_S;
    }

    /**
     * Whether to ask info.mageme.com now. A catalog the cache lost is asked for at once when the last attempt got
     * it (RESTORE_AFTER_S after that attempt at the earliest), and RETRY_INTERVAL_S after one that failed. A catalog
     * in the cache is asked for when it is REFRESH_INTERVAL_S old and a new attempt is allowed.
     */
    public function isStale(): bool
    {
        $success = $this->getLastSuccessAt();
        if ($this->getAll() === []) {
            $attempt = $this->getLastAttemptAt();

            $lastGotIt = $success !== null && $success >= $attempt;

            return $attempt === null
                || ($lastGotIt && time() - $attempt >= self::RESTORE_AFTER_S)
                || $this->mayAttempt();
        }

        return ($success === null || time() - $success >= self::REFRESH_INTERVAL_S) && $this->mayAttempt();
    }

    private function markRefresh(string $key): void
    {
        $data = $this->flagManager->getFlagData(self::FLAG_REFRESH);
        $data = is_array($data) ? $data : [];
        $data[$key] = time();
        $this->flagManager->saveFlag(self::FLAG_REFRESH, $data);
    }

    private function readRefresh(string $key): ?int
    {
        $data = $this->flagManager->getFlagData(self::FLAG_REFRESH);
        $value = is_array($data) ? ($data[$key] ?? null) : null;

        return is_int($value) && $value > 0 ? $value : null;
    }

    /**
     * @param array<string, mixed> $catalog
     * @return array<string, mixed>
     */
    private function forInstalledGenerations(array $catalog): array
    {
        $bases = $this->basesOfAddons($catalog);
        foreach ($catalog as $moduleName => $entry) {
            if (!is_array($entry) || !isset($entry['generations']) || !is_array($entry['generations'])) {
                continue;
            }
            $major = $this->installedMajor->of((string)$moduleName);
            $base  = count($bases[$moduleName] ?? []) === 1 ? $this->installedMajor->of($bases[$moduleName][0]) : null;
            if ($base !== null && $base !== $major && is_array($entry['generations'][(string)$base] ?? null)) {
                // An add-on is offered for the generation of the module it extends, whatever major of it is installed.
                $major = $base;
            }
            $own = $major === null ? null : ($entry['generations'][(string)$major] ?? null);
            if (is_array($own)) {
                $catalog[$moduleName] = array_replace($entry, $own);
            }
        }

        return $catalog;
    }

    /**
     * The modules each add-on extends: the one its entry names in `is_addon_of`, or else every entry that lists it
     * among its add-ons, at the top or in any generation.
     *
     * @param array<string, mixed> $catalog
     * @return array<string, string[]> add-on => base modules
     */
    private function basesOfAddons(array $catalog): array
    {
        $named  = [];
        $listed = [];
        foreach ($catalog as $moduleName => $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $layers = [$entry];
            foreach (is_array($entry['generations'] ?? null) ? $entry['generations'] : [] as $generation) {
                if (is_array($generation)) {
                    $layers[] = $generation;
                }
            }
            foreach ($layers as $layer) {
                if (isset($layer['is_addon_of']) && is_string($layer['is_addon_of']) && $layer['is_addon_of'] !== '') {
                    $named[(string)$moduleName][$layer['is_addon_of']] = true;
                }
                foreach (is_array($layer['add-ons'] ?? null) ? $layer['add-ons'] : [] as $addon) {
                    if (is_string($addon) && $addon !== '') {
                        $listed[$addon][(string)$moduleName] = true;
                    }
                }
            }
        }

        $bases = [];
        foreach ($named + $listed as $addon => $modules) {
            $bases[$addon] = array_keys($modules);
        }

        return $bases;
    }

    /**
     * A catalog is a non-empty map of Vendor_Module => non-empty entry. Anything else — a list, a
     * scalar, an empty object, an error document such as {"error": {"message": "..."}} — is refused
     * rather than cached over good data. Entry contents are deliberately NOT schema-checked: the
     * catalog gains fields over time, and rejecting the whole payload over an unknown key would
     * break every panel the day a new field ships.
     *
     * @param mixed $decoded
     */
    private function isCatalog($decoded): bool
    {
        if (!is_array($decoded) || $decoded === []) {
            return false;
        }
        foreach ($decoded as $moduleName => $entry) {
            if (!is_string($moduleName) || preg_match(self::MODULE_NAME_PATTERN, $moduleName) !== 1) {
                return false;
            }
            if (!is_array($entry) || $entry === []) {
                return false;
            }
        }

        return true;
    }
}
