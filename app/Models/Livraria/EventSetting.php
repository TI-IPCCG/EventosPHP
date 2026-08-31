<?php

namespace App\Models\Livraria;

use App\Models\Event;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** O que a livraria acrescenta a um evento. Uma linha por evento. */
class EventSetting extends Model
{
    protected $table = 'liv_event_settings';

    protected $connection = 'mysql';

    protected $primaryKey = 'event_id';

    public $incrementing = false;

    protected $fillable = ['event_id', 'meta_tipo', 'meta_valor', 'observacao'];

    protected $casts = ['meta_valor' => 'decimal:2'];

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class, 'event_id');
    }

    public function isZeroAZero(): bool
    {
        return $this->meta_tipo === 'zero_a_zero';
    }
}
