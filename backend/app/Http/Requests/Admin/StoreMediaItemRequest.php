<?php

namespace App\Http\Requests\Admin;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreMediaItemRequest extends FormRequest
{
    /**
     * The route itself already sits behind the `auth` middleware (see
     * routes/web.php) — no per-record ownership check is needed on top
     * of that for a single-tenant admin panel.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'type' => ['required', 'in:video,slide,ticker'],
            'title' => ['required', 'string', 'max:255'],
            'url' => ['nullable', 'url', 'max:2048', 'required_if:type,video,slide'],
            'body' => ['nullable', 'string', 'required_if:type,ticker'],
            'duration_seconds' => ['required', 'integer', 'min:0'],
            'sort_order' => ['required', 'integer', 'min:0'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after:starts_at'],
            'is_active' => ['required', 'boolean'],
        ];
    }
}
