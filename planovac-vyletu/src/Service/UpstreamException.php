<?php

declare(strict_types=1);

namespace Planovac\Service;

/** Selhání navazujícího API, převedené na srozumitelný český důvod. */
final class UpstreamException extends \RuntimeException
{
    public const TIMEOUT = 'timeout';
    public const UNREACHABLE = 'unreachable';
    public const HTTP_ERROR = 'http_error';
    public const INVALID_RESPONSE = 'invalid_response';

    public function __construct(
        public readonly string $kind,
        string $reason,
        public readonly ?int $httpStatus = null,
        public readonly ?string $upstreamCode = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($reason, 0, $previous);
    }

    public function isTimeout(): bool
    {
        return $this->kind === self::TIMEOUT;
    }

    /** HTTP kód, který má Plánovač vrátit klientovi, když tato chyba znemožní požadavek. */
    public function gatewayStatus(): int
    {
        return $this->isTimeout() ? 504 : 502;
    }
}
