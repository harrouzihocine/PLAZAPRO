<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Seeds Algeria's geographic hierarchy — 58 wilayas and their 1,541 communes —
 * from the vendored official dataset (database/data/algeria_cities.json,
 * sourced from othmanus/algeria-cities). Everything here is editable in-app via
 * the Settings → Wilayas & Communes screen; these are the starting defaults.
 *
 * Idempotent (keys off wilaya code and (wilaya_id, commune name)) and inserted
 * via the query builder so seeding doesn't flood the activity log.
 */
class WilayaCommuneSeeder extends Seeder
{
    public function run(): void
    {
        $rows = $this->dataset();
        $now = now();

        // 1) Wilayas, keyed by their official code.
        $names = [];
        foreach ($rows as $row) {
            $code = str_pad((string) $row['wilaya_code'], 2, '0', STR_PAD_LEFT);
            $names[$code] ??= (string) $row['wilaya_name'];
        }
        ksort($names);

        $existingWilayas = DB::table('wilayas')->pluck('id', 'code')->all();
        $newWilayas = [];
        foreach ($names as $code => $name) {
            if (! isset($existingWilayas[$code])) {
                $newWilayas[] = ['code' => $code, 'name' => $name, 'status' => 'active', 'created_at' => $now, 'updated_at' => $now];
            }
        }
        if ($newWilayas !== []) {
            DB::table('wilayas')->insert($newWilayas);
        }

        $wilayaIdByCode = DB::table('wilayas')->pluck('id', 'code')->all();

        // 2) Communes, deduped by (wilaya_id, name).
        $seen = DB::table('communes')
            ->select('wilaya_id', 'name')
            ->get()
            ->mapWithKeys(fn ($c) => [$c->wilaya_id.'|'.$c->name => true])
            ->all();

        $newCommunes = [];
        foreach ($rows as $row) {
            $code = str_pad((string) $row['wilaya_code'], 2, '0', STR_PAD_LEFT);
            $wilayaId = $wilayaIdByCode[$code];
            $name = (string) $row['commune_name'];
            $key = $wilayaId.'|'.$name;

            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;

            $newCommunes[] = [
                'wilaya_id' => $wilayaId,
                'name' => $name,
                'daira_name' => $row['daira_name'] ?? null,
                'status' => 'active',
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        foreach (array_chunk($newCommunes, 500) as $chunk) {
            DB::table('communes')->insert($chunk);
        }
    }

    /**
     * @return list<array{commune_name: string, daira_name: string, wilaya_code: string|int, wilaya_name: string}>
     */
    private function dataset(): array
    {
        $path = database_path('data/algeria_cities.json');

        if (! is_file($path)) {
            throw new RuntimeException("Missing wilaya/commune dataset at {$path}.");
        }

        /** @var list<array<string, mixed>> $data */
        $data = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);

        return $data;
    }
}
