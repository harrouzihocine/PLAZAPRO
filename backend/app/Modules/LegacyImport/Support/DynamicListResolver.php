<?php

declare(strict_types=1);

namespace App\Modules\LegacyImport\Support;

use RuntimeException;

/**
 * The lookup backbone (PLAZA_MIGRATION_PLAN.md §4.3): resolves a legacy label
 * to a dynamic_list_items id by normalized label, honouring the explicit pins
 * in config('legacy_import.list_pins') — pins are by item VALUE so they
 * survive reseeding, and a pinned label is never auto-created. Unpinned
 * misses are created (label = legacy label, value = ASCII slug, meta
 * {"legacy": true}) — or with the explicit label/value from list_creates.
 */
class DynamicListResolver
{
    /** @var array<string, int> list key → dynamic_lists.id */
    private array $listIds = [];

    /** @var array<string, array<string, int>> list key → normalized label → item id */
    private array $byLabel = [];

    /** @var array<string, array<string, int>> list key → value → item id */
    private array $byValue = [];

    /** @var array<string, array<string, string>> list key → normalized pin label → pinned value */
    private array $pins = [];

    /** @var array<string, array<string, array{label: string, value: string}>> */
    private array $creates = [];

    public function __construct(
        private readonly ImportContext $ctx,
        private readonly Transform $transform,
    ) {
        foreach ((array) $ctx->cfg('list_pins', []) as $listKey => $pins) {
            foreach ($pins as $label => $value) {
                $this->pins[$listKey][self::normalize((string) $label)] = $value;
            }
        }
        foreach ((array) $ctx->cfg('list_creates', []) as $listKey => $creates) {
            foreach ($creates as $label => $spec) {
                $this->creates[$listKey][self::normalize((string) $label)] = $spec;
            }
        }
    }

    /**
     * Resolve a legacy label to an item id, creating the item when no pin or
     * existing item matches. NULL/blank labels resolve to NULL.
     */
    public function resolve(string $listKey, ?string $label, array $extraMeta = []): ?int
    {
        $label = trim((string) $label);
        if ($label === '') {
            return null;
        }
        $this->load($listKey);
        $norm = self::normalize($label);

        // Explicit pin → existing item by value; never create (§4.3).
        if (isset($this->pins[$listKey][$norm])) {
            $value = $this->pins[$listKey][$norm];
            if (! isset($this->byValue[$listKey][$value])) {
                throw new RuntimeException(
                    "legacy:import — pinned item '{$value}' missing from list '{$listKey}'. Reseed the dynamic lists."
                );
            }

            return $this->byValue[$listKey][$value];
        }

        if (isset($this->byLabel[$listKey][$norm])) {
            return $this->byLabel[$listKey][$norm];
        }

        return $this->create($listKey, $label, $norm, $extraMeta);
    }

    /** Fetch an existing item by exact value (e.g. visit_outcomes:interested). */
    public function byValue(string $listKey, string $value): int
    {
        $this->load($listKey);
        if (! isset($this->byValue[$listKey][$value])) {
            throw new RuntimeException(
                "legacy:import — expected item '{$value}' missing from list '{$listKey}'. Reseed the dynamic lists."
            );
        }

        return $this->byValue[$listKey][$value];
    }

    private function create(string $listKey, string $label, string $norm, array $extraMeta): int
    {
        $spec = $this->creates[$listKey][$norm] ?? null;

        // A creates-spec renames the item (label ≠ legacy label), so the
        // normalized-label lookup misses on re-runs — its value acts as a pin
        // once the item exists.
        if ($spec !== null && isset($this->byValue[$listKey][$spec['value']])) {
            return $this->byLabel[$listKey][$norm] = $this->byValue[$listKey][$spec['value']];
        }

        $useLabel = $spec['label'] ?? $label;
        $value = $spec['value'] ?? $this->uniqueValue($listKey, $this->transform->slug($label));

        $now = now()->format('Y-m-d H:i:s');
        $id = $this->ctx->insert('dynamic_list_items', [
            'dynamic_list_id' => $this->listId($listKey),
            'label' => $useLabel,
            'value' => $value,
            'sort_order' => count($this->byValue[$listKey]) + 1,
            'is_active' => 1,
            'meta' => json_encode(array_merge(['legacy' => true], $extraMeta)),
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $this->byLabel[$listKey][$norm] = $id;
        $this->byValue[$listKey][$value] = $id;
        $this->ctx->count('lists', 'created');
        $this->ctx->warn('list_item_created', "List '{$listKey}': created item '{$useLabel}' (value '{$value}') from legacy label '{$label}'.");

        return $id;
    }

    private function uniqueValue(string $listKey, string $base): string
    {
        $value = $base;
        $i = 2;
        while (isset($this->byValue[$listKey][$value])) {
            $value = $base.'_'.$i++;
        }

        return $value;
    }

    private function listId(string $listKey): int
    {
        $this->load($listKey);

        return $this->listIds[$listKey];
    }

    private function load(string $listKey): void
    {
        if (isset($this->listIds[$listKey])) {
            return;
        }
        $list = $this->ctx->target->table('dynamic_lists')->where('key', $listKey)->first(['id']);
        if ($list === null) {
            throw new RuntimeException("legacy:import — dynamic list '{$listKey}' does not exist. Run the system seeders first.");
        }
        $this->listIds[$listKey] = (int) $list->id;
        $this->byLabel[$listKey] = [];
        $this->byValue[$listKey] = [];
        $items = $this->ctx->target->table('dynamic_list_items')
            ->where('dynamic_list_id', $list->id)
            ->get(['id', 'label', 'value']);
        foreach ($items as $item) {
            $this->byLabel[$listKey][self::normalize($item->label)] = (int) $item->id;
            $this->byValue[$listKey][$item->value] = (int) $item->id;
        }
    }

    /**
     * Matching key: trim, collapse whitespace, casefold, strip Arabic
     * diacritics/tatweel and fold alef variants (§4.3).
     */
    public static function normalize(string $label): string
    {
        $s = preg_replace('/\s+/u', ' ', trim($label));
        $s = preg_replace('/[\x{064B}-\x{0652}\x{0670}\x{0640}]/u', '', $s);
        $s = str_replace(['أ', 'إ', 'آ'], 'ا', $s);

        return mb_strtolower($s);
    }
}
