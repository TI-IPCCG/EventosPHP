<?php

namespace App\Models\Participantes;

use App\Models\Event;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A inscrição no evento. O participante não é `users` — ele é esta linha.
 *
 * ⚠ `nome`, `email`, `telefone` e `cpf` são SNAPSHOT: o que foi declarado
 * NESTE evento. Nunca recarregue de Person — corrigir o telefone hoje não pode
 * mexer na lista de presença de um evento encerrado.
 *
 * ⚠ Todo envio usa ESTE `email`, não o de Person.
 */
class Registration extends Model
{
    public const CONFIRMADA   = 'confirmada';
    public const LISTA_ESPERA = 'lista_espera';
    public const CANCELADA    = 'cancelada';

    protected $table = 'par_registrations';

    protected $connection = 'mysql';

    protected $fillable = [
        'event_id', 'person_id', 'codigo', 'token', 'status', 'origem',
        'nome', 'email', 'telefone', 'cpf', 'respostas',
        'conflito_identidade', 'email_valido', 'inscrita_em',
        'qr_enviado_em', 'qr_envios', 'qr_reservado_em', 'qr_erro',
        'registrada_por', 'cancelada_em', 'cancelada_por', 'observacao',
    ];

    protected $casts = [
        'respostas'           => 'array',
        'conflito_identidade' => 'boolean',
        'email_valido'        => 'boolean',
        'inscrita_em'         => 'datetime',
        'qr_enviado_em'       => 'datetime',
        'qr_reservado_em'     => 'datetime',
        'cancelada_em'        => 'datetime',
    ];

    public function setEmailAttribute($valor): void
    {
        $this->attributes['email'] = mb_strtolower(trim((string) $valor)) ?: null;
    }

    public function setCpfAttribute($valor): void
    {
        $this->attributes['cpf'] = preg_replace('/\D/', '', (string) $valor) ?: null;
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class, 'event_id');
    }

    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class, 'person_id');
    }

    public function checkins(): HasMany
    {
        return $this->hasMany(Checkin::class, 'registration_id');
    }

    /** Uma resposta do formulário, congelada no dia da inscrição. */
    public function resposta(string $chave, mixed $padrao = null): mixed
    {
        return data_get($this->respostas, $chave, $padrao);
    }

    public function getCanceladaAttribute(): bool
    {
        return $this->status === self::CANCELADA;
    }

    /** Já entrou neste dia? (check-in não cancelado) */
    public function presenteEm(int $eventDayId): bool
    {
        return $this->checkins()
            ->where('event_day_id', $eventDayId)
            ->whereNull('cancelado_em')
            ->exists();
    }

    public function scopeAtivas($query)
    {
        return $query->where('status', '!=', self::CANCELADA);
    }
}
