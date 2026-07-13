<?php

namespace App\Http\Requests\CustomerService;

use Illuminate\Foundation\Http\FormRequest;

class IndexTicketRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'status' => ['sometimes', 'in:nieuw,in_behandeling,wachten_op_klant,afgehandeld,alle'],
            'prioriteit' => ['sometimes', 'in:laag,normaal,hoog,urgent'],
            'behandelaar_id' => ['sometimes', 'uuid'],
            'niet_toegewezen' => ['sometimes', 'boolean'],
            'zoek' => ['sometimes', 'string', 'max:255'],
            'sorteer' => ['sometimes', 'in:laatste_bericht,aangemaakt,prioriteit'],
            'richting' => ['sometimes', 'in:asc,desc'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }
}
