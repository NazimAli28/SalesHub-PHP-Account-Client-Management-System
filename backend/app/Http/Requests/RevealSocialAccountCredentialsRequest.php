<?php

namespace App\Http\Requests;

class RevealSocialAccountCredentialsRequest extends RevealCredentialsRequest
{
    public function allowedFields(): array
    {
        return ['password'];
    }

    protected function routeParameter(): string
    {
        return 'socialAccount';
    }
}
