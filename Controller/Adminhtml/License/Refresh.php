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

use MageMe\Core\Model\License\CoverageRefresher;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Exception\LocalizedException;

/**
 * POST core/license/refresh — the "Check Again" button.
 *
 * Open to every admin user: it takes no serial and changes nothing but the stored verdict, and a
 * user locked out of an editor must be able to press "Check Again".
 */
class Refresh extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Magento_Backend::admin';

    /** @var JsonFactory */
    private $jsonFactory;
    /** @var CoverageRefresher */
    private $refresher;

    public function __construct(
        Context $context,
        JsonFactory $jsonFactory,
        CoverageRefresher $refresher
    ) {
        parent::__construct($context);
        $this->jsonFactory = $jsonFactory;
        $this->refresher   = $refresher;
    }

    public function execute()
    {
        // The licence server may take seconds; holding the session lock that long would stall the
        // page the user opens next.
        $this->_session->writeClose();

        $result = $this->jsonFactory->create();
        try {
            return $result->setData($this->refresher->refreshNow(
                (string)$this->getRequest()->getParam('license_section', '')
            ));
        } catch (LocalizedException $e) {
            return $result->setHttpResponseCode(400)->setData(['success' => false, 'errors' => [$e->getMessage()]]);
        }
    }
}
