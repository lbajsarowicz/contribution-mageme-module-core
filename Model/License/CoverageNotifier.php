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

use MageMe\Core\Model\ModuleEcosystem\RemoteCatalog;
use Magento\AdminNotification\Model\InboxFactory;
use Magento\AdminNotification\Model\ResourceModel\Inbox as InboxResource;
use Magento\AdminNotification\Model\ResourceModel\Inbox\CollectionFactory as InboxCollectionFactory;
use Magento\Framework\DataObject;
use Magento\Framework\Notification\MessageInterface;
use Magento\Framework\Phrase;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;

/**
 * One admin inbox message per module whose license stops covering the installed version.
 *
 * The message links to the page that resolves it: the renewal of the serial, the license agreement of a free
 * upgrade, or My Products. The caller keeps the id of the message it was given and hands it back to retire it.
 */
class CoverageNotifier
{
    /** @var InboxFactory */
    private $inboxFactory;
    /** @var InboxResource */
    private $inboxResource;
    /** @var InboxCollectionFactory */
    private $inboxCollectionFactory;
    /** @var RemoteCatalog */
    private $catalog;
    /** @var TimezoneInterface */
    private $timezone;

    public function __construct(
        InboxFactory $inboxFactory,
        InboxResource $inboxResource,
        InboxCollectionFactory $inboxCollectionFactory,
        RemoteCatalog $catalog,
        TimezoneInterface $timezone
    ) {
        $this->inboxFactory           = $inboxFactory;
        $this->inboxResource          = $inboxResource;
        $this->inboxCollectionFactory = $inboxCollectionFactory;
        $this->catalog                = $catalog;
        $this->timezone               = $timezone;
    }

    /**
     * @param string $upgradeKind free | renewal | '' — how the newer product generation is obtained, when
     *                            $upgradeRequired says the installed version belongs to one
     * @return int the id of the message
     */
    public function notifyNotCovered(
        string $ownerModuleName,
        string $version,
        ?string $coveredUntil,
        bool $isDev,
        string $serial = '',
        bool $upgradeRequired = false,
        string $upgradeKind = ''
    ): int {
        $message = $this->inboxFactory->create();
        $message->setData([
            'severity'    => MessageInterface::SEVERITY_MAJOR,
            'date_added'  => gmdate('Y-m-d H:i:s'),
            'title'       => $this->getTitle($this->getModuleTitle($ownerModuleName), $version),
            'description' => (string)$this->getDescription($ownerModuleName, $version, $coveredUntil, $isDev, $upgradeRequired, $upgradeKind),
            'url'         => $this->getUrl($serial, $isDev, $upgradeRequired, $upgradeKind),
        ]);
        $this->inboxResource->save($message);

        return (int)$message->getId();
    }

    /**
     * Once the version is covered again, or a newer one takes its place, the message would only mislead; it is
     * marked read, not deleted. Messages written before Core kept their id are found by their title, or by the
     * module their link named.
     */
    public function markResolved(string $ownerModuleName, string $version, int $messageId = 0): void
    {
        $titles = array_unique([
            $this->getTitle($this->getModuleTitle($ownerModuleName), $version),
            $this->getTitle($this->getPlainTitle($ownerModuleName), $version),
        ]);
        $fields     = ['url'];
        $conditions = [['like' => '%module=' . $ownerModuleName . '&v=%']];
        foreach ($titles as $title) {
            $fields[]     = 'title';
            $conditions[] = ['eq' => $title];
        }
        if ($messageId > 0) {
            $fields[]     = 'notification_id';
            $conditions[] = ['eq' => $messageId];
        }
        $collection = $this->inboxCollectionFactory->create();
        $collection->addFieldToFilter($fields, $conditions)->addFieldToFilter('is_read', 0);
        foreach ($collection as $message) {
            if ($this->isAbout($message, $ownerModuleName, $titles, $messageId)) {
                $this->inboxResource->save($message->setData('is_read', 1));
            }
        }
    }

    /**
     * Its own id on a message that links where Core's messages do (an inbox emptied and refilled reuses ids); a link
     * of the pre-releases naming the module; or a title of this version on a message that has no link or links where
     * Core's messages do. LIKE also lets `_` stand for any character, so all is checked here.
     *
     * @param string[] $titles
     */
    private function isAbout(DataObject $message, string $ownerModuleName, array $titles, int $messageId): bool
    {
        $url = (string)$message->getData('url');
        if ($messageId > 0 && (int)$message->getData('notification_id') === $messageId && Urls::isLicenseMessageUrl($url)) {
            return true;
        }
        if (Urls::legacyModuleOf($url) === $ownerModuleName) {
            return true;
        }

        return in_array((string)$message->getData('title'), $titles, true)
            && ($url === '' || Urls::isLicenseMessageUrl($url));
    }

    private function getTitle(string $moduleTitle, string $version): string
    {
        return (string)__('%1 %2 is not covered by your MageMe license', $moduleTitle, $version);
    }

    private function getDescription(
        string $ownerModuleName,
        string $version,
        ?string $coveredUntil,
        bool $isDev,
        bool $upgradeRequired,
        string $upgradeKind
    ): Phrase {
        $title = $this->getModuleTitle($ownerModuleName);

        if ($isDev) {
            return __('The storefront is not affected. Your development license does not cover %1 %2. Activate a production license.', $title, $version);
        }

        if ($upgradeRequired) {
            switch ($upgradeKind) {
                case CoverageVerdict::UPGRADE_FREE:
                    return __(
                        'The storefront is not affected. A free upgrade to %1 %2 is included in your license. Accept its license agreement in Stores › Configuration › MageMe Extensions › %1.',
                        $title,
                        $version
                    );
                case CoverageVerdict::UPGRADE_RENEWAL:
                    return __('The storefront is not affected. %1 %2 requires a license renewal. Renew the license to cover it.', $title, $version);
                default:
                    return __('The storefront is not affected. %1 %2 needs a license upgrade. Contact MageMe support.', $title, $version);
            }
        }

        $what = $coveredUntil !== null
            ? __('Your license includes %1 updates released until %2, and %3 was released later.', $title, $this->formatDate($coveredUntil), $version)
            : __('Your license does not cover %1 %2.', $title, $version);

        return __('The storefront is not affected. %1 Renew the license to cover it.', $what);
    }

    private function getUrl(string $serial, bool $isDev, bool $upgradeRequired, string $upgradeKind): string
    {
        if (!$isDev && $upgradeRequired && $upgradeKind === CoverageVerdict::UPGRADE_FREE) {
            return Urls::AGREEMENT;
        }
        $renews = !$isDev && (!$upgradeRequired || $upgradeKind === CoverageVerdict::UPGRADE_RENEWAL);

        return $renews && $serial !== '' ? Urls::renew($serial) : Urls::MY_PRODUCTS;
    }

    private function getModuleTitle(string $moduleName): string
    {
        $entry = $this->catalog->get($moduleName);

        // A version follows the title, so a generation the catalog names ("WebForms 4") would be said twice.
        return isset($entry['name']) && is_string($entry['name']) && $entry['name'] !== ''
            ? (string)preg_replace('/\s+\d+$/', '', trim($entry['name']))
            : $this->getPlainTitle($moduleName);
    }

    private function getPlainTitle(string $moduleName): string
    {
        return (string)preg_replace('/^MageMe_/', '', $moduleName);
    }

    private function formatDate(string $date): string
    {
        try {
            // A calendar date from the licence server; shifting it into the store timezone would move the day.
            return $this->timezone->formatDateTime(
                new \DateTime($date, new \DateTimeZone('UTC')),
                \IntlDateFormatter::MEDIUM,
                \IntlDateFormatter::NONE,
                null,
                'UTC'
            );
        } catch (\Throwable $e) {
            return $date;
        }
    }
}
