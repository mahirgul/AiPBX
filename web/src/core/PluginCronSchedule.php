<?php
/**
 * A five-field cron expression, parsed once and matched against a minute.
 *
 * The plugin cron hook needs to answer one question per job per minute: is this
 * job due now? Getting that wrong is visible — a job that never runs, or one
 * that runs sixty times an hour — so the parsing rules are narrow on purpose:
 *
 *   *            every value
 *   * /n         every n-th value (no space in a real expression)
 *   a            one value
 *   a-b          an inclusive range
 *   a-b/n        every n-th value in a range
 *   a,b,c        a list of any of the above
 *
 * Not supported, deliberately: `@daily` aliases, month/day names, and seconds.
 * A manifest is not a shell crontab; it gets the smallest language that covers
 * "every five minutes" and "at 03:15 on the first", and anything else is a
 * manifest error with a message rather than a silently ignored job.
 *
 * Day-of-month and day-of-week follow Vixie cron's odd rule: when BOTH are
 * restricted the day matches if EITHER matches. `0 0 13 * 5` is "the 13th or
 * any Friday", not "Friday the 13th". A loader that gets this wrong runs jobs
 * on the wrong days, so it is tested explicitly.
 */

final class PluginCronSchedule
{
    private const BOUNDS = [
        'minute' => [0, 59],
        'hour' => [0, 23],
        'dom' => [1, 31],
        'month' => [1, 12],
        'dow' => [0, 7],
    ];

    /** @var array<string, array<int, int>> */
    private array $sets = [];

    private bool $domRestricted = false;
    private bool $dowRestricted = false;

    public function __construct(private string $expression)
    {
        $problems = self::validate($expression);
        if ($problems) {
            throw new InvalidArgumentException(implode('; ', $problems));
        }

        $fields = preg_split('/\s+/', trim($expression)) ?: [];
        $sets = [];
        $names = array_keys(self::BOUNDS);
        foreach ($names as $index => $name) {
            [$low, $high] = self::BOUNDS[$name];
            $sets[$name] = self::expand($fields[$index], $low, $high, $name);
        }
        // `7` is Sunday in crontab notation and `0` is what date('w') returns.
        // Normalise once, here, so matching has a single representation.
        $sets['dow'] = array_values(array_unique(array_map(
            static fn(int $day): int => $day === 7 ? 0 : $day,
            $sets['dow']
        )));
        sort($sets['dow']);

        $this->sets = $sets;
        $this->domRestricted = trim($fields[2]) !== '*';
        $this->dowRestricted = trim($fields[4]) !== '*';
    }

    public function expression(): string
    {
        return $this->expression;
    }

    /** @return array<int, string> problems; empty means valid */
    public static function validate(string $expression): array
    {
        $fields = preg_split('/\s+/', trim($expression));
        if ($fields === false || count($fields) !== 5) {
            return ['a schedule is exactly five fields (minute hour day month weekday)'];
        }
        $problems = [];
        foreach (array_keys(self::BOUNDS) as $index => $name) {
            [$low, $high] = self::BOUNDS[$name];
            try {
                self::expand($fields[$index], $low, $high, $name);
            } catch (InvalidArgumentException $e) {
                $problems[] = $e->getMessage();
            }
        }
        return $problems;
    }

    public function matches(DateTimeImmutable $at): bool
    {
        $minute = (int) $at->format('i');
        $hour = (int) $at->format('G');
        $dom = (int) $at->format('j');
        $month = (int) $at->format('n');
        $dow = (int) $at->format('w');

        if (!in_array($minute, $this->sets['minute'], true)
                || !in_array($hour, $this->sets['hour'], true)
                || !in_array($month, $this->sets['month'], true)) {
            return false;
        }

        $dayOfMonth = in_array($dom, $this->sets['dom'], true);
        $dayOfWeek = in_array($dow, $this->sets['dow'], true);
        if ($this->domRestricted && $this->dowRestricted) {
            return $dayOfMonth || $dayOfWeek;
        }
        return $dayOfMonth && $dayOfWeek;
    }

    /**
     * One field -> the values it selects.
     *
     * @return array<int, int>
     */
    private static function expand(string $field, int $low, int $high, string $name): array
    {
        $field = trim($field);
        if ($field === '') {
            throw new InvalidArgumentException(sprintf('%s is empty', $name));
        }
        $values = [];
        foreach (explode(',', $field) as $item) {
            $item = trim($item);
            if ($item === '') {
                throw new InvalidArgumentException(sprintf('%s has an empty list item', $name));
            }
            $step = 1;
            $range = $item;
            $slash = strpos($item, '/');
            if ($slash !== false) {
                $range = substr($item, 0, $slash);
                $stepText = substr($item, $slash + 1);
                if (!ctype_digit($stepText) || (int) $stepText < 1) {
                    throw new InvalidArgumentException(sprintf(
                        '%s: the step after the slash must be a positive whole number in "%s"',
                        $name, $item));
                }
                $step = (int) $stepText;
            }

            if ($range === '*') {
                $from = $low;
                $to = $high;
            } elseif (strpos($range, '-') !== false) {
                [$fromText, $toText] = explode('-', $range, 2);
                if (!ctype_digit($fromText) || !ctype_digit($toText)) {
                    throw new InvalidArgumentException(sprintf(
                        '%s: range "%s" is not a-b with whole numbers', $name, $item));
                }
                $from = (int) $fromText;
                $to = (int) $toText;
                if ($from > $to) {
                    throw new InvalidArgumentException(sprintf(
                        '%s: range "%s" ends before it starts', $name, $item));
                }
            } else {
                if (!ctype_digit($range)) {
                    throw new InvalidArgumentException(sprintf(
                        '%s: "%s" is not a number, *, a-b or a,b', $name, $item));
                }
                $from = (int) $range;
                $to = $slash === false ? $from : $high;
            }

            if ($from < $low || $to > $high) {
                throw new InvalidArgumentException(sprintf(
                    '%s: "%s" is outside %d-%d', $name, $item, $low, $high));
            }
            for ($value = $from; $value <= $to; $value += $step) {
                $values[$value] = $value;
            }
        }
        ksort($values);
        return array_values($values);
    }
}
