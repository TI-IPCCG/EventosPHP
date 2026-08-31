<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(PermissionSeeder::class);   // dado de código, todo ambiente
        $this->call(CatalogoSeeder::class);     // ponto de partida da congregação
    }
}
