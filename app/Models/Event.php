<?php

namespace App\Models;

use App\Models\Concerns\BelongsToChurch;
use App\Models\Livraria\EventSetting;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * NÚCLEO. O simpósio, o retiro, o congresso. Os módulos penduram aqui.
 *
 * ⚠ Módulo NÃO acrescenta coluna a esta tabela. O que é da livraria mora em
 * liv_*, inclusive a meta financeira (EventSetting). Se cada módulo pudesse
 * pôr a sua coluna, `events` viraria depósito no segundo módulo.
 */
class Event extends Model
{
    use BelongsToChurch;

    protected $connection = 'mysql';

    protected $fillable = ['church_id', 'nome', 'local', 'inicio', 'fim', 'status'];

    protected $casts = ['inicio' => 'date', 'fim' => 'date'];

    // ── módulo livraria ──
    public function livrariaSettings(): HasOne
    {
        return $this->hasOne(EventSetting::class, 'event_id');
    }

    public function shipments(): HasMany
    {
        return $this->hasMany(Livraria\Shipment::class, 'event_id');
    }

    public function copies(): HasMany
    {
        return $this->hasMany(Livraria\Copy::class, 'event_id');
    }

    public function sales(): HasMany
    {
        return $this->hasMany(Livraria\Sale::class, 'event_id');
    }

    public function writeoffs(): HasMany
    {
        return $this->hasMany(Livraria\Writeoff::class, 'event_id');
    }

    public function costs(): HasMany
    {
        return $this->hasMany(Livraria\EventCost::class, 'event_id');
    }

    public function paymentMethods(): HasMany
    {
        return $this->hasMany(Livraria\PaymentMethod::class, 'event_id')->orderBy('ordem');
    }

    // ── módulo participantes ──

    public function participantesSettings(): HasOne
    {
        return $this->hasOne(Participantes\EventSetting::class, 'event_id');
    }

    public function dias(): HasMany
    {
        return $this->hasMany(Participantes\EventDay::class, 'event_id')
            ->orderBy('data')
            ->orderBy('id');
    }

    public function inscricoes(): HasMany
    {
        return $this->hasMany(Participantes\Registration::class, 'event_id');
    }

    /**
     * "Agora" na hora-de-parede da congregação DESTE evento.
     *
     * ⚠ Existe para que nenhuma escrita do módulo precise de now(). O
     * middleware SetChurchContext aplica o fuso da igreja só no caminho web —
     * comando de terminal e worker ficam em UTC, e três horas de diferença
     * corromperiam todo o registro de presença sem sintoma nenhum.
     */
    public function agora(): \Illuminate\Support\Carbon
    {
        return Church::agora($this->church_id);
    }

    public function scopeEmAndamento($query)
    {
        return $query->where('status', 'em_andamento');
    }

    /**
     * O evento que a sessão está olhando.
     *
     * Cai para o evento em andamento mais recente e, se não houver nenhum, para
     * o último cadastrado — e grava a escolha na sessão. Assim toda tela responde
     * igual: sem isto, cada uma resolvia "qual é o evento" do seu jeito e o
     * Estoque mostrava "nenhum evento ativo" no meio de um evento acontecendo.
     *
     * O ChurchScope já garante que só vem evento da congregação da sessão.
     */
    public static function atual(): ?self
    {
        if ($id = session('event_id')) {
            if ($evento = static::find($id)) {
                return $evento;
            }

            session()->forget('event_id');   // evento apagado ou de outra igreja
        }

        $evento = static::emAndamento()->orderByDesc('inicio')->first()
            ?? static::orderByDesc('inicio')->first();

        if ($evento) {
            session(['event_id' => $evento->id]);
        }

        return $evento;
    }

    public function getEncerradoAttribute(): bool
    {
        return $this->status === 'encerrado';
    }
}
