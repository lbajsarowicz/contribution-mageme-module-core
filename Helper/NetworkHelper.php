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

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\HTTP\PhpEnvironment\RemoteAddress;

/**
 * The visitor's IP address as MageMe modules record it (submissions, quote and price requests).
 *
 * Without trusted proxies it reads like Magento does with the headers Core passes in di.xml: the first of
 * CF-Connecting-IP and X-Forwarded-For that is present (the leftmost address of X-Forwarded-For); when that value is
 * not a valid IP, REMOTE_ADDR. With trusted proxies configured, headers are believed only when the request comes from
 * one of them, and only X-Forwarded-For is read: walking it from the right past the trusted proxies, the first
 * address that is not one of them. An entry that cannot be read ends the walk with REMOTE_ADDR. The result is always
 * a valid IP, or REMOTE_ADDR.
 */
class NetworkHelper
{
    public const XML_PATH_TRUSTED_PROXIES = 'mageme/network/trusted_proxies';

    private const HEADER_XFF = 'HTTP_X_FORWARDED_FOR';
    private const HEADER_CF  = 'HTTP_CF_CONNECTING_IP';

    private RemoteAddress $remoteAddress;
    private HttpRequest $request;
    private ScopeConfigInterface $scopeConfig;

    public function __construct(RemoteAddress $remoteAddress, HttpRequest $request, ScopeConfigInterface $scopeConfig)
    {
        $this->remoteAddress = $remoteAddress;
        $this->request       = $request;
        $this->scopeConfig   = $scopeConfig;
    }

    public function getRealIp(): string
    {
        $peer    = $this->validIp((string)$this->request->getServer('REMOTE_ADDR', '')) ?? '';
        $trusted = $this->getTrustedProxies();

        if ($trusted === []) {
            // The store's own di.xml may put X-Forwarded-For ahead of the headers Core gives the remote address,
            // so the Cloudflare header is read here first; like any first header present, an invalid one gives the peer.
            $cloudflare = trim((string)$this->request->getServer(self::HEADER_CF, ''));
            if ($cloudflare !== '') {
                return $this->validIp($cloudflare) ?? $peer;
            }

            return $this->validIp((string)$this->remoteAddress->getRemoteAddress()) ?? $peer;
        }
        if ($peer === '' || !$this->isTrusted($peer, $trusted)) {
            return $peer;
        }

        $chain = array_reverse(explode(',', (string)$this->request->getServer(self::HEADER_XFF, '')));
        foreach ($chain as $candidate) {
            $ip = $this->validIp($this->withoutPort($candidate));
            if ($ip === null) {
                return $peer;
            }
            if (!$this->isTrusted($ip, $trusted)) {
                return $ip;
            }
        }

        return $peer;
    }

    /**
     * Trusted proxies from the configuration: IPv4/IPv6 addresses or CIDR ranges, separated by commas, spaces or
     * new lines. Entries that are neither are ignored.
     *
     * @return string[]
     */
    public function getTrustedProxies(): array
    {
        $raw = (string)$this->scopeConfig->getValue(self::XML_PATH_TRUSTED_PROXIES);
        $entries = preg_split('/[\s,;]+/', $raw, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return array_values(array_filter($entries, fn(string $entry): bool => $this->isValidEntry($entry)));
    }

    private function isValidEntry(string $entry): bool
    {
        if (strpos($entry, '/') === false) {
            return $this->validIp($entry) !== null;
        }
        [$network, $prefix] = explode('/', $entry, 2);
        $ip = $this->validIp($network);
        if ($ip === null || !ctype_digit($prefix)) {
            return false;
        }

        return (int)$prefix <= (strpos($ip, ':') === false ? 32 : 128);
    }

    /** 203.0.113.9:51234 and [2001:db8::1]:443 as some proxies append them */
    private function withoutPort(string $entry): string
    {
        $entry = trim($entry);
        if (preg_match('/^\[([0-9A-Fa-f:.]+)\](?::\d+)?$/', $entry, $m) === 1) {
            return $m[1];
        }
        if (preg_match('/^(\d{1,3}(?:\.\d{1,3}){3}):\d+$/', $entry, $m) === 1) {
            return $m[1];
        }

        return $entry;
    }

    /**
     * @param string[] $trusted
     */
    private function isTrusted(string $ip, array $trusted): bool
    {
        $address = $this->packed($ip);
        if ($address === null) {
            return false;
        }
        foreach ($trusted as $entry) {
            if (strpos($entry, '/') === false) {
                if ($this->packed($entry) === $address) {
                    return true;
                }
                continue;
            }
            [$network, $prefix] = explode('/', $entry, 2);
            $range = $this->packed($network);
            $bits  = (int)$prefix;
            if ($range !== null && strlen($range) === 4 && strlen((string)inet_pton($network)) === 16) {
                $bits -= 96;
            }
            if ($range !== null && $bits >= 0 && strlen($range) === strlen($address)
                && $this->sameNetwork($address, $range, $bits)) {
                return true;
            }
        }

        return false;
    }

    /** Binary form of an address, an IPv4-mapped IPv6 address (::ffff:10.1.2.3) as its IPv4 form. */
    private function packed(string $ip): ?string
    {
        $binary = inet_pton($ip);
        if ($binary === false) {
            return null;
        }
        if (strlen($binary) === 16 && strncmp($binary, str_repeat("\0", 10) . "\xff\xff", 12) === 0) {
            return substr($binary, 12);
        }

        return $binary;
    }

    private function sameNetwork(string $address, string $range, int $prefix): bool
    {
        $bytes = intdiv($prefix, 8);
        if (substr($address, 0, $bytes) !== substr($range, 0, $bytes)) {
            return false;
        }
        $bits = $prefix % 8;
        if ($bits === 0) {
            return true;
        }
        $mask = (0xff << (8 - $bits)) & 0xff;

        return (ord($address[$bytes]) & $mask) === (ord($range[$bytes]) & $mask);
    }

    /**
     * @return string|null the trimmed address when it is a valid IPv4/IPv6 address
     */
    private function validIp(string $value): ?string
    {
        $value = trim($value);

        return $value !== '' && filter_var($value, FILTER_VALIDATE_IP) !== false ? $value : null;
    }
}
