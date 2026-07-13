<?php

namespace App\Http\Requests\CustomerService;

use Illuminate\Foundation\Http\FormRequest;

class StoreTicketMessageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'richting' => ['required', 'in:inkomend,uitgaand'],
            'inhoud' => ['required', 'string', 'max:10000'],
            'client_message_id' => ['required', 'uuid'],
            'versie' => ['required', 'integer', 'min:1'],
        ];
    }
}
