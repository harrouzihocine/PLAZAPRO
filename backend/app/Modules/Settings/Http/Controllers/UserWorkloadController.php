<?php

declare(strict_types=1);

namespace App\Modules\Settings\Http\Controllers;

use App\Modules\Settings\Actions\BuildUserWorkload;
use App\Modules\Settings\Actions\TransferUserWork;
use App\Modules\Settings\Http\Requests\TransferUserWorkRequest;
use App\Modules\Settings\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * The offboarding desk (users.transfer, see routes): review everything a
 * user owns — their career record plus the open work that would orphan when
 * they leave — and hand that open book to a successor. Thin — the report is
 * BuildUserWorkload, the hand-over is TransferUserWork.
 */
class UserWorkloadController extends Controller
{
    public function workload(Request $request, User $user, BuildUserWorkload $action): JsonResponse
    {
        // ?totals=1 — the cheap probe (open-book counts only) used by the
        // deactivate warning; the full report builds the item lists too.
        return response()->json(['data' => $request->boolean('totals')
            ? $action->totals($user)
            : $action->handle($user)]);
    }

    public function transfer(TransferUserWorkRequest $request, User $user, TransferUserWork $action): JsonResponse
    {
        $successor = User::query()->findOrFail($request->integer('successor_id'));

        $moved = $action->handle(
            $user,
            $successor,
            $request->user(),
            $request->boolean('dispatch_to_pool'),
        );

        return response()->json(['data' => $moved]);
    }
}
