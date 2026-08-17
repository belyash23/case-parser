<?php

namespace App\Http\Requests\Admin;

class StartInitialCrawlRequest extends AdminRequest
{
    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'from' => ['required', 'date_format:Y-m-d', 'before_or_equal:to'],
            'to' => ['required', 'date_format:Y-m-d'],
            'court_ids' => ['sometimes', 'array', 'max:5000'],
            'court_ids.*' => ['integer', 'min:1', 'distinct', 'exists:courts,id'],
            'region_ids' => ['sometimes', 'array', 'max:100'],
            'region_ids.*' => ['integer', 'min:1', 'distinct', 'exists:regions,sudrf_region_id'],
            'skip_directory_sync' => ['sometimes', 'boolean'],
        ];
    }
}
