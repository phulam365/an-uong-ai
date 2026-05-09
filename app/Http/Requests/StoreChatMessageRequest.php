<?php

namespace App\Http\Requests;

use App\MenuFilterDefinitions;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreChatMessageRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'message' => ['required', 'string', 'max:1000'],
            'cart' => ['array', 'max:100'],
            'cart.*.food_id' => ['required', 'integer', 'exists:foods,id'],
            'cart.*.quantity' => ['required', 'integer', 'min:1', 'max:99'],
            'filter_context' => ['sometimes', 'array'],
            'filter_context.category' => ['nullable', 'string', Rule::in(MenuFilterDefinitions::categoryKeys())],
            'filter_context.property_keys' => ['sometimes', 'array', 'max:15'],
            'filter_context.property_keys.*' => ['string', Rule::in(MenuFilterDefinitions::propertyKeys())],
        ];
    }
}
