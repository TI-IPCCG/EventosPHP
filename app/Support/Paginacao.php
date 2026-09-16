<?php

namespace App\Support;

use Livewire\WithPagination;

/**
 * Paginação nas telas Livewire, com a view do Emerald Archive.
 *
 * ── POR QUE ESTE TRAIT EXISTE ─────────────────────────────────────────
 * Registrar `Paginator::defaultView()` no AppServiceProvider NÃO basta:
 * a cada render, o Livewire chama `Paginator::defaultView($this->paginationView())`
 * (SupportPagination.php:60) e sobrescreve o default do app pelo tema dele,
 * que é `livewire::tailwind`.
 *
 * O resultado, quando o projeto não tem Tailwind, é um paginador com as
 * classes utilitárias inertes: o SVG da seta sem `w-5 h-5` renderiza no
 * tamanho natural e ocupa meia tela, e `{{ __('pagination.previous') }}`
 * aparece cru porque não há tradução pt-BR publicada.
 *
 * O único gancho que o Livewire respeita é este método no componente.
 * Trait em vez de repetir nas telas: a próxima que paginar não precisa
 * redescobrir o problema.
 */
trait Paginacao
{
    use WithPagination;

    public function paginationView(): string
    {
        return 'vendor.pagination.emerald';
    }

    public function paginationSimpleView(): string
    {
        return 'vendor.pagination.emerald';
    }
}
