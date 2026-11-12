<?php

namespace App\Http\Controllers;

use App\Models\ObservedWrite;
use App\Services\Agent\ObservedWriteApplier;
use Illuminate\Http\Request;

class ObservedWriteController extends Controller
{
    public function decide(Request $request, ObservedWrite $write, ObservedWriteApplier $applier)
    {
        $user = $request->user();

        abort_unless(
            $user !== null
            && ($user->isStaff() || ($user->artist_id !== null && $user->artist_id === $write->artist_id)),
            403,
        );

        $data = $request->validate([
            'decision' => ['required', 'in:approve,reject'],
        ]);

        if ($data['decision'] === 'reject') {
            $applier->reject($write, $user);

            return back()->with('status', 'Sugestão recusada.');
        }

        $result = $applier->approve($write, $user);

        return back()->with(
            $result['ok'] ? 'status' : 'error',
            $result['ok'] ? 'Sugestão aplicada.' : ($result['error'] ?? 'Não foi possível aplicar a sugestão.'),
        );
    }
}
