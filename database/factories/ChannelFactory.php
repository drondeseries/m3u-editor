<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use App\Models\Channel;
use App\Models\Group;
use App\Models\Playlist;
use App\Models\User;

class ChannelFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var string
     */
    protected $model = Channel::class;

    /**
     * Define the model's default state.
     */
    public function definition(): array
    {
        return [
            'name' => $this->faker->name(),
            'enabled' => $this->faker->boolean(),
            'channel' => $this->faker->randomNumber(),
            'shift' => $this->faker->randomNumber(),
            'url' => $this->faker->url(),
            'logo' => $this->faker->imageUrl(640, 480, 'cats', true, 'Faker'), // Generate a valid image URL
            'group' => $this->faker->word(),
            'stream_id' => $this->faker->word(),
            'lang' => $this->faker->word(),
            'country' => $this->faker->country(),
            'user_id' => User::factory(),
            'playlist_id' => Playlist::factory(),
            'group_id' => Group::factory(),
            'logo_type' => \App\Enums\ChannelLogoType::Channel, // Add default logo_type
        ];
    }
}
