<?php

declare(strict_types=1);

namespace GhostFrog\Ebay\Model\ModelKey;

/**
 * Counts that show how fragmented a set of model keys is, taken before and after a reclassification.
 *
 * A listing counts as unknown when its key is blank, "unknown" or "unknown-review". Distinct keys and
 * singletons are counted over the keyed listings only, so newly keyed listings raise both.
 */
final class KeySummary
{
    private int $rows = 0;
    private int $unknown = 0;
    /** @var array<string, int> */
    private array $keys = [];
    /** @var array<string, int> */
    private array $types = [];
    /** @var array<string, int> */
    private array $sources = [];

    public function add(?string $key, ?string $itemType, ?string $keySource): void
    {
        $this->rows++;
        $key = trim((string)$key);
        if ($key === '' || str_starts_with($key, 'unknown')) {
            $this->unknown++;
        } else {
            $this->keys[$key] = ($this->keys[$key] ?? 0) + 1;
            $source = (string)$keySource ?: 'not recorded';
            $this->sources[$source] = ($this->sources[$source] ?? 0) + 1;
        }
        $type = (string)$itemType ?: 'not set';
        $this->types[$type] = ($this->types[$type] ?? 0) + 1;
    }

    /**
     * @return array{rows: int, keyed: int, unknown: int, unknown_percent: float, distinct: int, singletons: int,
     *     shared: int, five_plus: int}
     */
    public function totals(): array
    {
        $singletons = count(array_filter($this->keys, static fn (int $count): bool => $count === 1));
        $keyed = $this->rows - $this->unknown;
        return [
            'rows' => $this->rows,
            'keyed' => $keyed,
            'unknown' => $this->unknown,
            'unknown_percent' => $this->rows === 0 ? 0.0 : round(100 * $this->unknown / $this->rows, 1),
            'distinct' => count($this->keys),
            'singletons' => $singletons,
            // Listings that have at least one other listing to be compared with.
            'shared' => $keyed - $singletons,
            'five_plus' => count(array_filter($this->keys, static fn (int $count): bool => $count >= 5)),
        ];
    }

    /** @return array<string, int> item type => listings */
    public function types(): array
    {
        ksort($this->types);
        return $this->types;
    }

    /** @return array<string, int> key source => keyed listings */
    public function sources(): array
    {
        ksort($this->sources);
        return $this->sources;
    }

    /** @return array<string, int> the busiest keys, most listings first, ties in key order */
    public function top(int $limit = 20): array
    {
        $keys = $this->keys;
        ksort($keys);
        arsort($keys);
        // arsort is not stable across equal counts on its own; re-sort the ties by key.
        uksort($keys, static fn (string $a, string $b): int => [$keys[$b], $a] <=> [$keys[$a], $b]);
        return array_slice($keys, 0, $limit, true);
    }
}
