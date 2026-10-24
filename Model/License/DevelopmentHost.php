<?php
/**
 * Copyright © MageMe. All rights reserved.
 * See LICENSE for license terms, or https://mageme.com/license.
 */

declare(strict_types=1);

namespace MageMe\Core\Model\License;

use MageMe\Core\Api\DevelopmentHostInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;

class DevelopmentHost implements DevelopmentHostInterface
{
    private const BASE_URL_PATHS = ['web/unsecure/base_url', 'web/secure/base_url'];

    private const DEV_SUFFIXES = [
        '.test', '.local', '.localhost', '.docker', '.ddev.site', '.lndo.site', '.magentosite.cloud',
        '.hypernode.io', '.platformsh.site', '.ngrok.io', '.ngrok-free.app',
    ];

    private const DEV_FIRST_LABEL = '/^(staging|stage|stg|dev|uat|preview)(-|\d|$)/';

    /** IPv4 ranges as [network, prefix length]: RFC 1918 and loopback. */
    private const PRIVATE_IPV4 = [['10.0.0.0', 8], ['172.16.0.0', 12], ['192.168.0.0', 16], ['127.0.0.0', 8]];

    /** @var StoreManagerInterface */
    private $storeManager;
    /** @var ScopeConfigInterface */
    private $scopeConfig;

    public function __construct(StoreManagerInterface $storeManager, ScopeConfigInterface $scopeConfig)
    {
        $this->storeManager = $storeManager;
        $this->scopeConfig  = $scopeConfig;
    }

    public function isDevelopmentInstall(): bool
    {
        $stores = $this->storeManager->getStores(false);
        if (!$stores) {
            return false;
        }
        foreach ($stores as $store) {
            foreach (self::BASE_URL_PATHS as $path) {
                $url = $this->scopeConfig->getValue($path, ScopeInterface::SCOPE_STORE, $store->getId());
                if (!is_string($url) || !$this->isDevelopmentUrl($url)) {
                    return false;
                }
            }
        }

        return true;
    }

    private function isDevelopmentUrl(string $url): bool
    {
        if (strpos($url, '{{') !== false) {
            return false;
        }
        $scheme = parse_url($url, PHP_URL_SCHEME);
        $host   = parse_url($url, PHP_URL_HOST);
        if (!is_string($scheme) || !in_array(strtolower($scheme), ['http', 'https'], true)
            || !is_string($host) || $host === '') {
            return false;
        }
        $host = strtolower($host);

        if ($host[0] === '[') {
            $ip = substr($host, 1, -1);

            return substr($host, -1) === ']'
                && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false
                && $this->isPrivateIpv6($ip);
        }
        if (substr($host, -1) === '.') {
            $host = substr($host, 0, -1);
        }
        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            return $this->isPrivateIpv4($host);
        }
        if (substr($host, -1) === '.' || filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false) {
            return false;
        }
        if (strpos($host, '.') === false) {
            return true;
        }
        foreach (self::DEV_SUFFIXES as $suffix) {
            if (substr($host, -strlen($suffix)) === $suffix) {
                return true;
            }
        }

        return preg_match(self::DEV_FIRST_LABEL, explode('.', $host)[0]) === 1;
    }

    private function isPrivateIpv4(string $ip): bool
    {
        $address = ip2long($ip);
        foreach (self::PRIVATE_IPV4 as [$network, $prefix]) {
            $mask = -1 << (32 - $prefix);
            if (($address & $mask) === (ip2long($network) & $mask)) {
                return true;
            }
        }

        return false;
    }

    /** ::1 and the unique local range fc00::/7. */
    private function isPrivateIpv6(string $ip): bool
    {
        $packed = inet_pton($ip);

        return $packed === inet_pton('::1') || (ord($packed[0]) & 0xfe) === 0xfc;
    }
}
