<?php

namespace App\Http\Requests\CustomerService;

use Illuminate\Foundation\Http\FormRequest;

class StoreTicketRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'onderwerp' => ['required', 'string', 'max:255'],
            'klant_naam' => ['required', 'string', 'max:255'],
            'klant_email' => ['required', 'email', 'max:255'],
            'prioriteit' => ['sometimes', 'in:laag,normaal,hoog,urgent'],
            'eerste_bericht' => ['required', 'string', 'max:10000'],
        ];
    }
}
