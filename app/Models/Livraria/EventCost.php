<?php

namespace App\Models\Livraria;

use App\Models\Event;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Custo do evento — frete e o que mais aparecer. Genérico de propósito:
 * criar "estacionamento" é um INSERT, não desenvolvimento.
 *
 * ⚠ `rateio` NÃO afeta o resultado do evento: este soma o custo UMA vez,
 * pelo valor real. O rateio serve só à sugestão de preço de venda. Foi
 * exatamente confundir as duas coisas que fez a planilha antiga cobrar
 * R$ 994 de frete num evento que custou R$ 700.
 */
class EventCost extends Model
{
    protected $table = 'liv_event_costs';

    protected $connection = 'mysql';

    public $timestamps = false;

    protected $fillable = ['event_id', 'supplier_id', 'descricao', 'valor', 'rateio', 'created_at'];

    protected $casts = ['valor' => 'decimal:2', 'created_at' => 'datetime'];

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class, 'event_id');
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class, 'supplier_id');
    }
}
