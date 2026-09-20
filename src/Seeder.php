<?php

namespace Porter;

/**
 * Setup mock data for seeding by wrapping Faker.
 * @see https://fakerphp.org/#create-fake-data
 * @todo $testLocales = ['en_US', 'fr_BE', 'ja_JP', 'fa_IR', 'es_VE'];
 */
class Seeder
{
    public static function generate(array $seedmap, int $count, string $locale = 'en_US'): array
    {
        $faker = \Faker\Factory::create($locale);
        $data = [];
        for ($i = 0; $i < $count; $i++) {
            $data[] = array_map(function ($method) use ($faker) {
                return $faker->$method;
            }, $seedmap);
        }
        return $data;
    }
}
