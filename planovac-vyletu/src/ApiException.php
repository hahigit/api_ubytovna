<?php

declare(strict_types=1);

namespace Planovac;

final class ApiException extends \RuntimeException
{
    public function __construct(
        public readonly int $status,
        public readonly string $errorCode,
        string $message,
        public readonly ?array $details = null,
        public readonly array $extra = [],
    ) {
        parent::__construct($message);
    }

    public function toBody(): array
    {
        $error = ['code' => $this->errorCode, 'message' => $this->getMessage()];
        if ($this->details !== null) {
            $error['details'] = $this->details;
        }
        return ['error' => $error] + $this->extra;
    }
}
