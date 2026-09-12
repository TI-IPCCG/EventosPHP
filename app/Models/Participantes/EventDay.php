<?php

namespace App\Models\Participantes;

use App\Models\Church;
use App\Models\Event;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Um dia do evento. O dia divide a presença como a variação divide o estoque:
 * cada um tem contagem própria e é referenciado pelo check-in.
 */
class EventDay extends Model
{
    protected $table = 'par_event_days';

    protected $connection = 'mysql';

    public $timestamps = false;

    protected $fillable = ['event_id', 'data', 'nome', 'ativo', 'created_at'];

    protected $casts = [
        'data'       => 'date',
        'ativo'      => 'boolean',
        'created_at' => 'datetime',
    ];

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class, 'event_id');
    }

    public function checkins(): HasMany
    {
        return $this->hasMany(Checkin::class, 'event_day_id');
    }

    /** "Sábado — manhã", ou a data quando ninguém nomeou. */
    public function rotulo(): string
    {
        return $this->nome ?: $this->data->format('d/m/Y');
    }

    /**
     * O dia de hoje NESTE evento.
     *
     * ⚠ Compara com a hora-de-parede da CONGREGAÇÃO, não com today(). Perto da
     * meia-noite, e em qualquer chamada fora da web (comando de terminal fica em
     * UTC), `today()` devolve o dia errado — e o check-in cairia no dia errado
     * sem ninguém notar.
     */
    public static function deHoje(int $eventId): ?self
    {
        $churchId = Event::withoutGlobalScopes()->whereKey($eventId)->value('church_id');

        return static::where('event_id', $eventId)
            ->where('ativo', true)
            ->whereDate('data', Church::agora($churchId)->toDateString())
            ->first();
    }

    public function scopeAtivos($query)
    {
        return $query->where('ativo', true)->orderBy('data')->orderBy('id');
    }
}
