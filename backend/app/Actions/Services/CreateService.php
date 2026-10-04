<?php

namespace App\Actions\Services;

use App\Models\Service;
use Illuminate\Support\Str;

class CreateService
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function handle(array $attributes): Service
    {
        $attributes['slug'] ??= Str::slug((string) $attributes['name']);

        return Service::query()->create($attributes)->refresh();
    }
}
