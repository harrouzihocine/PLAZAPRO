<?php

declare(strict_types=1);

namespace App\Modules\Payments\Support;

use App\Modules\Payments\Support\Contracts\PdfRenderer;
use Barryvdh\DomPDF\Facade\Pdf;

/**
 * Pure-PHP PDF renderer (barryvdh/laravel-dompdf). No system binary required, so
 * it runs anywhere the app runs — including CI and the queue worker — with no
 * Chrome/LibreOffice dependency.
 */
class DomPdfRenderer implements PdfRenderer
{
    public function render(string $view, array $data): string
    {
        return Pdf::loadView($view, $data)
            ->setPaper('a4')
            ->output();
    }
}
