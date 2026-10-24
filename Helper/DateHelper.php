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

use IntlDateFormatter;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use Magento\Store\Model\ScopeInterface;

class DateHelper
{
    public const DB_DATE_FORMAT = 'Y-m-d';
    public const DB_DATETIME_FORMAT = 'Y-m-d H:i:s';
    /**
     * Laminas Date To local date according Map array
     */
    protected const LAMINAS_TO_STRFTIME_DATE = [
        'yyyy-MM-ddTHH:mm:ssZZZZ' => '%c',
        'EEEE' => '%A',
        'EEE' => '%a',
        'D' => '%j',
        'MMMM' => '%B',
        'MMM' => '%b',
        'MM' => '%m',
        'M' => '%m',
        'dd' => '%d',
        'd' => '%e',
        'yyyy' => '%Y',
        'yy' => '%Y',
        'y' => '%Y'
    ];
    /**
     * Laminas Date To local time according Map array
     */
    protected const LAMINAS_TO_STRFTIME_TIME = [
        'a' => '%p',
        'hh' => '%I',
        'h' => '%I',
        'HH' => '%H',
        'H' => '%H',
        'mm' => '%M',
        'ss' => '%S',
        'z' => '%Z',
        'v' => '%Z'
    ];
    /**
     * ICU pattern letters as date() codes; a run of letters missing here is printed as text
     */
    private const ICU_TO_DATE = [
        'yyyy' => 'Y', 'yyy' => 'Y', 'yy' => 'y', 'y' => 'Y',
        'MMMM' => 'F', 'MMM' => 'M', 'MM' => 'm', 'M' => 'n',
        'dd' => 'd', 'd' => 'j',
        'EEEE' => 'l', 'EEE' => 'D', 'EE' => 'D', 'E' => 'D',
        'HH' => 'H', 'H' => 'G', 'hh' => 'h', 'h' => 'g',
        'mm' => 'i', 'm' => 'i', 'ss' => 's', 's' => 's',
        'a' => 'A', 'z' => 'T', 'zzzz' => 'e', 'v' => 'T', 'Z' => 'O',
    ];
    protected ScopeConfigInterface $scopeConfig;

    protected DateTime $dateTime;

    protected TimezoneInterface $timezone;

    /**
     * DateHelper constructor.
     *
     * @param TimezoneInterface $timezone
     * @param DateTime $dateTime
     * @param ScopeConfigInterface $scopeConfig
     */
    public function __construct(
        TimezoneInterface    $timezone,
        DateTime             $dateTime,
        ScopeConfigInterface $scopeConfig
    ) {
        $this->dateTime    = $dateTime;
        $this->timezone    = $timezone;
        $this->scopeConfig = $scopeConfig;
    }

    /**
     * Get date format
     *
     * @param int $format
     * @return string
     */
    public function getDateFormat(int $format = IntlDateFormatter::SHORT): string
    {
        return $this->timezone->getDateFormat($format);
    }

    /**
     * Get time format
     *
     * @param int $format
     * @return string
     */
    public function getTimeFormat(int $format = IntlDateFormatter::SHORT): string
    {
        return $this->timezone->getTimeFormat($format);
    }

    /**
     * Format a date with a Laminas (ICU) pattern, e.g. 'dd.MM.yyyy'.
     *
     * @param string $value
     * @param string $format
     * @return string
     */
    public function formatDateStrf(string $value, string $format): string
    {
        $time = strlen($value) > 0 ? strtotime($value) : false;
        if ($time === false) {
            return '';
        }
        $text = static fn(string $literal): string => implode('', array_map(
            static fn(string $char): string => '\\' . $char,
            preg_split('//u', $literal, -1, PREG_SPLIT_NO_EMPTY) ?: []
        ));
        $format = (string)preg_replace_callback(
            "/'((?:[^']|'')*)'|([A-Za-z])\\2*|[^A-Za-z']+/",
            static function (array $m) use ($time, $text): string {
                if (isset($m[2]) && $m[2] !== '') {
                    if ($m[2] === 'D') {
                        $day = (int)date('z', $time) + 1;
                        return $text(strlen($m[0]) >= 3 ? sprintf('%03d', $day) : (string)$day);
                    }
                    return self::ICU_TO_DATE[$m[0]] ?? $text($m[0]);
                }
                if ($m[0] === "''") {
                    return "\\'";
                }
                return $text(isset($m[1]) && $m[0][0] === "'" ? str_replace("''", "'", $m[1]) : $m[0]);
            },
            $format
        );

        return date($format, $time);
    }

    /**
     * Convert Laminas Date format to local time/date according format
     *
     * @param string $value
     * @param boolean $convertDate
     * @param boolean $convertTime
     * @return string
     */
    public function convertLaminasToStrftime(string $value, bool $convertDate = true, bool $convertTime = true): string
    {
        if ($convertTime) {
            $value = $this->convert($value, self::LAMINAS_TO_STRFTIME_TIME);
        }
        if ($convertDate) {
            $value = $this->convert($value, self::LAMINAS_TO_STRFTIME_DATE);
        }
        return $value;
    }

    /**
     * Convert value by dictionary
     *
     * @param string $value
     * @param array $dictionary
     * @return string
     */
    protected function convert(string $value, array $dictionary): string
    {
        foreach ($dictionary as $search => $replace) {
            $value = preg_replace('/(^|[^%])' . $search . '/', '$1' . $replace, $value);
        }
        return $value;
    }

    /**
     * Get formatted current date
     *
     * @return false|string
     */
    public function currentDate()
    {
        return $this->dateTime->gmtDate();
    }

    /**
     * Get timezone configuration
     *
     * @param string|null $scopeType
     * @param string|null $scopeCode
     * @return string
     */
    public function getTimezone(?string $scopeType = null, ?string $scopeCode = null)
    {
        return $this->timezone->getConfigTimezone($scopeType, $scopeCode);
    }

    /**
     * Format date according to store locale
     *
     * @param string $date
     * @param int|string|null $storeId
     * @return string
     */
    public function formatDate(string $date, $storeId): string
    {
        $localeCode = $this->scopeConfig->getValue(
            'general/locale/code',
            ScopeInterface::SCOPE_STORE,
            $storeId
        ) ?: null;
        return $this->timezone->formatDateTime(
            $date,
            null,
            null,
            $localeCode
        );
    }
}
