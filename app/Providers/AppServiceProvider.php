<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        /*
         | Ability = slug da permissão. Com isto, @can('livraria.vender') e o
         | middleware can:livraria.vender funcionam sem precisar declarar um
         | Gate por permissão — cada módulo novo só acrescenta slugs.
         |
         | ⚠ Devolve `?: null` e não `false`: retornar false aqui ABORTARIA a
         | checagem e mataria os gates definidos abaixo. Devolvendo null quando
         | não tem a permissão, o Laravel segue procurando.
         */
        Gate::before(function (User $user, string $ability) {
            if ($user->is_super) {
                return true;
            }

            return $user->hasPermission($ability) ?: null;
        });

        Gate::define('super-admin', fn (User $user) => $user->is_super);

        /*
         | Gates compostos: quem gerencia o catálogo ou a remessa também precisa
         | enxergar a livraria, e "permissão A não implica permissão B" é um
         | footgun conhecido — sem isto, quem tem só `livraria.remessa` tomaria
         | 403 na própria tela que acabou de montar.
         */
        Gate::define('ver-livraria', fn (User $user) => $user->hasPermission('livraria.ver')
            || $user->hasPermission('livraria.catalogo')
            || $user->hasPermission('livraria.remessa')
            || $user->hasPermission('livraria.vender')
            || $user->hasPermission('livraria.fechamento'));

        Gate::define('operar-mesa', fn (User $user) => $user->hasPermission('livraria.vender')
            || $user->hasPermission('livraria.baixar'));

        /*
         | Mesmo footgun do ver-livraria: o voluntário da portaria tem só
         | `participantes.checkin` e tomaria 403 na tela que precisa operar se a
         | rota exigisse `participantes.ver`.
         */
        Gate::define('ver-participantes', fn (User $user) => $user->hasPermission('participantes.ver')
            || $user->hasPermission('participantes.checkin')
            || $user->hasPermission('participantes.gerenciar')
            || $user->hasPermission('participantes.importar')
            || $user->hasPermission('participantes.enviar'));
    }
}
