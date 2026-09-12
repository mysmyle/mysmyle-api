<?php

namespace App\Support;

/**
 * The active password policy: the landlord "password_policy" setting merged
 * over config/password_policy.php. Owns the character pools (so generated and
 * validated passwords agree) and turns a candidate password into a list of
 * human-readable requirement failures.
 */
class PasswordPolicy
{
    private const AMBIGUOUS = ['0', 'O', '1', 'l', 'I'];

    public function __construct(
        public readonly int $minLength,
        public readonly int $minUppercase,
        public readonly int $minLowercase,
        public readonly int $minDigits,
        public readonly int $minSymbols,
        public readonly string $symbols,
        public readonly bool $excludeAmbiguous,
    ) {}

    public static function current(): self
    {
        return self::fromArray(
            app(SettingsRepository::class)->get('password_policy', config('password_policy')),
        );
    }

    public static function fromArray(array $data): self
    {
        return new self(
            minLength: (int) $data['min_length'],
            minUppercase: (int) $data['min_uppercase'],
            minLowercase: (int) $data['min_lowercase'],
            minDigits: (int) $data['min_digits'],
            minSymbols: (int) $data['min_symbols'],
            symbols: (string) $data['symbols'],
            excludeAmbiguous: (bool) $data['exclude_ambiguous'],
        );
    }

    public function toArray(): array
    {
        return [
            'min_length' => $this->minLength,
            'min_uppercase' => $this->minUppercase,
            'min_lowercase' => $this->minLowercase,
            'min_digits' => $this->minDigits,
            'min_symbols' => $this->minSymbols,
            'symbols' => $this->symbols,
            'exclude_ambiguous' => $this->excludeAmbiguous,
        ];
    }

    public function uppercasePool(): string
    {
        return $this->pool('ABCDEFGHIJKLMNOPQRSTUVWXYZ');
    }

    public function lowercasePool(): string
    {
        return $this->pool('abcdefghijklmnopqrstuvwxyz');
    }

    public function digitPool(): string
    {
        return $this->pool('0123456789');
    }

    /** The configured symbols, de-duplicated. Not filtered for ambiguity. */
    public function symbolPool(): string
    {
        return implode('', array_unique(str_split($this->symbols === '' ? '' : $this->symbols)));
    }

    /** Every character that may appear in a generated password. */
    public function fullPool(): string
    {
        return $this->uppercasePool().$this->lowercasePool().$this->digitPool().$this->symbolPool();
    }

    /**
     * @return list<string> empty when $password satisfies the policy
     *
     * Counts against the full character classes (A–Z, a–z, 0–9) — the
     * exclude_ambiguous setting only narrows the generator's pools, it never
     * penalises a character a user chose themselves.
     */
    public function violations(string $password): array
    {
        $out = [];

        if (mb_strlen($password) < $this->minLength) {
            $out[] = "The password must be at least {$this->minLength} characters.";
        }
        if ($this->minUppercase > 0 && $this->countMatching($password, '\p{Lu}') < $this->minUppercase) {
            $out[] = $this->countMessage('uppercase letter', $this->minUppercase);
        }
        if ($this->minLowercase > 0 && $this->countMatching($password, '\p{Ll}') < $this->minLowercase) {
            $out[] = $this->countMessage('lowercase letter', $this->minLowercase);
        }
        if ($this->minDigits > 0 && $this->countMatching($password, '\d') < $this->minDigits) {
            $out[] = $this->countMessage('number', $this->minDigits);
        }
        if ($this->minSymbols > 0 && $this->countIn($password, $this->symbolPool()) < $this->minSymbols) {
            $out[] = $this->countMessage('symbol', $this->minSymbols)
                .' Allowed symbols: '.$this->symbolPool();
        }

        return $out;
    }

    private function pool(string $chars): string
    {
        if (! $this->excludeAmbiguous) {
            return $chars;
        }

        return str_replace(self::AMBIGUOUS, '', $chars);
    }

    private function countIn(string $password, string $pool): int
    {
        if ($pool === '') {
            return 0;
        }

        return (int) preg_match_all('/['.preg_quote($pool, '/').']/u', $password);
    }

    private function countMatching(string $password, string $class): int
    {
        return (int) preg_match_all('/'.$class.'/u', $password);
    }

    private function countMessage(string $noun, int $n): string
    {
        return $n === 1
            ? "The password must contain at least one {$noun}."
            : "The password must contain at least {$n} {$noun}s.";
    }
}
