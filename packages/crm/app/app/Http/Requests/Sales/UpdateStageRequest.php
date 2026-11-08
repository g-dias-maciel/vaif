<?php

namespace App\Http\Requests\Sales;

use App\Models\Prospect;
use Illuminate\Foundation\Http\FormRequest;

class UpdateStageRequest extends FormRequest
{
    public function authorize(): bool
    {
        $prospect = $this->route('prospect');

        return $prospect instanceof Prospect
            && ($this->user()?->can('update', $prospect) ?? false);
    }

    public function rules(): array
    {
        return [
            'stage_key' => ['required', 'string', 'exists:prospect_stages,key'],
            'lost_reason' => ['nullable', 'string', 'max:255'],
            'note' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            $stage = $this->input('stage_key');

            if ($stage === 'perdido' && blank($this->input('lost_reason'))) {
                $validator->errors()->add('lost_reason', 'Informe o motivo da perda.');
            }

            if ($stage === 'ganho') {
                $validator->errors()->add(
                    'stage_key',
                    'O handoff ainda não está disponível. Mantenha o prospect em Proposta.'
                );
            }
        });
    }
}
