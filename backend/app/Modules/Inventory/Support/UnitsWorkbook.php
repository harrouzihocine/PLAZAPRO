<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Support;

use App\Modules\Inventory\Enums\GtmPriority;
use App\Modules\Inventory\Enums\SaleStatus;
use App\Modules\Inventory\Models\Location;
use App\Modules\Inventory\Models\Unit;
use App\Modules\Settings\Models\DynamicListItem;
use Illuminate\Support\Collection;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Builds the units .xlsx workbooks: the filtered-browse export (the fast-edit
 * round-trip) and the blank import template (example rows + a translated
 * Guide sheet). Column names are the import's contract (ImportUnits); cell
 * values use base (untranslated) labels so a file exported in Arabic
 * re-imports fine — only the Guide sheet is in the requesting user's locale.
 */
class UnitsWorkbook
{
    /** The export header — every column ImportUnits understands plus read-only context. */
    public const EXPORT_COLUMNS = [
        'id', 'location_id', 'project', 'wilaya', 'commune', 'reference', 'rooms', 'floor',
        'area_sqm', 'price_semi_fini', 'price_fini', 'sale_status', 'gtm_priority', 'block', 'stack_floor', 'position',
        'payment_methods', 'note',
    ];

    /**
     * The creation-oriented template header (no ids / lifecycle columns). No
     * `reference` column — it is always auto-generated; a reference is still
     * honoured on import when present (e.g. a re-imported export).
     */
    public const TEMPLATE_COLUMNS = [
        'project', 'rooms', 'floor', 'area_sqm',
        'price_semi_fini', 'price_fini', 'gtm_priority', 'block', 'stack_floor', 'position',
        'payment_methods', 'note',
    ];

    /** Columns whose values must stay text (block "05" is not the number 5). */
    private const TEXT_COLUMNS = [
        'project', 'wilaya', 'commune', 'reference', 'rooms', 'floor', 'sale_status', 'gtm_priority', 'block',
        'payment_methods', 'note',
    ];

    private const PRICE_FORMAT = '#,##0';

    /**
     * @param Collection<int, Unit> $units
     * @param bool $maskSoldPrices blank the price cells of sold rows (viewer
     *                             lacks units.sold_price); blank re-imports as
     *                             "leave untouched", so the trip stays lossless
     */
    public function export(Collection $units, bool $maskSoldPrices = false): Spreadsheet
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle(__('app.units_sheet_units'));

        $this->writeHeader($sheet, self::EXPORT_COLUMNS);

        $row = 2;
        foreach ($units as $unit) {
            $hidePrices = $maskSoldPrices && $unit->sale_status === SaleStatus::Sold;
            $this->writeRow($sheet, $row++, self::EXPORT_COLUMNS, [
                'id' => $unit->id,
                'location_id' => $unit->location_id,
                'project' => $unit->location?->name,
                'wilaya' => $unit->location?->wilaya?->name,
                'commune' => $unit->location?->commune?->name,
                'reference' => $unit->reference,
                'rooms' => $unit->roomNumber?->label,
                'floor' => $unit->floor?->label,
                'area_sqm' => $unit->area_sqm,
                'price_semi_fini' => $hidePrices ? null : $unit->price_semi_fini,
                'price_fini' => $hidePrices ? null : $unit->price_fini,
                'sale_status' => $unit->sale_status?->value,
                'gtm_priority' => $unit->gtm_priority?->value,
                'block' => $unit->block,
                'stack_floor' => $unit->stack_floor,
                'position' => $unit->position,
                'payment_methods' => $this->paymentMethodsCell($unit),
                'note' => $unit->note,
            ]);
        }

        $sheet->setAutoFilter('A1:'.$sheet->getHighestColumn().'1');
        $this->finishSheet($sheet, count(self::EXPORT_COLUMNS));

        return $spreadsheet;
    }

    /**
     * The empty import template: a Units sheet holding only example rows (the
     * example project name never resolves, so importing the file untouched
     * creates nothing) and a Guide sheet documenting each column with the
     * live allowed values (projects, rooms/floor labels, priorities).
     */
    public function template(): Spreadsheet
    {
        $rooms = $this->listLabels('room_numbers');
        $floors = $this->listLabels('floors');
        $payments = $this->listLabels('project_payment_methods');
        $projects = Location::query()->active()->orderBy('name')->pluck('name');

        $spreadsheet = new Spreadsheet;
        $units = $spreadsheet->getActiveSheet();
        $units->setTitle(__('app.units_sheet_units'));

        $this->writeHeader($units, self::TEMPLATE_COLUMNS);

        $exampleProject = __('app.units_example_project');
        $examples = [
            // Full row: every column filled; the reference auto-generates, and a
            // blank payment_methods inherits the project's options.
            ['project' => $exampleProject, 'rooms' => $rooms->first() ?? 'F3', 'floor' => $floors->first() ?? '1',
                'area_sqm' => 85.5, 'price_semi_fini' => 12500000, 'price_fini' => 14200000,
                'gtm_priority' => GtmPriority::High->value, 'block' => 'A', 'stack_floor' => 2, 'position' => 5,
                'note' => __('app.units_example_note')],
            // Minimal row: a project and one price is enough.
            ['project' => $exampleProject, 'rooms' => $rooms->get(1) ?? $rooms->first() ?? 'F2',
                'price_semi_fini' => 9800000],
            // Finished price only, with a per-unit payment override (e.g. cash-only).
            ['project' => $exampleProject,
                'area_sqm' => 110, 'price_fini' => 18000000, 'gtm_priority' => GtmPriority::Low->value, 'block' => 'B',
                'payment_methods' => $payments->first() ?? ''],
        ];

        $row = 2;
        foreach ($examples as $example) {
            $this->writeRow($units, $row++, self::TEMPLATE_COLUMNS, $example);
        }
        // Grey italics: visibly placeholder rows, to be replaced with real data.
        $units->getStyle('A2:'.$units->getHighestColumn().($row - 1))
            ->applyFromArray(['font' => ['italic' => true, 'color' => ['argb' => 'FF9CA3AF']]]);

        $this->finishSheet($units, count(self::TEMPLATE_COLUMNS));

        $this->writeGuide($spreadsheet->createSheet(), $projects, $rooms, $floors, $payments);
        $spreadsheet->setActiveSheetIndex(0);

        return $spreadsheet;
    }

    /**
     * @param Collection<int, string> $projects
     * @param Collection<int, string> $rooms
     * @param Collection<int, string> $floors
     * @param Collection<int, string> $payments
     */
    private function writeGuide(Worksheet $sheet, Collection $projects, Collection $rooms, Collection $floors, Collection $payments): void
    {
        $sheet->setTitle(__('app.units_sheet_guide'));
        if (app()->getLocale() === 'ar') {
            $sheet->setRightToLeft(true);
        }

        // The how-it-works note, merged across the table width.
        $sheet->mergeCells('A1:D1');
        $sheet->setCellValue('A1', __('app.units_guide_note'));
        $sheet->getStyle('A1')->applyFromArray([
            'font' => ['bold' => true],
            'alignment' => ['wrapText' => true, 'vertical' => Alignment::VERTICAL_TOP],
        ]);
        $sheet->getRowDimension(1)->setRowHeight(58);

        $header = [__('app.units_guide_column'), __('app.units_guide_required'), __('app.units_guide_description'), __('app.units_guide_allowed')];
        foreach ($header as $i => $label) {
            $sheet->setCellValue([$i + 1, 3], $label);
        }
        $this->styleHeaderRow($sheet, 3, count($header));

        $yes = __('app.units_guide_yes');
        $no = __('app.units_guide_no');
        $onePrice = __('app.units_guide_one_price');

        $rows = [
            ['project', $yes, __('app.units_guide_project'), $this->joined($projects)],
            ['rooms', $no, __('app.units_guide_rooms'), $this->joined($rooms)],
            ['floor', $no, __('app.units_guide_floor'), $this->joined($floors)],
            ['area_sqm', $no, __('app.units_guide_area_sqm'), __('app.units_guide_number')],
            ['price_semi_fini', $onePrice, __('app.units_guide_price_semi_fini'), __('app.units_guide_number')],
            ['price_fini', $onePrice, __('app.units_guide_price_fini'), __('app.units_guide_number')],
            ['gtm_priority', $no, __('app.units_guide_gtm_priority'), implode(', ', array_column(GtmPriority::cases(), 'value'))],
            ['block', $no, __('app.units_guide_block'), ''],
            ['stack_floor', $no, __('app.units_guide_stack_floor'), __('app.units_guide_number')],
            ['position', $no, __('app.units_guide_position'), __('app.units_guide_number')],
            ['payment_methods', $no, __('app.units_guide_payment_methods'), $this->joined($payments)],
            ['note', $no, __('app.units_guide_unit_note'), ''],
        ];

        foreach ($rows as $i => $cells) {
            foreach ($cells as $j => $value) {
                $sheet->setCellValueExplicit([$j + 1, $i + 4], (string) $value, DataType::TYPE_STRING);
            }
        }

        foreach (['A' => 16, 'B' => 24, 'C' => 52, 'D' => 60] as $column => $width) {
            $sheet->getColumnDimension($column)->setWidth($width);
        }
        $sheet->getStyle('C4:D'.(3 + count($rows)))
            ->getAlignment()->setWrapText(true)->setVertical(Alignment::VERTICAL_TOP);
        $sheet->getStyle('A4:A'.(3 + count($rows)))->getFont()->setBold(true);
    }

    /** @param list<string> $columns */
    private function writeHeader(Worksheet $sheet, array $columns): void
    {
        foreach ($columns as $i => $column) {
            $sheet->setCellValue([$i + 1, 1], $column);
        }
        $this->styleHeaderRow($sheet, 1, count($columns));
        $sheet->freezePane('A2');
        if (app()->getLocale() === 'ar') {
            $sheet->setRightToLeft(true);
        }
    }

    /**
     * One data row. Numbers stay numeric cells (edit-friendly, price format
     * applied), TEXT_COLUMNS are forced to text so "05" survives the trip.
     *
     * @param list<string> $columns
     * @param array<string, mixed> $values
     */
    private function writeRow(Worksheet $sheet, int $row, array $columns, array $values): void
    {
        foreach ($columns as $i => $column) {
            $value = $values[$column] ?? null;
            if ($value === null || $value === '') {
                continue;
            }

            if (in_array($column, self::TEXT_COLUMNS, true)) {
                $sheet->setCellValueExplicit([$i + 1, $row], (string) $value, DataType::TYPE_STRING);
            } else {
                $sheet->setCellValue([$i + 1, $row], is_numeric($value) ? $value + 0 : $value);
                if (str_starts_with($column, 'price_')) {
                    $sheet->getStyle([$i + 1, $row])->getNumberFormat()->setFormatCode(self::PRICE_FORMAT);
                }
            }
        }
    }

    private function styleHeaderRow(Worksheet $sheet, int $row, int $columns): void
    {
        $sheet->getStyle([1, $row, $columns, $row])->applyFromArray([
            'font' => ['bold' => true, 'color' => ['argb' => 'FF6B4E0B']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FFF3E5C3']],
        ]);
    }

    private function finishSheet(Worksheet $sheet, int $columns): void
    {
        for ($i = 1; $i <= $columns; $i++) {
            $sheet->getColumnDimensionByColumn($i)->setAutoSize(true);
        }
    }

    /**
     * The payment_methods export cell for a unit: blank when it inherits the
     * project's options, else its own overriding set as comma-separated base
     * labels. Blank round-trips as "still inheriting"; a list re-imports as an
     * override — so an untouched export never silently pins inherited units.
     */
    private function paymentMethodsCell(Unit $unit): string
    {
        if (! $unit->payment_methods_overridden) {
            return '';
        }

        return $unit->paymentMethods->map(fn (DynamicListItem $m) => $m->label)->implode(', ');
    }

    /** @return Collection<int, string> base labels of a dynamic list, in configured order */
    private function listLabels(string $key): Collection
    {
        return DynamicListItem::query()
            ->whereHas('list', fn ($q) => $q->where('key', $key))
            ->orderBy('sort_order')
            ->pluck('label');
    }

    /** @param Collection<int, string> $values */
    private function joined(Collection $values): string
    {
        $shown = $values->take(30);
        $suffix = $values->count() > 30 ? ', …' : '';

        return $shown->implode(', ').$suffix;
    }
}
