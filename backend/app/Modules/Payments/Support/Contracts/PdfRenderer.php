<?php

declare(strict_types=1);

namespace App\Modules\Payments\Support\Contracts;

/**
 * Renders a Blade template to raw PDF bytes. Kept behind an interface so the
 * renderer (dompdf now, headless-Chrome later) is swappable without touching the
 * document-generation Actions. Bound in AppServiceProvider.
 */
interface PdfRenderer
{
    /**
     * @param  string  $view  Blade view name (e.g. "documents.receipt")
     * @param  array<string, mixed>  $data
     * @return string Raw PDF bytes
     */
    public function render(string $view, array $data): string;
}
