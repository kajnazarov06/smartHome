<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\DeviceType;
use App\Models\Device;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Device>
 */
class DeviceFactory extends Factory
{
    public function definition(): array
    {
        $suffix = strtoupper(Str::random(4));

        return [
            'id' => 'clock-'.strtolower($suffix),
            'user_id' => User::factory(),
            'type' => DeviceType::Clock,
            'name' => 'Clock '.$suffix,
            'token' => Str::random(64),
            'last_ip' => fake()->localIpv4(),
            'fw_version' => '1.0.0',
            'mac' => fake()->macAddress(),
            'capabilities' => ['temperature', 'humidity', 'alarm', 'clock'],
            'settings' => null,
            'last_seen_at' => now(),
        ];
    }

    public function offline(): static
    {
        return $this->state(['last_seen_at' => now()->subHour()]);
    }
}
