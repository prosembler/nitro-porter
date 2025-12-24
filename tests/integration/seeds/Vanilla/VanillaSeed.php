<?php

use Phinx\Seed\AbstractSeed;

class VanillaSeed extends AbstractSeed
{
    public function getDependencies(): array
    {
        return [
            // 'UserSeeder',
        ];
    }

    /**
     * Write your database seeder using this method.
     *
     * More information on writing seeders is available here:
     * https://book.cakephp.org/phinx/0/en/seeding.html
     */
    public function run(): void
    {
        // Clear users.
        $this->table('GDN_User')->truncate();

        // Users, fixed.
        $data = [
            [
                'Name' => 'Linc',
                'Email' => 'lincoln@icrontic.com',
                'Password' => 'admin',
            ]
        ];
        $this->table('GDN_User')->insert($data)->saveData();

        // Users, random.
        $faker = Faker\Factory::create();
        $data = [];
        for ($i = 0; $i < 20; $i++) {
            $data[] = [
                'Name' => $faker->userName,
                'Password' => sha1($faker->password),
                'Email' => $faker->email,
            ];
        }
        $this->table('GDN_User')->insert($data)->saveData();
    }
}
