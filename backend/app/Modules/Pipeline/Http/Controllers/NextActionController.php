<?php

declare(strict_types=1);

namespace App\Modules\Pipeline\Http\Controllers;

use App\Modules\Pipeline\Actions\CorrectNextAction;
use App\Modules\Pipeline\Http\Requests\CorrectNextActionRequest;
use App\Modules\Pipeline\Http\Resources\NextActionResource;
use App\Modules\Pipeline\Models\NextAction;
use Illuminate\Routing\Controller;

/**
 * Correcting the enforced next action — e.g. the sales agent edits the last log to
 * turn a follow-up "call" into an "in-site visit". The change is captured as a
 * cancel + new pending version (CorrectNextAction → supersedeWith), never edited in
 * place, so the whole plan history stays in the timeline.
 */
class NextActionController extends Controller
{
    public function correct(CorrectNextActionRequest $request, NextAction $nextAction, CorrectNextAction $action): NextActionResource
    {
        return new NextActionResource(
            $action->handle($nextAction, $request->validated(), $request->validated('reason'))
                ->load('assignedTo'),
        );
    }
}
