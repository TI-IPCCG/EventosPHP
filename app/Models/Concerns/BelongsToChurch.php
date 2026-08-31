<?php

namespace App\Models\Concerns;

use App\Models\Church;
use App\Models\Scopes\ChurchScope;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Aplica o isolamento multi-tenant a um model que possui church_id:
 *  - injeta o ChurchScope (filtra pela igreja da sessão);
 *  - ao criar, preenche church_id com o da sessão, para que o coordenador
 *    nunca consiga gravar registro em outra congregação nem por engano.
 *
 * church_id é vínculo LÓGICO cross-db: aponta para `churches`, que vive no
 * banco central. A relação abaixo cruza schemas — quem resolve é a conexão
 * declarada no model Church.
 */
trait BelongsToChurch
{
    protected static function bootBelongsToChurch(): void
    {
        static::addGlobalScope(new ChurchScope);

        static::creating(function ($model) {
            if (empty($model->church_id) && session()->has('church_id')) {
                $model->church_id = session('church_id');
            }
        });
    }

    public function church(): BelongsTo
    {
        return $this->belongsTo(Church::class, 'church_id');
    }
}
