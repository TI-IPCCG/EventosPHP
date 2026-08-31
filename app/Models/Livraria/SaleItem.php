<?php

namespace App\Models\Livraria;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Um exemplar dentro de uma venda.
 *
 * `preco` e `custo_unitario` são SNAPSHOT: o acerto do fornecedor usa o custo
 * que valia no dia, não o preço de tabela de hoje.
 *
 * `cancelado_em` espelha o da venda — não é redundância à toa: a coluna gerada
 * copy_ativo depende dele para liberar o exemplar quando a venda é estornada.
 */
class SaleItem extends Model
{
    protected $table = 'liv_sale_items';

    protected $connection = 'mysql';

    public $timestamps = false;

    protected $fillable = ['sale_id', 'copy_id', 'preco', 'custo_unitario', 'cancelado_em'];

    protected $casts = [
        'preco'          => 'decimal:2',
        'custo_unitario' => 'decimal:2',
        'cancelado_em'   => 'datetime',
    ];

    /** copy_ativo é coluna GERADA pelo banco: nunca gravar. */
    protected $guarded = ['copy_ativo'];

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class, 'sale_id');
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
