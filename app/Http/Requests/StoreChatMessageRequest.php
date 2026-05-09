<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

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
            'language' => ['sometimes', 'string', 'in:vi,en'],
            'cart' => ['array', 'max:100'],
            'cart.*.food_id' => ['required', 'integer', 'exists:foods,id'],
            'cart.*.quantity' => ['required', 'integer', 'min:1', 'max:99'],
        ];
    }
}
