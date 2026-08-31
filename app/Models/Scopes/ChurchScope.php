<?php

namespace App\Models\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Isolamento multi-tenant. Injeta `WHERE church_id = X` em toda query das
 * tabelas que têm church_id, lendo o X gravado na sessão no login.
 *
 * Sem sessão, church_id fica NULL → `IS NULL` → zero linhas (as colunas são
 * NOT NULL). Assim nada vaza entre congregações por acidente: o pior caso é
 * não ver nada, nunca ver o que é de outra igreja.
 *
 * No login, a consulta ao usuário precisa driblar este escopo com
 * ->withoutGlobalScopes(), porque o church_id ainda não está na sessão.
 */
class ChurchScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $builder->where($model->getTable().'.church_id', session('church_id'));
    }
}
