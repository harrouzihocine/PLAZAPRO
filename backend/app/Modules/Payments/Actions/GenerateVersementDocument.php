<?php

declare(strict_types=1);

namespace App\Modules\Payments\Actions;

use App\Modules\Payments\Enums\DocumentType;
use App\Modules\Payments\Jobs\GenerateDocumentPdf;
use App\Modules\Payments\Models\Document;
use App\Modules\Payments\Models\Versement;
use App\Modules\Payments\Support\DocumentNumberGenerator;
use App\Modules\Payments\Support\Money;
use App\Modules\Settings\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Generate a branded receipt for a recorded versement. Allocates a unique
 * document number, snapshots the figures into meta (so a reprint is faithful even
 * if related records later change), records the documents row, links it back to
 * the versement, and dispatches the PDF render to the queue worker.
 */
class GenerateVersementDocument
{
    public function __construct(private DocumentNumberGenerator $numbers) {}

    public function handle(Versement $versement, User $actor): Document
    {
        abort_unless($versement->isActive(), 422, 'A cancelled versement cannot be receipted.');

        $versement->loadMissing(['method', 'clientProject.client', 'clientProject.unit', 'scheduleItem']);

        $document = DB::transaction(function () use ($versement, $actor) {
            $type = DocumentType::Receipt;

            $version = Document::query()
                ->where('documentable_type', $versement->getMorphClass())
                ->where('documentable_id', $versement->id)
                ->where('type', $type->value)
                ->count() + 1;

            $number = $this->numbers->next($type);

            return Document::create([
                'documentable_type' => $versement->getMorphClass(),
                'documentable_id' => $versement->id,
                'type' => $type->value,
                'number' => $number,
                'template' => $type->template(),
                'disk' => 'documents',
                'path' => "receipts/{$number}.pdf",
                'render_status' => 'pending',
                'version' => $version,
                'generated_by' => $actor->id,
                'generated_at' => now(),
                'meta' => $this->snapshot($versement, $number, $version),
            ]);
        });

        // Link the receipt to the versement and render on the queue worker.
        $versement->update(['document_id' => $document->id]);

        GenerateDocumentPdf::dispatch($document);

        return $document;
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(Versement $versement, string $number, int $version): array
    {
        $project = $versement->clientProject;

        $totalPrice = $project?->total_price !== null ? (string) $project->total_price : null;
        $totalPaid = Money::sum(
            $project ? $project->versements()->active()->pluck('amount') : []
        );

        return [
            'company' => config('documents.company'),
            'document' => [
                'number' => $number,
                'type' => DocumentType::Receipt->value,
                'version' => $version,
                'generated_at' => now()->toDayDateTimeString(),
            ],
            'client' => [
                'name' => trim(($project?->client?->first_name ?? '').' '.($project?->client?->last_name ?? '')) ?: null,
                'phone' => $project?->client?->phone,
            ],
            'project' => [
                'id' => $project?->id,
                'unit_reference' => $project?->unit?->reference,
                'total_price' => $totalPrice,
            ],
            'versement' => [
                'amount' => (string) $versement->amount,
                'paid_on' => optional($versement->paid_on)->toDateString(),
                'method' => $versement->method?->label,
                'reference' => $versement->reference,
                'installment_no' => $versement->scheduleItem?->installment_no,
            ],
            'balance' => [
                'total_price' => $totalPrice,
                'total_paid' => $totalPaid,
                'outstanding' => $totalPrice !== null ? Money::sub($totalPrice, $totalPaid) : null,
            ],
        ];
    }
}
