<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class WatchFixtureRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'speed' => ['nullable', 'integer', 'min:1', 'max:3600'],
        ];
    }

    public function speed(): int
    {
        return (int) ($this->query('speed') ?? 60);
    }
}
