<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class PredictionQueryRequest extends FormRequest
{
    public const DEFAULT_ITERATIONS = 10_000;

    public const MAX_ITERATIONS = 100_000;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<string|int>>
     */
    public function rules(): array
    {
        return [
            'iterations' => ['nullable', 'integer', 'min:1', 'max:'.self::MAX_ITERATIONS],
            'seed' => ['nullable', 'string', 'max:128'],
        ];
    }

    public function iterations(): int
    {
        $value = $this->input('iterations');

        return $value === null || $value === '' ? self::DEFAULT_ITERATIONS : (int) $value;
    }

    public function seed(): ?string
    {
        $value = $this->input('seed');

        return $value === null || $value === '' ? null : (string) $value;
    }
}
