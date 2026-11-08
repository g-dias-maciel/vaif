<?php

namespace App\Http\Requests\Sales;

use App\Models\Prospect;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

abstract class ProspectRequest extends FormRequest
{
    public function authorize(): bool
    {
        // The controller checks the policy so create/update share one rule set.
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
        $id = $this->currentProspect()?->id;

        return [
            'name' => ['required', 'string', 'max:255'],
            'whatsapp' => ['required', 'string', 'max:50'],
            'whatsapp_e164' => [
                'required',
                'string',
                'max:20',
                Rule::unique('prospects', 'whatsapp_e164')->ignore($id),
            ],
            'instagram' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'studio' => ['nullable', 'string', 'max:255'],
            'style' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:255'],
            'revenue_monthly' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
            'ticket' => ['nullable', 'numeric', 'min:0', 'max:9999999'],
            'sessions_monthly' => ['nullable', 'integer', 'min:0', 'max:1000'],
            'admin_hours_day' => ['nullable', 'numeric', 'min:0', 'max:24'],
            'source' => ['nullable', 'string', 'max:50'],
            'campaign_id' => ['nullable', 'uuid', 'exists:campaigns,id'],
            'owner_id' => ['nullable', 'integer', 'exists:users,id'],
            'next_action_at' => ['nullable', 'date'],
            'next_action_note' => ['nullable', 'string', 'max:1000'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ];
    }

    public function messages(): array
    {
        return [
            'whatsapp_e164.required' => 'Informe um WhatsApp válido com DDD.',
            'whatsapp_e164.unique' => 'Já existe um prospect com esse WhatsApp.',
        ];
    }

    protected function currentProspect(): ?Prospect
    {
        $prospect = $this->route('prospect');

        return $prospect instanceof Prospect ? $prospect : null;
    }
}
