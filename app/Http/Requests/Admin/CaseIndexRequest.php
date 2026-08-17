<?php

namespace App\Http\Requests\Admin;

use Illuminate\Validation\Rule;

class CaseIndexRequest extends AdminRequest
{
    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:100'],
            'court_id' => ['nullable', 'integer', 'exists:courts,id'],
            'region_id' => ['nullable', 'integer', 'exists:regions,id'],
            'status' => ['nullable', Rule::in(['active', 'resolved', 'unknown'])],
            'training_only' => ['nullable', 'boolean'],
            'from' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:to'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
