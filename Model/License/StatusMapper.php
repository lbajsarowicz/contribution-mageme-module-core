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

use Psr\Log\LoggerInterface;

class StatusMapper
{
    /** @var LoggerInterface */
    private $logger;

    public function __construct(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    public function fromStoredConfig(array $stored): LicenseStatus
    {
        $isActive = !empty($stored['active']) && (string)$stored['active'] !== '0';
        $isDev = !empty($stored['development']) && (string)$stored['development'] !== '0';
        $validUntil = $stored['valid_until'] ?? null;
        if ($validUntil === '') {
            $validUntil = null;
        }
        return new LicenseStatus(
            $this->resolveState($isActive, $isDev, $validUntil),
            $isActive,
            $isDev,
            $validUntil
        );
    }

    public function fromApiResponse(array $response): LicenseStatus
    {
        $success = !empty($response['success']);
        $isActive = $success && $this->isTrue($response['active'] ?? null);
        $isDev = $isActive && $this->isTrue($response['dev'] ?? null);
        $validUntil = $this->validUntil($response['support_expiry_date'] ?? null);
        return new LicenseStatus(
            $this->resolveState($isActive, $isDev, $validUntil),
            $isActive,
            $isDev,
            $validUntil
        );
    }

    /**
     * The support expiry date as a Y-m-d string; anything else is dropped, and logged unless it was empty.
     *
     * @param mixed $value
     */
    private function validUntil($value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_string($value)
            && preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m) === 1
            && checkdate((int)$m[2], (int)$m[3], (int)$m[1])) {
            return $value;
        }
        $this->logger->warning(
            'MageMe license: support_expiry_date is not a Y-m-d date',
            ['value' => is_scalar($value) ? (string)$value : gettype($value)]
        );

        return null;
    }

    /**
     * @param mixed $value
     */
    private function isTrue($value): bool
    {
        return is_scalar($value) && !empty($value) && (string)$value !== '0';
    }

    private function resolveState(bool $isActive, bool $isDev, ?string $validUntil): string
    {
        if (!$isActive) {
            return LicenseStatus::STATE_INACTIVE;
        }
        if ($isDev) {
            return LicenseStatus::STATE_DEV;
        }
        if ($validUntil !== null && preg_match('/^\d{4}-\d{2}-\d{2}/', $validUntil) && $validUntil < date('Y-m-d')) {
            return LicenseStatus::STATE_EXPIRED;
        }
        return LicenseStatus::STATE_ACTIVE;
    }
}
