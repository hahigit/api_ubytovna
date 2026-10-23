<?php

declare(strict_types=1);

namespace Planovac\Service;

/**
 * Objednávky v jednom JSON souboru {"orders": {"<order_id>": {...}}}.
 *
 * Zápis: exkluzivní zámek (flock LOCK_EX) na vedlejším souboru <soubor>.lock → načtení
 * aktuálního obsahu → zápis do dočasného souboru → fsync → rename. Zámek je na zvláštním
 * souboru, protože rename vymění inode datového souboru. Díky tomu se nepřepíšou souběžné
 * zápisy z více PHP procesů (PHP_CLI_SERVER_WORKERS) a soubor se při pádu neporuší.
 */
final class OrderRepository
{
    public function __construct(private readonly string $file)
    {
        $dir = dirname($file);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException("Nelze vytvořit složku $dir pro objednávky.");
        }
    }

    /** @param array<string, mixed> $order */
    public function save(array $order): void
    {
        $this->withLock(LOCK_EX, function () use ($order): void {
            $data = $this->read();
            $data['orders'][$order['order_id']] = $order;
            $this->writeAtomically($data);
        });
    }

    /** @return array<string, mixed>|null */
    public function find(string $orderId): ?array
    {
        return $this->withLock(LOCK_SH, fn () => $this->read()['orders'][$orderId] ?? null);
    }

    /**
     * @template T
     * @param \Closure(): T $fn
     * @return T
     */
    private function withLock(int $mode, \Closure $fn): mixed
    {
        $lock = fopen($this->file . '.lock', 'c');
        if ($lock === false || !flock($lock, $mode)) {
            throw new \RuntimeException("Nelze zamknout soubor objednávek {$this->file}.");
        }
        try {
            return $fn();
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** @return array{orders: array<string, array<string, mixed>>} */
    private function read(): array
    {
        if (!is_file($this->file)) {
            return ['orders' => []];
        }
        $json = file_get_contents($this->file);
        try {
            $data = json_decode((string) $json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \RuntimeException("Soubor objednávek {$this->file} není platný JSON: {$e->getMessage()}", 0, $e);
        }
        if (!is_array($data) || !is_array($data['orders'] ?? null)) {
            throw new \RuntimeException("Soubor objednávek {$this->file} nemá strukturu {\"orders\": {...}}.");
        }
        return $data;
    }

    /** @param array<string, mixed> $data */
    private function writeAtomically(array $data): void
    {
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
        $tmp = $this->file . '.tmp';
        $fh = fopen($tmp, 'w');
        if ($fh === false) {
            throw new \RuntimeException("Nelze zapsat $tmp.");
        }
        try {
            if (fwrite($fh, $json . "\n") === false || !fflush($fh) || !fsync($fh)) {
                throw new \RuntimeException("Zápis do $tmp selhal.");
            }
        } finally {
            fclose($fh);
        }
        if (!rename($tmp, $this->file)) {
            @unlink($tmp);
            throw new \RuntimeException("Nelze přejmenovat $tmp na {$this->file}.");
        }
    }
}
