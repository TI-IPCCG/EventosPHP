<?php

namespace App\Models\Participantes;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A entrada de uma inscrição num dia.
 *
 * ⚠ `registrado_em` vem de Event::agora(), nunca de now(): o middleware aplica
 * o fuso da congregação só no caminho web, e comando de terminal fica em UTC.
 * Três horas de erro corromperiam todo o registro de presença sem sintoma.
 */
class Checkin extends Model
{
    protected $table = 'par_checkins';

    protected $connection = 'mysql';

    public $timestamps = false;

    protected $fillable = [
        'registration_id', 'event_day_id', 'canal', 'registrado_em',
        'registrado_por', 'cancelado_em', 'cancelado_por', 'observacao', 'created_at',
    ];

    protected $casts = [
        'registrado_em' => 'datetime',
        'cancelado_em'  => 'datetime',
        'created_at'    => 'datetime',
    ];

    public function registration(): BelongsTo
    {
        return $this->belongsTo(Registration::class, 'registration_id');
    }

    public function day(): BelongsTo
    {
        return $this->belongsTo(EventDay::class, 'event_day_id');
    }

    public function operador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'registrado_por');
    }

    public function scopeVigentes($query)
    {
        return $query->whereNull('cancelado_em');
    }
}
