<?php

namespace App\Models\Livraria;

use App\Models\Event;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Forma de pagamento e sua taxa, POR EVENTO: a maquininha muda de contrato
 * entre um ano e outro, e o resultado do evento antigo precisa continuar
 * batendo.
 */
class PaymentMethod extends Model
{
    protected $table = 'liv_payment_methods';

    protected $connection = 'mysql';

    public $timestamps = false;

    protected $fillable = ['event_id', 'nome', 'taxa_percentual', 'ordem', 'ativo'];

    protected $casts = ['taxa_percentual' => 'decimal:2', 'ativo' => 'boolean'];

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class, 'event_id');
    }

    /** A taxa incide sobre o valor da TRANSAÇÃO, não sobre cada item. */
    public function taxaSobre(string|float $valor): float
    {
        return round((float) $valor * ((float) $this->taxa_percentual / 100), 2);
    }
}
