<?php

namespace App\Http\Requests;

use App\Support\NormalizesPhilippineMobileNumber;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class RegisterRequest extends FormRequest
{
    use NormalizesPhilippineMobileNumber;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'first_name' => ['required', 'string', 'max:50'],
            'middle_name' => ['nullable', 'string', 'max:50'],
            'last_name' => ['required', 'string', 'max:50'],
            'birthdate' => ['nullable', 'date_format:Y-m-d', 'before:today'],
            'gender' => ['nullable', 'string', 'in:male,female'],
            'email' => ['required', 'email', 'max:255', 'unique:users'],
            'phone' => $this->mobileNumberRules(),
            'password' => ['required', 'confirmed', Password::defaults()],
        ];
    }
}
