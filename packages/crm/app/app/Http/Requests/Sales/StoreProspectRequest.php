<?php

namespace App\Http\Requests\Sales;

use App\Models\Prospect;

class StoreProspectRequest extends ProspectRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Prospect::class) ?? false;
    }
}
