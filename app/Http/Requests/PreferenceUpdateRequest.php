<?php

namespace App\Http\Requests;

use App\Models\FurnitureProduct;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PreferenceUpdateRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if (! $this->has('budget_range')) {
            return;
        }

        $range = $this->input('budget_range');
        if (! is_string($range) || strlen($range) > 64 || preg_match('/\A\d+(?:\.\d{1,2})?-\d+(?:\.\d{1,2})?\z/', $range) !== 1) {
            return;
        }

        [$minimum, $maximum] = explode('-', $range);
        $this->merge(['budget_min' => $minimum, 'budget_max' => $maximum]);
    }

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $options = FurnitureProduct::catalogFilterOptions();
        $preference = $this->user()?->preference;
        $rangeRules = ['bail', 'required', 'string', 'max:64', 'regex:/\A\d+(?:\.\d{1,2})?-\d+(?:\.\d{1,2})?\z/'];
        $preferenceRules = [
            'furniture_type' => ['required', 'string', Rule::in(FurnitureProduct::recommendationTypes()->merge([$preference?->furniture_type])->all())],
            'budget_min' => ['bail', 'required', 'numeric', 'decimal:0,2', 'min:0', 'max:9999999999.99'],
            'budget_max' => ['bail', 'required', 'numeric', 'decimal:0,2', 'min:0', 'max:9999999999.99', 'gt:budget_min'],
            'style' => ['bail', 'nullable', 'string', 'max:255', Rule::in($options['styles']->merge([$preference?->style])->all())],
            'wood_type' => ['bail', 'nullable', 'string', 'max:255', Rule::in($options['woods']->merge([$preference?->wood_type])->all())],
            'varnish_finish' => ['bail', 'nullable', 'string', 'max:255', Rule::in($options['finishes']->merge([$preference?->varnish_finish])->all())],
            'room_width_m' => ['nullable', 'numeric', 'min:1', 'max:50', 'required_with:room_depth_m'],
            'room_depth_m' => ['nullable', 'numeric', 'min:1', 'max:50', 'required_with:room_width_m'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];

        if (! $this->has('budget_range')) {
            return $preferenceRules;
        }

        $range = $this->input('budget_range');
        if (! is_string($range) || strlen($range) > 64 || preg_match('/\A\d+(?:\.\d{1,2})?-\d+(?:\.\d{1,2})?\z/', $range) !== 1) {
            return ['budget_range' => $rangeRules];
        }

        return ['budget_range' => $rangeRules] + $preferenceRules;
    }
}
