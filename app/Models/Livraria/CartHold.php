<?php

namespace App\Models\Livraria;

use App\Models\Church;
use App\Models\Event;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Exemplar no carrinho de alguém agora.
 *
 * Não bloqueia venda: serve para a mesa enxergar que há outra venda em
 * andamento com o mesmo item. A trava de verdade continua sendo a de sempre
 * (índice único + gatilho + SELECT ... FOR UPDATE).
 */
class CartHold extends Model
{
    protected $table = 'liv_cart_holds';

    protected $connection = 'mysql';

    public $timestamps = false;

    protected $fillable = ['event_id', 'copy_id', 'user_id', 'criado_em', 'expira_em'];

    /**
     * ⚠ Estes horários são HORA-DE-PAREDE da congregação, não instantes UTC.
     * O cast rotula a string com o fuso do AMBIENTE ao ler, então
     * `$hold->expira_em->isFuture()` mente quando ambiente e congregação estão
     * em fusos diferentes. Para saber se a reserva vale, use o escopo
     * `vigentes()`, que compara no banco. (Dívida herdada do EscalaMembros:
     * migrar os horários para UTC canônico está registrado lá.)
     */
    protected $casts = ['criado_em' => 'datetime', 'expira_em' => 'datetime'];

    /** Quanto tempo um item fica reservado enquanto está no carrinho. */
    public const MINUTOS = 20;

    public function copy(): BelongsTo
    {
        return $this->belongsTo(Copy::class, 'copy_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    /**
     * "Agora" na hora-de-parede da congregação dona do evento.
     *
     * ⚠ Não use `now()` puro aqui. O middleware SetChurchContext aplica o fuso
     * da igreja na requisição web, mas comando de terminal não tem sessão e
     * fica em UTC — 3 horas de diferença. Uma reserva gravada pela tela
     * pareceria expirada para qualquer comando. O fuso vem da congregação,
     * nunca do ambiente.
     */
    public static function agoraDo(?int $eventId = null): \Illuminate\Support\Carbon
    {
        static $memo = [];

        if (! $eventId) {
            return Church::agora();
        }

        $churchId = $memo[$eventId] ??= Event::withoutGlobalScopes()
            ->whereKey($eventId)->value('church_id');

        return Church::agora($churchId);
    }

    /**
     * Reservas ainda válidas.
     *
     * A expiração é resolvida no FILTRO, não por rotina de limpeza: sem cron,
     * um carrinho abandonado deixaria o exemplar preso para sempre.
     */
    public function scopeVigentes(Builder $q, ?int $eventId = null): Builder
    {
        return $q->where('expira_em', '>', static::agoraDo($eventId));
    }

    /**
     * Reserva o exemplar — desde que ninguém o tenha reservado antes.
     *
     * Renova a própria reserva e assume uma expirada. Reserva VIGENTE de outra
     * pessoa é preservada: o segundo voluntário ainda pode pôr o exemplar no
     * carrinho (com aviso), mas roubar a reserva faria o primeiro perder o
     * sinal sem nunca saber.
     *
     * @return bool  se a reserva ficou com esta pessoa
     */
    public static function reservarSeLivre(int $eventId, int $copyId, int $userId): bool
    {
        $existente = static::where('copy_id', $copyId)->first();

        if ($existente
            && $existente->user_id !== $userId
            && static::whereKey($existente->id)->vigentes($eventId)->exists()) {
            return false;
        }

        $agora = static::agoraDo($eventId);

        static::updateOrCreate(
            ['copy_id' => $copyId],
            [
                'event_id'  => $eventId,
                'user_id'   => $userId,
                'criado_em' => $agora,
                'expira_em' => $agora->copy()->addMinutes(self::MINUTOS),
            ],
        );

        return true;
    }

    /**
     * Solta as reservas DESTA pessoa — ao remover do carrinho, limpar ou
     * concluir. Filtra por user_id de propósito: sem isso, esvaziar o próprio
     * carrinho apagaria a reserva de quem realmente a detém.
     */
    public static function soltar(array $copyIds, ?int $userId = null): void
    {
        if (! $copyIds) {
            return;
        }

        static::whereIn('copy_id', $copyIds)
            ->where('user_id', $userId ?? auth()->id())
            ->delete();
    }
}
