<?php

declare(strict_types=1);

namespace Planovac;

/**
 * Chyba, která se vrací klientovi v jednotném tvaru {"error": {"code", "message"}}.
 * $extra se přidá do těla odpovědi vedle "error" (např. neúspěšná objednávka).
 */
final class ApiException extends \RuntimeException
{
    /**
     * @param list<string>|null $details
     * @param array<string, mixed> $extra
     */
    public function __construct(
        public readonly int $status,
        public readonly string $errorCode,
        string $message,
        public readonly ?array $details = null,
        public readonly array $extra = [],
    ) {
        parent::__construct($message);
    }

    /** @return array<string, mixed> */
    public function toBody(): array
    {
        $error = ['code' => $this->errorCode, 'message' => $this->getMessage()];
        if ($this->details !== null) {
            $error['details'] = $this->details;
        }
        return ['error' => $error] + $this->extra;
    }
}
