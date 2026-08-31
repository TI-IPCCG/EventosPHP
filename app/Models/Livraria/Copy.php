<?php

namespace App\Models\Livraria;

use App\Models\Event;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * UM EXEMPLAR FÍSICO, com código único no evento.
 *
 * O saldo de qualquer item sai de um COUNT sobre esta tabela — não existe
 * campo de quantidade para desencontrar do que está na caixa. Como o exemplar
 * aponta para o shipment_item, ele já carrega a variação: a camiseta M e a GG
 * têm exemplares distintos.
 *
 * `status` é o estado atual e ÚNICO do exemplar.
 */
class Copy extends Model
{
    protected $table = 'liv_copies';

    protected $connection = 'mysql';

    protected $fillable = ['event_id', 'shipment_item_id', 'codigo', 'status'];

    public const DISPONIVEL = 'disponivel';
    public const RESERVADO  = 'reservado';
    public const VENDIDO    = 'vendido';
    public const BAIXADO    = 'baixado';
    public const DEVOLVIDO  = 'devolvido';

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class, 'event_id');
    }

    public function shipmentItem(): BelongsTo
    {
        return $this->belongsTo(ShipmentItem::class, 'shipment_item_id');
    }

    /** Reserva de carrinho, se houver. Não impede a venda — só informa. */
    public function hold(): HasOne
    {
        return $this->hasOne(CartHold::class, 'copy_id');
    }

    public function estaDisponivel(): bool
    {
        return $this->status === self::DISPONIVEL;
    }

    public function scopeDisponiveis($query)
    {
        return $query->where('status', self::DISPONIVEL);
    }

    public function scopeDoEvento($query, int $eventId)
    {
        return $query->where('event_id', $eventId);
    }
}
