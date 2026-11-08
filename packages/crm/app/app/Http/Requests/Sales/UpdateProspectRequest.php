<?php

namespace App\Http\Requests\Sales;

use App\Models\Prospect;

class UpdateProspectRequest extends ProspectRequest
{
    public function authorize(): bool
    {
        $prospect = $this->route('prospect');

        return $prospect instanceof Prospect
            && ($this->user()?->can('update', $prospect) ?? false);
    }
}
