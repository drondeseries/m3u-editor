<?php

namespace Database\Factories;

use App\Models\MergedChannel;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class MergedChannelFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var string
     */
    protected $model = MergedChannel::class;

    /**
     * Define the model's default state.
     *
     * @return array
     */
    public function definition()
    {
        return [
            'name' => $this->faker->unique()->words(3, true),
            'user_id' => User::factory(),
            // Add other fields if necessary, e.g., epg_channel_id
            // 'epg_channel_id' => null, 
        ];
    }
}
