<?php

declare(strict_types=1);

namespace Planovac;

final class ProviderRegistry
{
    /** @var array<string, Provider> */
    private array $providers = [];

    /** @param list<array<string, mixed>> $config */
    public function __construct(array $config)
    {
        foreach ($config as $row) {
            $provider = Provider::fromArray($row);
            if (isset($this->providers[$provider->id])) {
                throw new \InvalidArgumentException("Konfigurace: duplicitní id poskytovatele '{$provider->id}'.");
            }
            $this->providers[$provider->id] = $provider;
        }
    }

    /** @return list<Provider> */
    public function all(): array
    {
        return array_values($this->providers);
    }

    /** @return list<Provider> */
    public function byType(string $type): array
    {
        return array_values(array_filter($this->providers, fn (Provider $p) => $p->type === $type));
    }

    public function find(string $id): ?Provider
    {
        return $this->providers[$id] ?? null;
    }

    /** Vrátí poskytovatele daného typu, jinak vyhodí 400. */
    public function require(string $id, string $type): Provider
    {
        $provider = $this->find($id);
        if ($provider === null) {
            throw new ApiException(400, 'UNKNOWN_SOURCE', "Poskytovatel '$id' není v konfiguraci Plánovače.");
        }
        if ($provider->type !== $type) {
            $label = $type === Provider::TYPE_ACCOMMODATION ? 'ubytování' : 'atrakcí';
            throw new ApiException(400, 'SOURCE_TYPE_MISMATCH', "Poskytovatel '$id' není poskytovatelem $label (je typu '{$provider->type}').");
        }
        return $provider;
    }
}
