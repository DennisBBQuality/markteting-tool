<?php

namespace App\Http\Requests\CustomerService;

use Illuminate\Foundation\Http\FormRequest;

class UpdateTicketPriorityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'prioriteit' => ['required', 'in:laag,normaal,hoog,urgent'],
            'versie' => ['required', 'integer', 'min:1'],
        ];
    }
}
