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

namespace MageMe\Core\Cron;

use MageMe\Core\Model\License\CoverageRefresher;
use MageMe\Core\Model\ModuleEcosystem\RemoteCatalog;
use Psr\Log\LoggerInterface;
use Throwable;

class RefreshLicenseCoverage
{
    /** A cron run must not wait long on the catalog: the license checks come after it. */
    public const CATALOG_TIMEOUT_S = 2;

    /** @var CoverageRefresher */
    private $refresher;
    /** @var RemoteCatalog */
    private $catalog;
    /** @var LoggerInterface */
    private $logger;

    public function __construct(CoverageRefresher $refresher, RemoteCatalog $catalog, LoggerInterface $logger)
    {
        $this->refresher = $refresher;
        $this->catalog   = $catalog;
        $this->logger    = $logger;
    }

    public function execute(): void
    {
        $this->loadMissingCatalog();
        $this->refresher->refreshDue();
    }

    /**
     * The catalog names the modules in license messages and gives the settings panel its license block, so a store
     * whose cache was cleaned gets it back without anyone opening a MageMe page.
     */
    private function loadMissingCatalog(): void
    {
        try {
            if ($this->catalog->getAll() === [] && $this->catalog->isStale()) {
                $this->catalog->refresh(self::CATALOG_TIMEOUT_S);
            }
        } catch (Throwable $e) {
            $this->logger->info('MageMe catalog load from cron failed: ' . $e->getMessage());
        }
    }
}
