<?php

namespace App\Http\Requests\CustomerService;

use Illuminate\Foundation\Http\FormRequest;

class StoreTicketNoteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'inhoud' => ['required', 'string', 'max:10000'],
            'versie' => ['required', 'integer', 'min:1'],
        ];
    }
}
