<?php

namespace App\Models\Livraria;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * P, M, G / Preta, Branca — UMA LINHA POR SALDO INDEPENDENTE.
 *
 * Atributo descreve o item; variação divide o estoque. Sem isto não dá para
 * responder "tem no M?" na mesa nem "o M esgotou e o GG voltou inteiro?" no
 * fechamento. Livro não tem nenhuma variação.
 */
class Variant extends Model
{
    protected $table = 'liv_variants';

    protected $connection = 'mysql';

    public $timestamps = false;

    protected $fillable = ['product_id', 'nome', 'ordem', 'ativo'];

    protected $casts = ['ativo' => 'boolean'];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    /**
     * Linhas de remessa que usam esta variação.
     *
     * Serve de trava no catálogo: apagar um tamanho que já está numa remessa
     * levaria junto o saldo daquele tamanho inteiro.
     */
    public function shipmentItems(): HasMany
    {
        return $this->hasMany(ShipmentItem::class, 'variant_id');
    }
}
