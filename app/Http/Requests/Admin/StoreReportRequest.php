<?php

namespace App\Http\Requests\Admin;

use App\Enums\Admin\ReportType;
use Illuminate\Validation\Rule;

class StoreReportRequest extends AdminRequest
{
    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'type' => ['required', Rule::enum(ReportType::class)],
            'format' => ['required', Rule::in(['csv', 'jsonl', 'json'])],
            'from' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:to'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            'court_id' => ['nullable', 'integer', 'min:1', 'exists:courts,id'],
            'ids' => ['sometimes', 'array', 'max:100'],
            'ids.*' => ['integer', 'min:1', 'distinct', 'exists:case_instances,id'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
            'include_source_url' => ['sometimes', 'boolean'],
        ];
    }

    public function after(): array
    {
        return [function ($validator): void {
            $type = $this->string('type')->toString();

            if (in_array($type, [ReportType::Availability->value, ReportType::Dataset->value], true)) {
                if (! $this->filled('from') || ! $this->filled('to')) {
                    $validator->errors()->add('from', 'Для этого отчёта необходимо указать обе даты.');
                }

                if (! in_array($this->string('format')->toString(), ['csv', 'jsonl'], true)) {
                    $validator->errors()->add('format', 'Доступны форматы CSV и JSONL.');
                }
            }

            if ($type === ReportType::CaseInspection->value && $this->string('format')->toString() !== 'json') {
                $validator->errors()->add('format', 'Инспекция дел создаётся в формате JSON.');
            }
        }];
    }
}
