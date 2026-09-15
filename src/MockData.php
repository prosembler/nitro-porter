<?php

namespace Porter;

use Faker\Generator;

/**
 * Setup mock data for seeding by wrapping Faker.
 * @see \Porter\Seed
 * @see https://fakerphp.org/#create-fake-data
 * @todo $testLocales = ['en_US', 'fr_BE', 'ja_JP', 'fa_IR', 'es_VE'];
 */
class MockData
{
    private Generator $faker;

    public function __construct(
        public array $seedmap, // @see Faker\Generator for valid values.
        public int $count,
        public string $locale = 'en_US',
    ) {
        $this->faker = \Faker\Factory::create($locale);
    }

    public function generate(): array
    {
        $data = [];
        for ($i = 0; $i < $this->count; $i++) {
            $data[] = array_map(function ($method) {
                return $this->faker->$method;
            }, $this->seedmap);
        }
        return $data;
    }
}
