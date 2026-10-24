<?php
/**
 * Copyright © MageMe. All rights reserved.
 * See LICENSE for license terms, or https://mageme.com/license.
 */

declare(strict_types=1);

namespace MageMe\Core\Model\License;

use MageMe\Core\Model\ModuleEcosystem\RemoteCatalog;
use Magento\Framework\Phrase;

/**
 * Explains a refused activation by the server's error_code.
 */
class ActivationError
{
    public const MY_PRODUCTS_URL = Urls::MY_PRODUCTS;

    public const CODE_INVALID_REQUEST = 'invalid_request';
    public const CODE_NOT_FOUND       = 'not_found';
    public const CODE_WRONG_PRODUCT   = 'wrong_product';
    public const CODE_DISABLED        = 'disabled';
    public const CODE_IN_USE          = 'in_use';
    public const CODE_PLATFORM        = 'platform';

    /** Codes the merchant resolves in My Products, so the answer carries a link there. */
    private const MY_PRODUCTS_CODES = [self::CODE_NOT_FOUND, self::CODE_WRONG_PRODUCT, self::CODE_IN_USE];

    /** @var RemoteCatalog */
    private $catalog;

    public function __construct(RemoteCatalog $catalog)
    {
        $this->catalog = $catalog;
    }

    /**
     * A known error_code replaces the server's errors with one text and, where it helps, adds
     * my_products_url. Without one the server's errors stay and error_code is dropped.
     *
     * @param array<string, mixed> $result a refused activation answer
     * @return array<string, mixed>
     */
    public function apply(array $result, string $ownerModuleName): array
    {
        $code = $result['error_code'] ?? null;
        $text = is_string($code) ? $this->text($code, $ownerModuleName) : null;
        if ($text === null) {
            unset($result['error_code']);

            return $result;
        }

        $result['errors'] = [$text];
        if (in_array($code, self::MY_PRODUCTS_CODES, true)) {
            $result['my_products_url'] = self::MY_PRODUCTS_URL;
        }

        return $result;
    }

    private function text(string $code, string $ownerModuleName): ?Phrase
    {
        switch ($code) {
            case self::CODE_INVALID_REQUEST:
                return __('The license request was incomplete. Refresh the page and try again.');
            case self::CODE_NOT_FOUND:
                return __('Serial not found. Check it in My Products.');
            case self::CODE_WRONG_PRODUCT:
                $title = $this->moduleTitle($ownerModuleName);

                return $title !== ''
                    ? __('This serial is for another product. Enter your %1 serial.', $title)
                    : __('This serial is for another product. Enter the serial for this product.');
            case self::CODE_DISABLED:
                return __('This serial is disabled. Contact the license owner or MageMe support.');
            case self::CODE_IN_USE:
                return __(
                    'This serial is active on another Magento installation. Check which site in My Products: if it'
                    . ' is this site after a restore, deactivate it there and activate again; otherwise use another'
                    . ' serial.'
                );
            case self::CODE_PLATFORM:
                return __('This serial is for another Magento edition (Open Source / Adobe Commerce).');
            default:
                return null;
        }
    }

    private function moduleTitle(string $moduleName): string
    {
        $entry = $this->catalog->get($moduleName);

        return isset($entry['name']) && is_string($entry['name']) && $entry['name'] !== ''
            ? $entry['name']
            : (string)preg_replace('/^MageMe_/', '', $moduleName);
    }
}
