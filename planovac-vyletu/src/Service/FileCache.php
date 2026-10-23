<?php

declare(strict_types=1);

namespace Planovac\Service;

/** Jednoduchá souborová cache s TTL (JSON soubory v adresáři). */
final class FileCache
{
    /** @var \Closure(): int */
    private \Closure $now;

    public function __construct(private readonly string $dir, private readonly int $ttl, ?\Closure $now = null)
    {
        $this->now = $now ?? static fn (): int => time();
    }

    public function get(string $key): mixed
    {
        $file = $this->path($key);
        if (!is_file($file)) {
            return null;
        }
        $entry = json_decode((string) @file_get_contents($file), true);
        if (!is_array($entry) || !isset($entry['expires'], $entry['value']) || $entry['expires'] < ($this->now)()) {
            @unlink($file);
            return null;
        }
        return $entry['value'];
    }

    public function set(string $key, mixed $value): void
    {
        if (!is_dir($this->dir) && !@mkdir($this->dir, 0775, true) && !is_dir($this->dir)) {
            return; // cache je jen optimalizace, její selhání nesmí shodit požadavek
        }
        $tmp = $this->path($key) . '.' . bin2hex(random_bytes(4));
        $json = json_encode(['expires' => ($this->now)() + $this->ttl, 'value' => $value], JSON_UNESCAPED_UNICODE);
        if ($json !== false && @file_put_contents($tmp, $json) !== false) {
            @rename($tmp, $this->path($key));
        }
    }

    private function path(string $key): string
    {
        return $this->dir . '/' . sha1($key) . '.json';
    }
}
