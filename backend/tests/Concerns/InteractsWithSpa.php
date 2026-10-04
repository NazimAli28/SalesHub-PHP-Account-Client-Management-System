<?php

namespace Tests\Concerns;

use App\Enums\RoleName;
use App\Models\Team;
use App\Models\User;
use App\Models\Workstation;
use Illuminate\Testing\TestResponse;

/**
 * Drives the API the way the React SPA does: stateful origin headers, a cookie jar and the XSRF header.
 */
trait InteractsWithSpa
{
    /** @var array<string, string> */
    protected array $spaCookies = [];

    protected ?User $spaUser = null;

    /**
     * Authenticate the following SPA requests as this user without going through the login endpoint.
     */
    protected function spaAs(User $user): static
    {
        $this->spaUser = $user;

        return $this;
    }

    protected function spaOrigin(): string
    {
        return 'http://localhost:5173';
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, string>  $headers
     */
    protected function spa(string $method, string $uri, array $data = [], array $headers = []): TestResponse
    {
        // Each real HTTP request starts with fresh guards; the test kernel reuses one application.
        app('auth')->forgetGuards();

        if ($this->spaUser !== null) {
            app('auth')->guard('web')->setUser($this->spaUser);
        }

        $this->unencryptedCookies = [];
        $this->withUnencryptedCookies($this->spaCookies);
        // json() sends cookies only with credentials on, like fetch with credentials: "include".
        $this->withCredentials();

        if (isset($this->spaCookies['XSRF-TOKEN'])) {
            $headers['X-XSRF-TOKEN'] ??= $this->spaCookies['XSRF-TOKEN'];
        }

        $response = $this->withHeaders([
            'Origin' => $this->spaOrigin(),
            'Referer' => $this->spaOrigin().'/',
            'Accept' => 'application/json',
            ...$headers,
        ])->json($method, $uri, $data);

        foreach ($response->baseResponse->headers->getCookies() as $cookie) {
            if ($cookie->getValue() === null || $cookie->getValue() === '' || $cookie->getExpiresTime() < time() && $cookie->getExpiresTime() !== 0) {
                unset($this->spaCookies[$cookie->getName()]);
            } else {
                $this->spaCookies[$cookie->getName()] = $cookie->getValue();
            }
        }

        return $response;
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    protected function spaLogin(string $login, string $password = 'Demo@12345', array $overrides = []): TestResponse
    {
        return $this->spa('POST', '/api/auth/login', ['login' => $login, 'password' => $password, ...$overrides]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function makeUser(RoleName $role, array $attributes = []): User
    {
        $factory = match ($role) {
            RoleName::Admin => User::factory()->admin(),
            RoleName::Support => User::factory()->support(),
            RoleName::TeamLead => User::factory()->teamLead(),
            RoleName::SalesExecutive => User::factory()->salesExecutive(),
        };

        return $factory->create($attributes);
    }

    /**
     * A user placed on a team and (optionally) a workstation of that team.
     *
     * @param  array<string, mixed>  $attributes
     */
    protected function makeStaff(RoleName $role, Team $team, ?Workstation $station = null, array $attributes = []): User
    {
        return $this->makeUser($role, [
            'team_id' => $team->id,
            'workstation_id' => $station?->id,
            ...$attributes,
        ]);
    }
}
