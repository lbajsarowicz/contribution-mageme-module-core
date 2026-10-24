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

namespace MageMe\Core\Controller\Adminhtml\License;

use Magento\Framework\App\Action\HttpGetActionInterface;

/**
 * GET core/license/status — the stored licence state for the ecosystem panel. Never calls the
 * licence server: the background refresh does that on the licence's own schedule.
 */
class Status extends AbstractAction implements HttpGetActionInterface
{
    public const ADMIN_RESOURCE = 'MageMe_Core::license_view';

    public function execute()
    {
        $resultJson = $this->jsonFactory->create();

        list($licenseSection, $ownerModuleName) = $this->resolveLicenseTarget();
        if ($licenseSection === null || $ownerModuleName === null) {
            return $resultJson->setHttpResponseCode(400)->setData([
                'success' => false,
                'errors'  => [(string)__('license_section or module_name required')],
            ]);
        }

        return $resultJson->setData($this->genericLicenseHelper->getStatus($licenseSection)->toArray());
    }
}
