<?php

declare(strict_types=1);

namespace Planovac;

final class Validator
{
    public const MAX_NIGHTS = 30;
    public const MAX_PEOPLE = 20;

    private array $errors = [];

    public function __construct(private readonly string $today)
    {
    }

    public function stay(mixed $city, mixed $dateFrom, mixed $dateTo, mixed $people, string $peopleField = 'people'): array
    {
        $city = $this->city($city);
        $fromOk = $this->date($dateFrom, 'date_from');
        $toOk = $this->date($dateTo, 'date_to');
        if ($fromOk && $toOk) {
            if ($dateTo <= $dateFrom) {
                $this->errors[] = 'Datum odjezdu (date_to) musí být pozdější než datum příjezdu (date_from).';
            } elseif (Dates::nights($dateFrom, $dateTo) > self::MAX_NIGHTS) {
                $this->errors[] = sprintf('Pobyt může mít nejvýše %d nocí.', self::MAX_NIGHTS);
            }
            if ($dateFrom < $this->today) {
                $this->errors[] = "Datum příjezdu $dateFrom je v minulosti.";
            }
        }
        $people = $this->people($people, $peopleField);

        return ['city' => (string) $city, 'date_from' => (string) $dateFrom, 'date_to' => (string) $dateTo, 'people' => $people];
    }

    public function city(mixed $city): ?string
    {
        if (!is_string($city) || trim($city) === '') {
            $this->errors[] = "Chybí povinné pole 'city'.";
            return null;
        }
        $city = trim($city);
        if (mb_strlen($city) > 100 || preg_match('/^\p{L}[\p{L}\p{M}\s.\'-]*$/u', $city) !== 1) {
            $this->errors[] = "Neplatný název města '$city' (povolena jsou písmena, mezery, tečka a pomlčka).";
            return null;
        }
        return $city;
    }

    public function date(mixed $value, string $field): bool
    {
        if ($value === null || $value === '') {
            $this->errors[] = "Chybí povinné pole '$field'.";
            return false;
        }
        if (!Dates::isValid($value)) {
            $this->errors[] = "Pole '$field' musí být platné datum ve formátu YYYY-MM-DD.";
            return false;
        }
        return true;
    }

    public function people(mixed $value, string $field = 'people'): int
    {
        if (is_string($value) && preg_match('/^\d+$/', $value) === 1) {
            $value = (int) $value;
        }
        if ($value === null) {
            $this->errors[] = "Chybí povinné pole '$field'.";
            return 0;
        }
        if (!is_int($value)) {
            $this->errors[] = "Pole '$field' musí být celé číslo.";
            return 0;
        }
        if ($value < 1 || $value > self::MAX_PEOPLE) {
            $this->errors[] = sprintf("Pole '%s' musí být v rozsahu 1–%d.", $field, self::MAX_PEOPLE);
        }
        return $value;
    }

    public function requireString(mixed $value, string $field, int $maxLength = 200): ?string
    {
        if (!is_string($value) || trim($value) === '') {
            $this->errors[] = "Chybí povinné pole '$field'.";
            return null;
        }
        if (mb_strlen($value) > $maxLength) {
            $this->errors[] = "Pole '$field' může mít nejvýše $maxLength znaků.";
            return null;
        }
        return trim($value);
    }

    public function email(mixed $value, string $field): ?string
    {
        $value = $this->requireString($value, $field);
        if ($value !== null && filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
            $this->errors[] = "Pole '$field' musí být platná e-mailová adresa.";
            return null;
        }
        return $value;
    }

    public function addError(string $message): void
    {
        $this->errors[] = $message;
    }

    public function throwIfInvalid(): void
    {
        if ($this->errors !== []) {
            throw new ApiException(400, 'VALIDATION_ERROR', implode(' ', $this->errors), $this->errors);
        }
    }
}
