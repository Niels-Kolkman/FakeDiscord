<?php

namespace Database\Factories;

use App\Models\Message;
use App\Models\MessageAttachment;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * Metadata only: no file is written to the disk.
 *
 * @extends Factory<MessageAttachment>
 */
class MessageAttachmentFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->word().'.png';

        return [
            'message_id' => Message::factory(),
            'disk' => 'public',
            'path' => 'attachments/'.Str::uuid().'/'.$name,
            'original_name' => $name,
            'mime_type' => 'image/png',
            'size' => fake()->numberBetween(10_000, 5_000_000),
            'metadata' => [
                'width' => fake()->numberBetween(200, 1920),
                'height' => fake()->numberBetween(200, 1080),
            ],
        ];
    }

    /**
     * A non-image file without dimensions.
     */
    public function document(): static
    {
        return $this->state(function () {
            $name = fake()->word().'.pdf';

            return [
                'path' => 'attachments/'.Str::uuid().'/'.$name,
                'original_name' => $name,
                'mime_type' => 'application/pdf',
                'metadata' => null,
            ];
        });
    }
}
