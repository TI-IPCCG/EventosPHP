<?php

namespace App\Models\Livraria;

use App\Models\Event;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Remessa de um fornecedor para um evento. */
class Shipment extends Model
{
    protected $table = 'liv_shipments';

    protected $connection = 'mysql';

    public $timestamps = false;

    protected $fillable = ['event_id', 'supplier_id', 'condicao', 'observacao', 'created_at'];

    protected $casts = ['created_at' => 'datetime'];

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class, 'event_id');
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class, 'supplier_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(ShipmentItem::class, 'shipment_id');
    }

    /** Consignado: o que não vende volta e não é pago. */
    public function isConsignado(): bool
    {
        return $this->condicao === 'consignado';
    }
}
