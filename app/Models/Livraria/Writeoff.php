<?php

namespace App\Models\Livraria;

use App\Models\Event;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Baixa sem venda: sorteio, cortesia, doação, perda.
 *
 * Cabeçalho + itens pelo mesmo motivo da venda: sortear três livros de uma vez
 * informa motivo e autorização UMA vez, não três.
 *
 * `autorizado_por` é texto livre porque quem autoriza nem sempre tem login —
 * pode ser o preletor ou o pastor. A regra pede o registro, não o usuário.
 */
class Writeoff extends Model
{
    protected $table = 'liv_writeoffs';

    protected $connection = 'mysql';

    public $timestamps = false;

    protected $fillable = [
        'event_id', 'reason_id', 'autorizado_por', 'registrado_por',
        'observacao', 'registrada_em', 'created_at',
        // idem Sale: sem isto, cancelar a baixa não marca o cabeçalho.
        'cancelada_em', 'cancelada_por',
    ];

    protected $casts = [
        'registrada_em' => 'datetime',
        'cancelada_em'  => 'datetime',
        'created_at'    => 'datetime',
    ];

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class, 'event_id');
    }

    public function reason(): BelongsTo
    {
        return $this->belongsTo(WriteoffReason::class, 'reason_id');
    }

    public function registradoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'registrado_por');
    }

    public function items(): HasMany
    {
        return $this->hasMany(WriteoffItem::class, 'writeoff_id');
    }

    public function foiCancelada(): bool
    {
        return $this->cancelada_em !== null;
    }

    public function scopeValidas($query)
    {
        return $query->whereNull('cancelada_em');
    }
}
