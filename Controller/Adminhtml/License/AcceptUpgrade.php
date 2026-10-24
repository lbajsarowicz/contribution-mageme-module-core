<?php
/**
 * Copyright © MageMe. All rights reserved.
 * See LICENSE for license terms, or https://mageme.com/license.
 */

declare(strict_types=1);

namespace MageMe\Core\Controller\Adminhtml\License;

use MageMe\Core\Api\LicenseCoverageInterface;
use MageMe\Core\Helper\GenericLicenseHelper;
use MageMe\Core\Model\ModuleEcosystem\LicenseMetaResolver;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Phrase;

/**
 * POST core/license/acceptUpgrade — accepts the license agreement of a free product-generation
 * upgrade for the section's serial. Answers {success, errors, coverage} and nothing else.
 */
class AcceptUpgrade extends AbstractAction implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'MageMe_Core::license_accept_upgrade';

    /** The assent text is stored by the server as evidence of what the admin agreed to. */
    public const ASSENT_MAX_LENGTH = 1000;

    /** @var LicenseCoverageInterface */
    private $coverage;

    public function __construct(
        Context                  $context,
        JsonFactory              $jsonFactory,
        GenericLicenseHelper     $genericLicenseHelper,
        LicenseMetaResolver      $licenseMetaResolver,
        LicenseCoverageInterface $coverage
    ) {
        parent::__construct($context, $jsonFactory, $genericLicenseHelper, $licenseMetaResolver);
        $this->coverage = $coverage;
    }

    /**
     * @inheritdoc
     */
    public function execute()
    {
        $resultJson = $this->jsonFactory->create();

        list($licenseSection, $ownerModuleName) = $this->resolveLicenseTarget();
        if ($licenseSection === null || $ownerModuleName === null) {
            return $resultJson->setHttpResponseCode(400)->setData([
                'success'  => false,
                'errors'   => [(string)__('This module has no license of its own.')],
                'coverage' => null,
            ]);
        }

        $assentText = trim((string)$this->getRequest()->getParam('assent_text', ''));
        if ($assentText === '' || mb_strlen($assentText) > self::ASSENT_MAX_LENGTH) {
            return $resultJson->setHttpResponseCode(400)->setData([
                'success'  => false,
                'errors'   => [(string)__('Check the box to accept the license agreement.')],
                'coverage' => $this->coverage->getCoverage($licenseSection, $ownerModuleName)->toArray(),
            ]);
        }

        $user       = $this->_auth->getUser();
        $adminEmail = $user !== null ? (string)$user->getEmail() : '';
        // The license server may take seconds; holding the session lock that long would stall the
        // page the user opens next.
        $this->_session->writeClose();

        $result = $this->genericLicenseHelper->acceptUpgrade($licenseSection, $ownerModuleName, $adminEmail, $assentText);

        return $resultJson->setData([
            'success'  => !empty($result['success']),
            'errors'   => $this->texts($result['errors'] ?? []),
            'coverage' => $this->coverage->getCoverage($licenseSection, $ownerModuleName)->toArray(),
        ]);
    }

    /**
     * @param mixed $errors
     * @return string[]
     */
    private function texts($errors): array
    {
        $texts = [];
        foreach (is_array($errors) ? $errors : [] as $error) {
            if (is_scalar($error) || $error instanceof Phrase) {
                $texts[] = (string)$error;
            }
        }

        return $texts;
    }
}
