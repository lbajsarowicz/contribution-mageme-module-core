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

/**
 * When the next automatic licence check is due, decided at the moment a check ends.
 *
 * Each installation checks at its own minute of the hour, derived from a key of the installation (its base
 * URL), so the stores that are due in the same hour do not reach the licence server in the same minute. The
 * cron looks every few minutes; the slot moves a check to the nearest such minute, at most half an hour either
 * way, so the intervals keep their length on average.
 */
class RefreshPolicy
{
    public const CODE_OK           = 'ok';
    public const CODE_NOT_COVERED  = 'version_not_covered';
    public const CODE_UNKNOWN      = 'version_unknown';

    public const HOUR = 3600;
    public const DAY  = 86400;

    /** A renewal has to reach a locked editor quickly. */
    public const NOT_COVERED_INTERVAL = self::HOUR;
    public const DEFAULT_INTERVAL     = self::DAY;

    /** Delay after the n-th consecutive failure; later failures wait a day. */
    public const BACKOFF = [1 => self::HOUR, 2 => 2 * self::HOUR, 3 => 4 * self::HOUR, 4 => 8 * self::HOUR];

    public const CIRCUIT_AFTER_FAILURES = 3;
    public const CIRCUIT_DELAY          = 6 * self::HOUR;

    public const JITTER = 0.1;

    /**
     * @param string $installation key of this installation; empty for no slot
     */
    public function nextAfterAnswer(string $code, int $now, string $installation = ''): int
    {
        $interval = $code === self::CODE_NOT_COVERED ? self::NOT_COVERED_INTERVAL : self::DEFAULT_INTERVAL;

        return $this->toSlot($now + $this->jitter($interval), $installation);
    }

    /**
     * @param int $failures consecutive failures, this one included
     * @param string $installation key of this installation; empty for no slot
     */
    public function nextAfterFailure(int $failures, int $now, string $installation = ''): int
    {
        $delay = self::BACKOFF[$failures] ?? self::DAY;
        if ($failures >= self::CIRCUIT_AFTER_FAILURES) {
            $delay = max($delay, self::CIRCUIT_DELAY);
        }

        return $this->toSlot($now + $this->jitter($delay), $installation);
    }

    /**
     * Seconds past the hour at which this installation checks.
     */
    public function slotOf(string $installation): int
    {
        // crc32() is negative for half of the inputs on a 32-bit build
        $slot = crc32($installation) % self::HOUR;

        return $slot < 0 ? $slot + self::HOUR : $slot;
    }

    /**
     * The moment nearest to $time that falls on the installation's slot.
     */
    protected function toSlot(int $time, string $installation): int
    {
        if ($installation === '') {
            return $time;
        }
        $at = $time - $time % self::HOUR + $this->slotOf($installation);
        if ($at - $time > self::HOUR / 2) {
            return $at - self::HOUR;
        }

        return $time - $at > self::HOUR / 2 ? $at + self::HOUR : $at;
    }

    /**
     * Spreads the stores that failed together over the next window instead of sending them back at once.
     */
    protected function jitter(int $seconds): int
    {
        $spread = (int)round($seconds * self::JITTER);

        return $seconds + random_int(-$spread, $spread);
    }
}
