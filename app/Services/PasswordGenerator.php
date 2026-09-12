<?php

namespace App\Services;

use App\Support\PasswordPolicy;
use RuntimeException;

/**
 * Builds a random password that satisfies a PasswordPolicy by construction:
 * one character drawn for each required minimum, the rest filled from the full
 * pool, then a cryptographic shuffle. Uses random_int (CSPRNG) throughout.
 */
class PasswordGenerator
{
    public function generate(?PasswordPolicy $policy = null): string
    {
        $policy ??= PasswordPolicy::current();

        $chars = [
            ...$this->draw($policy->uppercasePool(), $policy->minUppercase),
            ...$this->draw($policy->lowercasePool(), $policy->minLowercase),
            ...$this->draw($policy->digitPool(), $policy->minDigits),
            ...$this->draw($policy->symbolPool(), $policy->minSymbols),
        ];

        $fullPool = $policy->fullPool();

        if ($fullPool === '') {
            throw new RuntimeException('Password policy has no usable character pool.');
        }

        while (count($chars) < $policy->minLength) {
            $chars[] = $fullPool[random_int(0, strlen($fullPool) - 1)];
        }

        return $this->shuffle($chars);
    }

    /**
     * @return list<string>
     */
    private function draw(string $pool, int $count): array
    {
        if ($count > 0 && $pool === '') {
            throw new RuntimeException('Password policy requires characters from an empty pool.');
        }

        $out = [];
        for ($i = 0; $i < $count; $i++) {
            $out[] = $pool[random_int(0, strlen($pool) - 1)];
        }

        return $out;
    }

    /**
     * @param  list<string>  $chars
     */
    private function shuffle(array $chars): string
    {
        for ($i = count($chars) - 1; $i > 0; $i--) {
            $j = random_int(0, $i);
            [$chars[$i], $chars[$j]] = [$chars[$j], $chars[$i]];
        }

        return implode('', $chars);
    }
}
