<?php

declare(strict_types=1);

/**
 * Helper for cron expression validation, matching, and next run calculations.
 * Used by Scheduled Tasks (Roadmap 2.1).
 */
class CronScheduleHelper
{
    public const PRESETS = [
        'every_5_minutes'  => '*/5 * * * *',
        'every_15_minutes' => '*/15 * * * *',
        'every_30_minutes' => '*/30 * * * *',
        'hourly'           => '0 * * * *',
        'nightly_0300'     => '0 3 * * *',
        'nightly_0400'     => '0 4 * * *',
        'weekly_sunday'    => '0 3 * * 0',
        'monthly_first'    => '0 3 1 * *',
    ];

    /**
     * Validates a 5-part cron expression.
     */
    public static function isValid(string $expression): bool
    {
        $parts = preg_split('/\s+/', trim($expression));
        if ($parts === false || count($parts) !== 5) {
            return false;
        }

        [$minute, $hour, $dayOfMonth, $month, $dayOfWeek] = $parts;

        return self::validateField($minute, 0, 59)
            && self::validateField($hour, 0, 23)
            && self::validateField($dayOfMonth, 1, 31)
            && self::validateField($month, 1, 12)
            && self::validateField($dayOfWeek, 0, 7);
    }

    /**
     * Checks if the expression is due to run at the given minute.
     */
    public static function isDue(string $expression, ?DateTimeInterface $time = null): bool
    {
        if (!self::isValid($expression)) {
            return false;
        }

        $time = $time ?? new DateTimeImmutable();
        $parts = preg_split('/\s+/', trim($expression));
        if ($parts === false || count($parts) !== 5) {
            return false;
        }

        $min  = (int)$time->format('i');
        $hour = (int)$time->format('G');
        $dom  = (int)$time->format('j');
        $mon  = (int)$time->format('n');
        $dow  = (int)$time->format('w'); // 0 = Sunday

        return self::matchField($parts[0], $min, 0, 59)
            && self::matchField($parts[1], $hour, 0, 23)
            && self::matchField($parts[2], $dom, 1, 31)
            && self::matchField($parts[3], $mon, 1, 12)
            && self::matchDow($parts[4], $dow);
    }

    /**
     * Computes the next N run dates starting after the given time.
     *
     * @return string[] ISO-8601 formatted datetime strings (Y-m-d H:i:s)
     */
    public static function getNextRuns(string $expression, int $count = 5, ?DateTimeInterface $from = null): array
    {
        if (!self::isValid($expression) || $count <= 0) {
            return [];
        }

        $current = $from ? DateTimeImmutable::createFromInterface($from) : new DateTimeImmutable();
        // Zero out seconds
        $current = $current->setTime((int)$current->format('H'), (int)$current->format('i'), 0);

        $runs = [];
        $maxMinutesScan = 525600; // 1 year limit to avoid infinite loops on invalid logic
        $minutesScanned = 0;

        while (count($runs) < $count && $minutesScanned < $maxMinutesScan) {
            $current = $current->modify('+1 minute');
            $minutesScanned++;

            if (self::isDue($expression, $current)) {
                $runs[] = $current->format('Y-m-d H:i:00');
            }
        }

        return $runs;
    }

    private static function validateField(string $field, int $min, int $max): bool
    {
        if ($field === '*') {
            return true;
        }

        // Sub-elements separated by comma
        $terms = explode(',', $field);
        foreach ($terms as $term) {
            if ($term === '') {
                return false;
            }

            // Step syntax: */5 or 1-10/2
            if (str_contains($term, '/')) {
                $parts = explode('/', $term);
                if (count($parts) !== 2 || !ctype_digit($parts[1]) || (int)$parts[1] <= 0) {
                    return false;
                }
                $range = $parts[0];
                if ($range !== '*' && !self::validateRangeOrNum($range, $min, $max)) {
                    return false;
                }
            } else {
                if (!self::validateRangeOrNum($term, $min, $max)) {
                    return false;
                }
            }
        }

        return true;
    }

    private static function validateRangeOrNum(string $expr, int $min, int $max): bool
    {
        if (str_contains($expr, '-')) {
            $parts = explode('-', $expr);
            if (count($parts) !== 2 || !ctype_digit($parts[0]) || !ctype_digit($parts[1])) {
                return false;
            }
            $a = (int)$parts[0];
            $b = (int)$parts[1];
            return $a >= $min && $b <= $max && $a <= $b;
        }

        if (!ctype_digit($expr)) {
            return false;
        }

        $val = (int)$expr;
        return $val >= $min && $val <= $max;
    }

    private static function matchField(string $field, int $value, int $min, int $max): bool
    {
        if ($field === '*') {
            return true;
        }

        $terms = explode(',', $field);
        foreach ($terms as $term) {
            if (str_contains($term, '/')) {
                [$range, $step] = explode('/', $term, 2);
                $step = (int)$step;
                if ($step <= 0) {
                    continue;
                }

                $rangeStart = $min;
                $rangeEnd = $max;
                if ($range !== '*') {
                    if (str_contains($range, '-')) {
                        [$a, $b] = explode('-', $range, 2);
                        $rangeStart = (int)$a;
                        $rangeEnd = (int)$b;
                    } else {
                        $rangeStart = (int)$range;
                    }
                }

                if ($value >= $rangeStart && $value <= $rangeEnd) {
                    if (($value - $rangeStart) % $step === 0) {
                        return true;
                    }
                }
            } elseif (str_contains($term, '-')) {
                [$a, $b] = explode('-', $term, 2);
                $a = (int)$a;
                $b = (int)$b;
                if ($value >= $a && $value <= $b) {
                    return true;
                }
            } else {
                if ((int)$term === $value) {
                    return true;
                }
            }
        }

        return false;
    }

    private static function matchDow(string $field, int $dow): bool
    {
        // In cron, 0 and 7 are both Sunday. If dow is 0, also check 7.
        if (self::matchField($field, $dow, 0, 7)) {
            return true;
        }
        if ($dow === 0 && self::matchField($field, 7, 0, 7)) {
            return true;
        }
        return false;
    }
}
