<?php

namespace App\Models\Livraria;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Quanto de cada item veio, e a que preço. Uma linha por (item, variação):
 * livro tem variant_id NULL; camiseta P/M/G tem três linhas, três saldos.
 *
 * custo_unitario e preco_venda são SNAPSHOT do evento.
 */
class ShipmentItem extends Model
{
    protected $table = 'liv_shipment_items';

    protected $connection = 'mysql';

    public $timestamps = false;

    protected $fillable = ['shipment_id', 'product_id', 'variant_id', 'quantidade', 'custo_unitario', 'preco_venda'];

    protected $casts = ['custo_unitario' => 'decimal:2', 'preco_venda' => 'decimal:2'];

    /** variant_key é coluna GERADA pelo banco: nunca gravar. */
    protected $guarded = ['variant_key'];

    public function shipment(): BelongsTo
    {
        return $this->belongsTo(Shipment::class, 'shipment_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(Variant::class, 'variant_id');
    }

    public function copies(): HasMany
    {
        return $this->hasMany(Copy::class, 'shipment_item_id');
    }

    /** "Aquietai-vos" ou "Camiseta Simpósio 2026 · M". */
    public function rotulo(): string
    {
        return $this->product->nome.($this->variant ? ' · '.$this->variant->nome : '');
    }
}
