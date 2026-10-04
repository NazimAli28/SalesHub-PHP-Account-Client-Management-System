<?php

namespace Database\Seeders;

use App\Models\Team;
use App\Models\User;
use App\Models\Workstation;
use Illuminate\Database\Seeder;

class UserSeeder extends Seeder
{
    /**
     * 14 users: 1 admin, 2 support, 4 team leads, 7 sales executives (one inactive).
     * Every demo user signs in with the password "Demo@12345".
     */
    public function run(): void
    {
        $teams = Team::query()->orderBy('id')->get();
        $stations = Workstation::query()->pluck('id', 'code');

        User::factory()->admin()->create(['name' => 'Morgan Hale', 'username' => 'admin', 'email' => 'admin@example.com']);
        User::factory()->support()->create(['username' => 'support', 'email' => 'support@example.com']);
        User::factory()->support()->create(['username' => 'support2', 'email' => 'support2@example.com']);

        foreach ($teams as $index => $team) {
            $suffix = $index === 0 ? '' : (string) ($index + 1);
            $lead = User::factory()->teamLead()->create([
                'username' => 'tl'.$suffix,
                'email' => 'tl'.$suffix.'@example.com',
                'team_id' => $team->id,
            ]);
            $team->update(['team_lead_id' => $lead->id]);
        }

        // [username, team index, workstation code, active]
        $executives = [
            ['agent1', 0, 'PC-01', true],
            ['agent2', 0, 'PC-02', true],
            ['agent3', 1, 'PC-04', true],
            ['agent4', 1, 'PC-05', true],
            ['agent5', 2, 'PC-07', true],
            ['agent6', 2, 'PC-08', true],
            ['agent7', 3, 'PC-10', false],
        ];

        foreach ($executives as [$username, $teamIndex, $code, $active]) {
            $factory = User::factory()->salesExecutive();

            if (! $active) {
                $factory = $factory->inactive();
            }

            $factory->create([
                'username' => $username,
                'email' => $username.'@example.com',
                'team_id' => $teams[$teamIndex]->id,
                'workstation_id' => $stations[$code],
            ]);
        }
    }
}
