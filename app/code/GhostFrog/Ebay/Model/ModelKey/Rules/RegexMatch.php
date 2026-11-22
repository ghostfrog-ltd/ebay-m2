<?php

declare(strict_types=1);

namespace GhostFrog\Ebay\Model\ModelKey\Rules;

/** Captures and character offsets for item-specific patterns. */
final class RegexMatch
{
    public function __construct(
        private readonly array $captures,
        private readonly string $subject
    ) {
    }

    public function group(int $index = 0): ?string
    {
        return $this->captures[$index][0] ?? null;
    }

    public function groups(): array
    {
        return array_map(static fn(array $group): ?string => $group[0], array_slice($this->captures, 1));
    }

    public function start(): int
    {
        return mb_strlen(substr($this->subject, 0, $this->captures[0][1]));
    }

    public function end(): int
    {
        return $this->start() + mb_strlen($this->captures[0][0]);
    }
}
