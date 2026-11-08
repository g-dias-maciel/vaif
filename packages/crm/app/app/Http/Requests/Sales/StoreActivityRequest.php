<?php

namespace App\Http\Requests\Sales;

use App\Models\Prospect;
use Illuminate\Foundation\Http\FormRequest;

class StoreActivityRequest extends FormRequest
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
            'type' => ['required', 'in:call,whatsapp,note,task'],
            'title' => ['nullable', 'string', 'max:255'],
            'body' => ['nullable', 'string', 'max:5000'],
            'due_at' => ['nullable', 'date'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            $type = $this->input('type');

            if ($type === 'task' && blank($this->input('due_at'))) {
                $validator->errors()->add('due_at', 'Informe o prazo da tarefa.');
            }

            if ($type !== 'task' && blank($this->input('body')) && blank($this->input('title'))) {
                $validator->errors()->add('body', 'Escreva uma nota para registrar a atividade.');
            }
        });
    }
}
