<?php

namespace App\Http\Requests\CustomerService;

use Illuminate\Foundation\Http\FormRequest;

class UpdateTicketStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'status' => ['required', 'in:nieuw,in_behandeling,wachten_op_klant,afgehandeld'],
            'versie' => ['required', 'integer', 'min:1'],
        ];
    }
}
