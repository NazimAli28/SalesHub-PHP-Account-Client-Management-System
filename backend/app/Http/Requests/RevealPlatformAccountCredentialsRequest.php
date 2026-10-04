<?php

namespace App\Http\Requests;

class RevealPlatformAccountCredentialsRequest extends RevealCredentialsRequest
{
    public function allowedFields(): array
    {
        return ['email_password', 'discord_password', 'recovery_phone', 'phone_holder_name'];
    }

    protected function routeParameter(): string
    {
        return 'platformAccount';
    }
}
