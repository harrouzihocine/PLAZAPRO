<?php

declare(strict_types=1);

namespace App\Modules\Settings\Http\Controllers;

use App\Modules\Settings\Models\UserDraft;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * The signed-in user's own draft metadata (mirror of their localStorage drafts).
 * No special permission — a user only ever touches their own rows. The
 * drafts-oversight page (separate, permissioned) reads everyone's.
 */
class UserDraftController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $drafts = UserDraft::query()
            ->where('user_id', $request->user()->id)
            ->get(['draft_key', 'label', 'route', 'updated_at']);

        return response()->json(['data' => $drafts]);
    }

    public function upsert(Request $request): JsonResponse
    {
        $data = $request->validate([
            'key' => ['required', 'string', 'max:191'],
            'label' => ['nullable', 'string', 'max:255'],
            'route' => ['nullable', 'string', 'max:1000'],
        ]);

        UserDraft::query()->updateOrCreate(
            ['user_id' => $request->user()->id, 'draft_key' => $data['key']],
            ['label' => $data['label'] ?? null, 'route' => $data['route'] ?? null],
        );

        return response()->json(['ok' => true]);
    }

    public function destroy(Request $request, string $key): JsonResponse
    {
        UserDraft::query()
            ->where('user_id', $request->user()->id)
            ->where('draft_key', $key)
            ->delete();

        return response()->json(['ok' => true]);
    }
}
