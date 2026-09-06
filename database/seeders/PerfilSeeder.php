<?php

namespace Database\Seeders;

use App\Models\Church;
use App\Models\Permission;
use App\Models\SystemRole;
use Illuminate\Database\Seeder;

/**
 * Perfis de acesso iniciais, um conjunto por congregação.
 *
 * Antes isto só existia no 03-seed-referencia.sql, então quem instalava por
 * migration + db:seed terminava com permissões e NENHUM perfil — e sem perfil
 * ninguém enxerga nada além do Painel. Aqui e no SQL, os mesmos três.
 *
 * Idempotente: pode rodar de novo sem duplicar nem apagar ajuste manual. As
 * permissões são sincronizadas, então rodar depois de um módulo novo entrega
 * as permissões novas ao Administrador sem trabalho.
 */
class PerfilSeeder extends Seeder
{
    /** Perfil => slugs. O Administrador leva TODAS, inclusive as futuras. */
    public const PERFIS = [
        'Administrador' => '*',

        'Coordenador da Livraria' => [
            'eventos.ver', 'eventos.gerenciar', 'usuarios.ver', 'usuarios.gerenciar',
            'livraria.ver', 'livraria.catalogo', 'livraria.remessa',
            'livraria.vender', 'livraria.baixar', 'livraria.fechamento',
        ],

        'Operador de Mesa' => [
            'eventos.ver', 'livraria.ver', 'livraria.vender', 'livraria.baixar',
        ],
    ];

    public function run(): void
    {
        $todas = Permission::pluck('id', 'slug');

        // withoutGlobalScopes: SystemRole filtra pela igreja da SESSÃO, que no
        // terminal não existe — sem isto o seeder não acha o que já criou e
        // duplicaria os perfis a cada execução.
        foreach (Church::all() as $igreja) {
            foreach (self::PERFIS as $nome => $slugs) {
                $perfil = SystemRole::withoutGlobalScopes()->firstOrCreate(
                    ['church_id' => $igreja->id, 'name' => $nome],
                );

                $ids = $slugs === '*'
                    ? $todas->values()->all()
                    : $todas->only($slugs)->values()->all();

                $perfil->permissions()->sync($ids);
            }
        }
    }
}
