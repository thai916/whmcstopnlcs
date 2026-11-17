<?php

namespace App\Services\WhmcsImport;

use InvalidArgumentException;

/** Arbitrary-length signed decimal addition, without floats or optional extensions. */
final class ExactAmount
{
    public static function add(string $a, string $b, int $scale = 2): string
    {
        [$an, $av] = self::parts($a, $scale);
        [$bn, $bv] = self::parts($b, $scale);
        if ($an === $bn) {
            $digits = self::sum($av, $bv);
            $negative = $an;
        } else {
            $comparison = strlen($av) <=> strlen($bv) ?: strcmp($av, $bv);
            $digits = $comparison >= 0 ? self::subtract($av, $bv) : self::subtract($bv, $av);
            $negative = $comparison >= 0 ? $an : $bn;
        }
        $digits = str_pad($digits, $scale + 1, '0', STR_PAD_LEFT);
        $value = $scale === 0 ? $digits : substr($digits, 0, -$scale).'.'.substr($digits, -$scale);

        return ($negative && trim($digits, '0') !== '' ? '-' : '').$value;
    }

    public static function normalize(string $value, int $scale = 2): string
    {
        return self::add($value, '0', $scale);
    }

    private static function parts(string $value, int $scale): array
    {
        if ($scale < 0 || $scale > 12 || ! preg_match('/^(-?)(\d+)(?:\.(\d+))?$/D', $value, $m)) {
            throw new InvalidArgumentException('Invalid exact decimal.');
        }
        $fraction = $m[3] ?? '';
        if (strlen($fraction) > $scale) {
            throw new InvalidArgumentException('Decimal would require rounding.');
        }

        return [$m[1] === '-', ltrim($m[2].str_pad($fraction, $scale, '0'), '0') ?: '0'];
    }

    private static function sum(string $a, string $b): string
    {
        $out = '';
        $carry = 0;
        $ar = strrev($a);
        $br = strrev($b);
        for ($i = 0; $i < max(strlen($a), strlen($b)); $i++) {
            $n = (int) ($ar[$i] ?? 0) + (int) ($br[$i] ?? 0) + $carry;
            $out = ($n % 10).$out;
            $carry = intdiv($n, 10);
        }

        return ($carry ? '1' : '').$out;
    }

    private static function subtract(string $a, string $b): string
    {
        $out = '';
        $borrow = 0;
        $ar = strrev($a);
        $br = strrev($b);
        for ($i = 0; $i < strlen($a); $i++) {
            $n = (int) $ar[$i] - (int) ($br[$i] ?? 0) - $borrow;
            $borrow = $n < 0 ? 1 : 0;
            $out = ($n < 0 ? $n + 10 : $n).$out;
        }

        return ltrim($out, '0') ?: '0';
    }
}
