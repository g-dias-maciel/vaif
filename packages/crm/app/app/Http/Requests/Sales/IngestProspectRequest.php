<?php

namespace App\Http\Requests\Sales;

use App\Models\Prospect;
use Illuminate\Foundation\Http\FormRequest;

class IngestProspectRequest extends FormRequest
{
    public function authorize(): bool
    {
        // The `sales.ingest` middleware owns the bearer-token check.
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'whatsapp_e164' => Prospect::normalizePhone($this->input('whatsapp')),
        ]);
    }

    public function rules(): array
    {
        return [
            'nome' => ['required', 'string', 'max:255'],
            'whatsapp' => ['required', 'string', 'max:50'],
            'whatsapp_e164' => ['required', 'string', 'max:20'],
            'instagram' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'studio' => ['nullable', 'string', 'max:255'],
            'faturamento' => ['nullable', 'numeric'],
            'ticket' => ['nullable', 'numeric'],
            'sessoes' => ['nullable', 'integer'],
            'horas_admin' => ['nullable', 'numeric'],
            'prejuizo_mensal' => ['nullable', 'numeric'],
            'potencial_lucro' => ['nullable', 'numeric'],
            'origem' => ['nullable', 'string', 'max:50'],
            'utm_source' => ['nullable', 'string', 'max:255'],
            'utm_medium' => ['nullable', 'string', 'max:255'],
            'utm_campaign' => ['nullable', 'string', 'max:255'],
            'utm_content' => ['nullable', 'string', 'max:255'],
            'utm_term' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return [
            'whatsapp_e164.required' => 'Informe um WhatsApp válido com DDD.',
        ];
    }
}
