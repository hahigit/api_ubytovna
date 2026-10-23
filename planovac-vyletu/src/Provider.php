<?php

declare(strict_types=1);

namespace Planovac;

final class Provider
{
    public const TYPE_ACCOMMODATION = 'accommodation';
    public const TYPE_ATTRACTIONS = 'attractions';

    public function __construct(
        public readonly string $id,
        public readonly string $type,
        public readonly string $name,
        public readonly string $baseUrl,
        public readonly float $timeout,
    ) {
    }

    public static function fromArray(array $row): self
    {
        foreach (['id', 'type', 'name', 'base_url'] as $key) {
            if (!isset($row[$key]) || !is_string($row[$key]) || $row[$key] === '') {
                throw new \InvalidArgumentException("Konfigurace poskytovatele: chybí položka '$key'.");
            }
        }
        if (!in_array($row['type'], [self::TYPE_ACCOMMODATION, self::TYPE_ATTRACTIONS], true)) {
            throw new \InvalidArgumentException("Konfigurace poskytovatele '{$row['id']}': neznámý typ '{$row['type']}'.");
        }
        return new self($row['id'], $row['type'], $row['name'], rtrim($row['base_url'], '/'), (float) ($row['timeout'] ?? 3.0));
    }

    public function url(string $path): string
    {
        return $this->baseUrl . $path;
    }

    public function toArray(): array
    {
        return ['id' => $this->id, 'type' => $this->type, 'name' => $this->name, 'base_url' => $this->baseUrl, 'timeout' => $this->timeout];
    }
}
