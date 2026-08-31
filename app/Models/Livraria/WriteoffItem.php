<?php

namespace App\Models\Livraria;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Um exemplar dentro de uma baixa.
 *
 * `gera_custo` é SNAPSHOT do motivo: se amanhã alguém desmarcar o custo do
 * motivo "Sorteio", o acerto de um evento já fechado não pode mudar.
 */
class WriteoffItem extends Model
{
    protected $table = 'liv_writeoff_items';

    protected $connection = 'mysql';

    public $timestamps = false;

    protected $fillable = ['writeoff_id', 'copy_id', 'custo_unitario', 'gera_custo', 'cancelado_em'];

    protected $casts = [
        'custo_unitario' => 'decimal:2',
        'gera_custo'     => 'boolean',
        'cancelado_em'   => 'datetime',
    ];

    /** copy_ativo é coluna GERADA pelo banco: nunca gravar. */
    protected $guarded = ['copy_ativo'];

    public function writeoff(): BelongsTo
    {
        return $this->belongsTo(Writeoff::class, 'writeoff_id');
    }

    public function copy(): BelongsTo
    {
        return $this->belongsTo(Copy::class, 'copy_id');
    }

    public function scopeValidos($query)
    {
        return $query->whereNull('cancelado_em');
    }
}
