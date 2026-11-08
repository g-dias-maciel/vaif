<?php

namespace App\Http\Requests\Sales;

use App\Models\Prospect;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class HandoffRequest extends FormRequest
{
    public function authorize(): bool
    {
        $prospect = $this->route('prospect');

        return $prospect instanceof Prospect
            && ($this->user()?->can('handoff', $prospect) ?? false);
    }

    public function rules(): array
    {
        return [
            'plan' => [
                'required',
                Rule::in(['mensal', 'semestral', 'twelve_months', 'full_service', 'members_area']),
            ],
            'monthly_value' => ['nullable', 'numeric', 'min:0', 'max:9999999'],
            'start_date' => ['required', 'date'],
            'billing_day' => ['nullable', 'integer', 'between:1,31'],
            'artist_email' => ['nullable', 'email', 'max:255'],
            'whatsapp_number' => ['nullable', 'string', 'max:32'],
            'instagram_handle' => ['nullable', 'string', 'max:255'],
            'timezone' => ['nullable', 'string', 'max:64'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            if ($this->input('plan') === 'members_area') {
                return;
            }

            if (blank($this->input('artist_email'))) {
                $validator->errors()->add('artist_email', 'Informe o e-mail do artista.');
            }

            if ((float) $this->input('monthly_value') <= 0) {
                $validator->errors()->add('monthly_value', 'Informe o valor mensal.');
            }
        });
    }
}
