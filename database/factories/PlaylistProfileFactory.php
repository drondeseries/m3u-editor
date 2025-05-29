<?php

namespace Database\Factories;

use App\Models\Playlist;
use App\Models\PlaylistProfile;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class PlaylistProfileFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var string
     */
    protected $model = PlaylistProfile::class;

    /**
     * Define the model's default state.
     *
     * @return array
     */
    public function definition()
    {
        return [
            'id' => Str::uuid(), // Assuming the model uses HasUuids
            'playlist_id' => Playlist::factory(), // Associate with a Playlist
            'name' => $this->faker->words(2, true),
            'max_streams' => $this->faker->numberBetween(1, 5),
            'is_default' => false,
            'is_active' => true,
        ];
    }

    /**
     * Indicate that the profile is the default.
     *
     * @return \Illuminate\Database\Eloquent\Factories\Factory
     */
    public function isDefault()
    {
        return $this->state(function (array $attributes) {
            return [
                'is_default' => true,
            ];
        });
    }

    /**
     * Indicate that the profile is inactive.
     *
     * @return \Illuminate\Database\Eloquent\Factories\Factory
     */
    public function inactive()
    {
        return $this->state(function (array $attributes) {
            return [
                'is_active' => false,
            ];
        });
    }
}
