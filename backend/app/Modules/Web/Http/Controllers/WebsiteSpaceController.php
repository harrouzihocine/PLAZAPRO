<?php

declare(strict_types=1);

namespace App\Modules\Web\Http\Controllers;

use App\Modules\Web\Models\WebsiteSpace;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

/**
 * Hands the Settings UI the site libraries' anchor ids (creating the rows on
 * first use) so it can drive the standard media endpoints with
 * mediableType='website'. settings.manage-gated like the rest of the page.
 */
class WebsiteSpaceController extends Controller
{
    public function __invoke(): JsonResponse
    {
        return response()->json(['data' => [
            'id' => WebsiteSpace::hero()->id,
            'about_id' => WebsiteSpace::about()->id,
        ]]);
    }
}
