<?php

namespace GregPriday\WorkManager\Support;

/**
 * Domain diff (before/after/changes/summary) produced by apply().
 *
 * @immutable
 *
 * @property-read array $before
 * @property-read array $after
 * @property-read array $changes
 *
 * @see docs/concepts/lifecycle-and-flow.md
 */
class Diff
{
    public function __construct(
        public readonly array $before,
        public readonly array $after,
        public readonly array $changes,
        public readonly ?string $summary = null
    ) {}

    public static function fromArrays(array $before, array $after, ?string $summary = null): self
    {
        $changes = self::computeChanges($before, $after);

        return new self($before, $after, $changes, $summary);
    }

    public static function empty(): self
    {
        return new self([], [], []);
    }

    public function toArray(): array
    {
        return [
            'before' => $this->before,
            'after' => $this->after,
            'changes' => $this->changes,
            'summary' => $this->summary,
        ];
    }

    public function toJson(): string
    {
        return json_encode($this->toArray(), JSON_PRETTY_PRINT);
    }

    public function hasChanges(): bool
    {
        return ! empty($this->changes);
    }

    protected static function computeChanges(array $before, array $after): array
    {
        $changes = [];

        // Find additions and modifications
        foreach ($after as $key => $value) {
            if (! array_key_exists($key, $before)) {
                $changes[$key] = [
                    'type' => 'added',
                    'value' => $value,
                ];
            } elseif ($before[$key] !== $value) {
                $changes[$key] = [
                    'type' => 'modified',
                    'from' => $before[$key],
                    'to' => $value,
                ];
            }
        }

        // Find deletions
        foreach ($before as $key => $value) {
            if (! array_key_exists($key, $after)) {
                $changes[$key] = [
                    'type' => 'removed',
                    'value' => $value,
                ];
            }
        }

        return $changes;
    }
}
