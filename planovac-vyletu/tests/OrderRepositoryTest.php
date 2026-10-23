<?php

declare(strict_types=1);

namespace Planovac\Tests;

use Planovac\Service\OrderRepository;
use Planovac\Tests\Support\TestCase;

final class OrderRepositoryTest extends TestCase
{
    private function file(): string
    {
        return $this->config()['data_file'];
    }

    /** @return array<string, mixed> */
    private static function order(string $id): array
    {
        return ['order_id' => $id, 'status' => 'confirmed', 'created_at' => '2026-11-02T10:00:00+01:00', 'customer' => ['name' => 'Jiří Žák'], 'total_price' => ['czk' => 100, 'eur' => 4.0]];
    }

    public function testCreatesFolderAndPersistsAsJson(): void
    {
        $repo = new OrderRepository($this->file());
        self::assertNull($repo->find('ORD-1'));

        $repo->save(self::order('ORD-1'));
        $repo->save(self::order('ORD-2'));

        self::assertFileExists($this->file());
        self::assertFileDoesNotExist($this->file() . '.tmp', 'dočasný soubor se přejmenuje');
        $data = json_decode((string) file_get_contents($this->file()), true);
        self::assertSame(['ORD-1', 'ORD-2'], array_keys($data['orders']));
        self::assertStringContainsString('Jiří Žák', (string) file_get_contents($this->file()), 'UTF-8 bez escapování');
        self::assertSame(4.0, (new OrderRepository($this->file()))->find('ORD-2')['total_price']['eur']);
    }

    public function testCorruptedFileIsNotOverwritten(): void
    {
        mkdir(dirname($this->file()), 0775, true);
        file_put_contents($this->file(), '{"orders": {');
        $repo = new OrderRepository($this->file());

        try {
            $repo->save(self::order('ORD-1'));
            self::fail('Očekávána výjimka');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('není platný JSON', $e->getMessage());
        }
        self::assertSame('{"orders": {', file_get_contents($this->file()));
    }

    public function testConcurrentWritersFromMoreProcessesLoseNothing(): void
    {
        if (!function_exists('pcntl_fork')) {
            self::markTestSkipped('Rozšíření pcntl není k dispozici.');
        }
        $processes = 8;
        $perProcess = 15;
        $pids = [];
        for ($p = 0; $p < $processes; $p++) {
            $pid = pcntl_fork();
            if ($pid === 0) {
                $repo = new OrderRepository($this->file());
                for ($i = 0; $i < $perProcess; $i++) {
                    $repo->save(self::order("ORD-$p-$i"));
                }
                exit(0);
            }
            $pids[] = $pid;
        }
        foreach ($pids as $pid) {
            pcntl_waitpid($pid, $status);
            self::assertSame(0, pcntl_wexitstatus($status));
        }

        $data = json_decode((string) file_get_contents($this->file()), true, flags: JSON_THROW_ON_ERROR);
        self::assertCount($processes * $perProcess, $data['orders']);
    }
}
