<?php

namespace App\Models\Participantes;

use App\Models\Event;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * O que o módulo acrescenta a um evento — fora de `events`, porque núcleo não
 * recebe coluna de módulo. Espelha Livraria\EventSetting.
 */
class EventSetting extends Model
{
    protected $table = 'par_event_settings';

    protected $connection = 'mysql';

    protected $primaryKey = 'event_id';

    public $incrementing = false;

    protected $fillable = [
        'event_id', 'form_id', 'slug_publico', 'prefixo_codigo', 'vagas',
        'inscricoes_de', 'inscricoes_ate', 'texto_confirmacao', 'observacao',
    ];

    protected $casts = [
        'inscricoes_de'  => 'datetime',
        'inscricoes_ate' => 'datetime',
    ];

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class, 'event_id');
    }

    public function form(): BelongsTo
    {
        return $this->belongsTo(Form::class, 'form_id');
    }
}
