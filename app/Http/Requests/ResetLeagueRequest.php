<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class ResetLeagueRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'seed' => ['nullable', 'string', 'max:128'],
        ];
    }

    public function seed(): ?string
    {
        $value = $this->input('seed');

        return $value === null || $value === '' ? null : (string) $value;
    }
}
