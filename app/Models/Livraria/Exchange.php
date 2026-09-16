<?php

namespace App\Models\Livraria;

use App\Models\Event;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A troca de itens de uma venda já registrada.
 *
 * A venda original não é tocada: `liv_sales.valor_bruto` é o que passou no
 * meio de pagamento e tem de continuar batendo com o extrato. O que esta
 * linha guarda é o movimento NOVO — o troco que saiu do caixa ou o valor
 * cobrado a mais — com forma de pagamento e taxa próprias.
 *
 * `diferenca` tem sinal: positivo cobrou, negativo devolveu, zero saiu par.
 */
class Exchange extends Model
{
    protected $table = 'liv_exchanges';

    protected $connection = 'mysql';

    public $timestamps = false;

    protected $fillable = [
        'sale_id', 'event_id', 'payment_method_id', 'diferenca',
        'taxa_percentual', 'taxa_valor', 'motivo', 'registrado_por',
        'realizada_em', 'created_at',
    ];

    protected $casts = [
        'diferenca'       => 'decimal:2',
        'taxa_percentual' => 'decimal:2',
        'taxa_valor'      => 'decimal:2',
        'realizada_em'    => 'datetime',
        'created_at'      => 'datetime',
    ];

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class, 'sale_id');
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class, 'event_id');
    }

    public function paymentMethod(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class, 'payment_method_id');
    }

    public function registradoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'registrado_por');
    }

    /** O que entrou na venda por esta troca. */
    public function itensEntrada(): HasMany
    {
        return $this->hasMany(SaleItem::class, 'exchange_in_id');
    }

    /** O que saiu da venda por esta troca. */
    public function itensSaida(): HasMany
    {
        return $this->hasMany(SaleItem::class, 'exchange_out_id');
    }

    public function cobrou(): bool
    {
        return (float) $this->diferenca > 0;
    }

    public function devolveu(): bool
    {
        return (float) $this->diferenca < 0;
    }

    /** Quanto de dinheiro trocou de mãos, sem sinal — para exibir. */
    public function valorMovimentado(): float
    {
        return abs((float) $this->diferenca);
    }
}
