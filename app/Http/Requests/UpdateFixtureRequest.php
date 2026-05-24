<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class UpdateFixtureRequest extends FormRequest
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
            'home_goals' => ['required', 'integer', 'min:0', 'max:99'],
            'away_goals' => ['required', 'integer', 'min:0', 'max:99'],
        ];
    }

    public function homeGoals(): int
    {
        return (int) $this->input('home_goals');
    }

    public function awayGoals(): int
    {
        return (int) $this->input('away_goals');
    }
}
