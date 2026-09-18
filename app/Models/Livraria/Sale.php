<?php

namespace App\Models\Livraria;

use App\Models\Event;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A TRANSAÇÃO. Uma venda com 3 itens é UMA linha aqui e 3 em SaleItem.
 *
 * É isso que faz a taxa de cartão incidir por transação e não por item — o
 * erro que a planilha antiga cometia, e que pesava porque metade dos
 * compradores levava mais de um item.
 *
 * `vendida_em` é separado de `created_at` para o lançamento retroativo: o
 * registro nasce hoje, a venda aconteceu ontem.
 */
class Sale extends Model
{
    protected $table = 'liv_sales';

    protected $connection = 'mysql';

    public $timestamps = false;

    protected $fillable = [
        'event_id', 'payment_method_id', 'registrado_por', 'comprador',
        'valor_bruto', 'taxa_percentual', 'taxa_valor', 'vendida_em', 'created_at',
        // sem estes dois no fillable, o update() do estorno os descarta EM SILÊNCIO:
        // a receita zera (o filtro pega o item) mas a taxa continua na conta.
        'cancelada_em', 'cancelada_por',
        'corrigida_em', 'corrigida_por',
    ];

    protected $casts = [
        'valor_bruto'     => 'decimal:2',
        'taxa_percentual' => 'decimal:2',
        'taxa_valor'      => 'decimal:2',
        'vendida_em'      => 'datetime',
        'cancelada_em'    => 'datetime',
        'corrigida_em'    => 'datetime',
        'created_at'      => 'datetime',
    ];

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

    /**
     * Quem desfez, e quem reescreveu.
     *
     * As colunas existiam desde sempre e nunca tiveram relação — então a
     * pergunta que se faz no fechamento ("quem estornou a #142?") só era
     * respondível com SQL na mão.
     */
    public function canceladaPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelada_por');
    }

    public function corrigidaPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'corrigida_por');
    }

    public function items(): HasMany
    {
        return $this->hasMany(SaleItem::class, 'sale_id');
    }

    public function exchanges(): HasMany
    {
        return $this->hasMany(Exchange::class, 'sale_id');
    }

    /** Os itens que a venda tem HOJE — depois de trocas e correções. */
    public function itensAtivos(): HasMany
    {
        return $this->items()->whereNull('cancelado_em');
    }

    public function foiCancelada(): bool
    {
        return $this->cancelada_em !== null;
    }

    public function foiCorrigida(): bool
    {
        return $this->corrigida_em !== null;
    }

    /**
     * Quanto a venda vale hoje, somando os itens que restaram.
     *
     * NÃO é `valor_bruto`, e a diferença importa: valor_bruto é o que passou
     * no meio de pagamento e tem de continuar batendo com o extrato, mesmo
     * depois de uma troca. Este é o valor da mercadoria que a pessoa levou.
     */
    public function valorDosItens(): float
    {
        return round((float) $this->items()->whereNull('cancelado_em')->sum('preco'), 2);
    }

    /** Vendas que contam para o resultado. */
    public function scopeValidas($query)
    {
        return $query->whereNull('cancelada_em');
    }
}
