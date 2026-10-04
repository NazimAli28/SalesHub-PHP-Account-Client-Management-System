<?php

namespace Database\Seeders;

use App\Enums\ServiceCategory;
use App\Models\Service;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class ServiceSeeder extends Seeder
{
    public function run(): void
    {
        $services = [
            ['Logo', ServiceCategory::Branding, 8000, 'A custom channel logo with two revisions.'],
            ['Emote (each)', ServiceCategory::Emotes, 1500, 'A single custom emote in 3 sizes.'],
            ['Sub Badges Set', ServiceCategory::Emotes, 4500, 'A set of six loyalty badges.'],
            ['Stream Overlay', ServiceCategory::Overlays, 12000, 'Webcam frame and layout overlay.'],
            ['Full Stream Pack', ServiceCategory::Packages, 35000, 'Overlay, alerts, panels, screens and emotes.'],
            ['Panels', ServiceCategory::Branding, 4000, 'A matching set of channel panels.'],
            ['Alerts', ServiceCategory::Overlays, 6000, 'Follower, sub and donation alerts.'],
            ['Animated Emote', ServiceCategory::Animation, 3000, 'A single animated emote.'],
            ['Banner', ServiceCategory::Branding, 3500, 'A profile banner for one platform.'],
            ['Starting / BRB Screens', ServiceCategory::Overlays, 7000, 'Starting soon, BRB and ending screens.'],
            ['VTuber Model Sketch', ServiceCategory::Animation, 20000, 'Concept sketch for a VTuber model.'],
            ['Custom', ServiceCategory::Other, 0, 'Custom scope, priced per quote.'],
        ];

        foreach ($services as [$name, $category, $price, $description]) {
            Service::factory()->create([
                'name' => $name,
                'slug' => Str::slug($name),
                'category' => $category,
                'description' => $description,
                'base_price_cents' => $price,
            ]);
        }
    }
}
