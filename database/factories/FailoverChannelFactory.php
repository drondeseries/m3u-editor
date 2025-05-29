<?php

namespace Database\Factories;

use App\Models\FailoverChannel;
use Illuminate\Database\Eloquent\Factories\Factory;

class FailoverChannelFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var string
     */
    protected $model = FailoverChannel::class;

    /**
     * Define the model's default state.
     *
     * @return array
     */
    public function definition()
    {
        return [
            'name' => $this->faker->name(),
            // Add other attributes here if needed
        ];
    }
}
