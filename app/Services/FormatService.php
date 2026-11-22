<?php

declare(strict_types=1);

namespace App\Services;

use DateTime;
use Illuminate\Support\Number;
use Xgp\App\Core\Enumerators\ImportanceEnumerator as Importance;

/**
 * @SuppressWarnings("PHPMD.TooManyPublicMethods")
 * @SuppressWarnings("PHPMD.BooleanArgumentFlag")
 * @SuppressWarnings("PHPMD.ShortVariable")
 * @SuppressWarnings("PHPMD.ElseExpression")
 * @SuppressWarnings("PHPMD.StaticAccess")
 */
class FormatService
{
    public function prettyTime(float $inputSeconds): string
    {
        $secMin = 60;
        $secHour = 60 * $secMin;
        $secDay = 24 * $secHour;
        $secWeek = 7 * $secDay;

        $weeks = floor($inputSeconds / $secWeek);
        $daysSeconds = (int) $inputSeconds % $secWeek;
        $days = floor($daysSeconds / $secDay);
        $hourSeconds = (int) $inputSeconds % $secDay;
        $hours = floor($hourSeconds / $secHour);
        $minuteSeconds = (int) $hourSeconds % $secHour;
        $minutes = floor($minuteSeconds / $secMin);
        $remainingSeconds = (int) $minuteSeconds % $secMin;
        $seconds = ceil($remainingSeconds);

        $timeParts = [];
        $sections = [
            'w' => (int) $weeks,
            'd' => (int) $days,
            'h' => (int) $hours,
            'm' => (int) $minutes,
            's' => (int) $seconds,
        ];

        foreach ($sections as $name => $value) {
            if ($value > 0) {
                $timeParts[] = $value . $name;
            }
        }

        return implode(' ', $timeParts);
    }

    public function prettyTimeHour(int $seconds): string
    {
        $min = floor(intval($seconds / 60) % 60);

        return $min != 0 ? $min . 'min ' : '';
    }

    public function prettyTimeAgo(string $datetime, bool $full = false): string
    {
        $now = new DateTime();
        $ago = new DateTime($datetime);
        $diff = $now->diff($ago);
        $weeks = floor($diff->d / 7);
        $diff->d -= (int) ($weeks * 7);

        $string = [
            'y' => 'year',
            'm' => 'month',
            'w' => 'week',
            'd' => 'day',
            'h' => 'hour',
            'i' => 'minute',
            's' => 'second',
        ];

        foreach ($string as $k => &$v) {
            if ($k !== 'w') {
                if ($diff->$k) {
                    $v = $diff->$k . ' ' . $v . ($diff->$k > 1 ? 's' : '');
                } else {
                    unset($string[$k]);
                }
            } else {
                if (!empty($weeks)) {
                    $v = $weeks . ' ' . $v . ($weeks > 1 ? 's' : '');
                } else {
                    unset($string[$k]);
                }
            }
        }

        if (!$full) {
            $string = array_slice($string, 0, 1);
        }

        return $string ? implode(', ', $string) : '';
    }

    public function prettyNumber(float | int $n, bool $floor = true): string
    {
        if (empty($n)) {
            $n = 0.0;
        }

        if ($floor) {
            $n = floor($n);
        }

        return number_format($n, 0, ',', '.');
    }

    /**
     * Big amounts in words for messages: below a million the full number (845.120), then one
     * decimal and a word: 11.8 million, 2.3 billion, 4 trillion (Dale, 22 Nov 2026).
     */
    public function readableNumber(float | int $number): string
    {
        $abs = abs((float) $number);

        if ($abs < 1e6) {
            return $this->prettyNumber($number);
        }

        $units = [
            [1e6, 'game/global.number_million'],
            [1e9, 'game/global.number_billion'],
            [1e12, 'game/global.number_trillion'],
        ];

        $index = 0;

        foreach ($units as $i => [$size]) {
            if ($abs >= $size) {
                $index = $i;
            }
        }

        $value = round($abs / $units[$index][0], 1);

        // 999.96 million rounds to 1000: say 1 billion instead
        if ($value >= 1000 && isset($units[$index + 1])) {
            $index++;
            $value = round($abs / $units[$index][0], 1);
        }

        $text = rtrim(rtrim(number_format($value, 1, '.', ''), '0'), '.');

        return sprintf(__($units[$index][1]), ($number < 0 ? '-' : '') . $text);
    }

    public function shortlyNumber(float | int $number): string
    {
        return match (true) {
            $number >= 1e24 => $this->prettyNumber($number / 1e18) . ' T+',
            $number >= 1e18 => $this->prettyNumber($number / 1e18) . ' T',
            $number >= 1e12 => $this->prettyNumber($number / 1e12) . ' B',
            $number >= 1e6 => $this->prettyNumber($number / 1e6) . ' M',
            $number >= 1e4 => $this->prettyNumber($number / 1e3) . ' K',
            default => $this->prettyNumber($number),
        };
    }

    public function floatToString(float | int $numeric, int $precision = 0, bool $dotSeparator = false): string
    {
        $formatted = sprintf('%.' . $precision . 'f', $numeric);

        return $dotSeparator ? str_replace(',', '.', $formatted) : $formatted;
    }

    public function roundUp(float | int $value, int $precision = 0): float
    {
        $precisionFactor = $precision == 0 ? 1 : pow(10, $precision);

        return ceil($value * $precisionFactor) / $precisionFactor;
    }

    public function formatCoords(int $galaxy, int $system, int $planet): string
    {
        return sprintf('[%d:%d:%d]', $galaxy, $system, $planet);
    }

    /**
     * @SuppressWarnings("PHPMD.StaticAccess")
     */
    public function prettyBytes(int | float $bytes, int $precision = 2): string
    {
        return Number::fileSize($bytes, $precision);
    }

    public function colorNumber(float | int $number, string $string = ''): string
    {
        $display = $string !== '' ? $string : (string) $number;

        if ($number >= 0) {
            return $this->colorGreen($display);
        }

        if ($number < 0) {
            return $this->colorRed($display);
        }

        return $display;
    }

    public function colorRed(string $string): string
    {
        return '<font color="#ff0000">' . $string . '</font>';
    }

    public function colorGreen(string $string): string
    {
        return '<font color="#00ff00">' . $string . '</font>';
    }

    public function customColor(string $string, string $color): string
    {
        return '<font color="' . $color . '">' . $string . '</font>';
    }

    public function strongText(string $value): string
    {
        return '<strong>' . $value . '</strong>';
    }

    public function link(string $href, string $text, string $title = '', string $attributes = ''): string
    {
        $titleAttr = $title !== '' ? ' title="' . $title . '"' : '';
        $attrsStr = $attributes !== '' ? ' ' . $attributes : '';

        return '<a href="' . $href . '"' . $titleAttr . $attrsStr . '>' . $text . '</a>';
    }

    public function prettyCoords(int $galaxy, int $system, int $planet): string
    {
        return $this->link(
            'game.php?page=galaxy&mode=3&galaxy=' . $galaxy . '&system=' . $system,
            $this->formatCoords($galaxy, $system, $planet)
        );
    }

    public function spanClassElement(string $content, ?string $class = ''): string
    {
        return '<span class="' . $class . '">' . $content . '</span>';
    }

    public function spanStyleElement(string $content, ?string $style = ''): string
    {
        return '<span style="' . $style . '">' . $content . '</span>';
    }

    public function getImportanceColor(int $priority): string
    {
        return match ($priority) {
            Importance::unimportant => 'lime',
            Importance::normal => 'yellow',
            Importance::important => 'red',
            default => 'lime',
        };
    }

    public function formatLevel(string $object, int $level): string
    {
        return $object . ' (' . __('game/global.level') . $level . ')';
    }
}
