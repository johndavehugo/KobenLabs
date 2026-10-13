<?php

namespace App\Services;

use Illuminate\Support\Str;
use RuntimeException;

/**
 * Fill blank Reverb variables in the .env file without touching set ones.
 *
 * Laravel's own `reverb:install` only appends lines for keys that are absent
 * entirely, so on a fresh clone — whose .env.example ships every REVERB_* key
 * present but empty — it writes nothing and still reports success. This fills
 * the blanks instead: generated secrets for the credentials, vendor-matching
 * local defaults for host/port/scheme. Anything already set, quoted or not,
 * is left byte-for-byte alone, which is what makes it safe to run on every
 * `composer run setup` and harmless to re-run at any time.
 */
class ReverbEnvSetup
{
    public function __construct(private ?string $path = null) {}

    /**
     * Fill every blank Reverb variable in the environment file.
     *
     * A variable counts as blank when its line is missing, empty, or holds
     * only quotes/whitespace. Commented lines (`# REVERB_APP_KEY=...`) are
     * never treated as the variable and never modified.
     *
     * @return array{filled: array<string, string>, kept: array<int, string>}
     */
    public function fillBlanks(): array
    {
        // environmentFilePath(), not environmentFile(): the latter is only the
        // file name ('.env'), which would resolve against the working
        // directory instead of the application.
        $path = $this->path ?? app()->environmentFilePath();

        if (! is_file($path) || ! is_readable($path)) {
            throw new RuntimeException("Environment file not found: {$path}");
        }

        $contents = (string) file_get_contents($path);
        $original = $contents;
        $eol = str_contains($contents, "\r\n") ? "\r\n" : "\n";

        $filled = [];
        $kept = [];

        foreach ($this->generators() as $key => $generate) {
            if (preg_match('/^[ \t]*'.preg_quote($key, '/').'[ \t]*=([^\r\n]*)$/m', $contents, $matches)) {
                if ($this->isBlankValue($matches[1])) {
                    $value = $generate();
                    $contents = preg_replace(
                        '/^[ \t]*'.preg_quote($key, '/').'[ \t]*=[^\r\n]*$/m',
                        $this->canonicalLine($key, $value),
                        $contents,
                        1
                    );
                    $filled[$key] = $value;
                } else {
                    $kept[] = $key;
                }
            } else {
                $value = $generate();
                $separator = $contents === '' || str_ends_with($contents, "\r\n") || str_ends_with($contents, "\n") || str_ends_with($contents, "\r")
                    ? ''
                    : $eol;
                $contents .= $separator.$this->canonicalLine($key, $value).$eol;
                $filled[$key] = $value;
            }
        }

        if ($contents !== $original) {
            file_put_contents($path, $contents);
        }

        return ['filled' => $filled, 'kept' => $kept];
    }

    /**
     * @return array<string, callable(): string>
     */
    private function generators(): array
    {
        return [
            'REVERB_APP_ID' => fn (): string => (string) random_int(100_000, 999_999),
            'REVERB_APP_KEY' => fn (): string => Str::lower(Str::random(20)),
            'REVERB_APP_SECRET' => fn (): string => Str::lower(Str::random(20)),
            'REVERB_HOST' => fn (): string => 'localhost',
            'REVERB_PORT' => fn (): string => '8080',
            'REVERB_SCHEME' => fn (): string => 'http',
        ];
    }

    /**
     * The exact line written when a value is filled in or a key is appended.
     *
     * REVERB_HOST stays quoted to match the file's existing style; the rest
     * are bare, matching what `reverb:install` writes.
     */
    private function canonicalLine(string $key, string $value): string
    {
        if ($key === 'REVERB_HOST') {
            return $key.'="'.$value.'"';
        }

        return $key.'='.$value;
    }

    private function isBlankValue(string $raw): bool
    {
        $value = trim($raw);

        if (strlen($value) >= 2) {
            $first = $value[0];
            $last = $value[strlen($value) - 1];

            if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
                $value = substr($value, 1, -1);
            }
        }

        return trim($value) === '';
    }
}
