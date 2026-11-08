<?php

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\Models\BillingSetting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class SettingController extends Controller
{
    public function edit()
    {
        return view('finance.settings', [
            'setting' => BillingSetting::current(),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'legal_name' => ['nullable', 'string', 'max:255'],
            'tax_id' => ['nullable', 'string', 'max:32'],
            'address' => ['nullable', 'string', 'max:2000'],
            'pix_key' => ['nullable', 'string', 'max:255'],
            'invoice_prefix' => ['required', 'string', 'max:16'],
            'default_due_day' => ['required', 'integer', 'between:1,31'],
            'payment_instructions' => ['nullable', 'string', 'max:5000'],
            'receipt_footer' => ['nullable', 'string', 'max:2000'],
        ]);

        BillingSetting::current()->update($data);

        return redirect()
            ->route('finance.settings')
            ->with('status', 'Configuração financeira atualizada.');
    }
}
