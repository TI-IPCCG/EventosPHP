<?php

namespace App\Models\Livraria;

use App\Models\Concerns\BelongsToChurch;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Item do catálogo — livro, camiseta, o que for.
 *
 * `nome` e não "titulo", `preco_referencia` e não "preco_capa": camiseta não
 * tem título nem capa. O que é específico de cada categoria vive no JSON
 * `atributos`, cujas chaves são definidas por CategoryField.
 */
class Product extends Model
{
    use BelongsToChurch;

    protected $table = 'liv_products';

    protected $connection = 'mysql';

    protected $fillable = ['church_id', 'category_id', 'supplier_id', 'nome', 'preco_referencia', 'desconto', 'atributos', 'observacoes', 'ativo'];

    protected $casts = [
        'atributos'        => 'array',
        'preco_referencia' => 'decimal:2',
        'desconto'         => 'decimal:2',
        'ativo'            => 'boolean',
    ];

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'category_id');
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class, 'supplier_id');
    }

    public function variants(): HasMany
    {
        return $this->hasMany(Variant::class, 'product_id')
            ->orderBy('ordem')
            ->orderBy('id');
    }

    public function photos(): HasMany
    {
        return $this->hasMany(ProductPhoto::class, 'product_id')
            ->orderBy('ordem')
            ->orderBy('id');
    }

    public function coverPhoto(): HasOne
    {
        return $this->hasOne(ProductPhoto::class, 'product_id')->where('capa', true);
    }

    /** Valor de um atributo da categoria: $livro->atributo('autor'). */
    public function atributo(string $chave, mixed $padrao = null): mixed
    {
        return data_get($this->atributos, $chave, $padrao);
    }

    /**
     * Os atributos marcados para aparecer na listagem, já com o rótulo.
     * É o que vai embaixo do nome na tela da mesa: autor no livro, modelo na
     * camiseta — sem a tela precisar saber o que é livro e o que é camiseta.
     */
    public function destaques(): array
    {
        return $this->category->fields
            ->where('mostrar_na_lista', true)
            ->map(fn ($f) => ['rotulo' => $f->rotulo, 'valor' => $this->atributo($f->chave)])
            ->filter(fn ($d) => filled($d['valor']))
            ->values()
            ->all();
    }

    /**
     * Desconto que vale para este item: o dele, se houver; senão o do
     * fornecedor. NULL quando nenhum dos dois está definido.
     *
     * ⚠ Serve só para SUGERIR o custo ao montar a remessa. O valor do acerto é
     * o `custo_unitario` gravado na linha, que é snapshot — renegociar o
     * desconto amanhã não pode mexer num evento já fechado.
     */
    public function descontoEfetivo(): ?float
    {
        $desconto = $this->desconto ?? $this->supplier?->desconto_padrao;

        return $desconto === null ? null : (float) $desconto;
    }

    /** De onde veio o desconto — a tela precisa dizer isso ao coordenador. */
    public function origemDoDesconto(): string
    {
        if ($this->desconto !== null) {
            return 'item';
        }

        return $this->supplier?->desconto_padrao !== null ? 'fornecedor' : 'nenhum';
    }

    /** Custo sugerido por exemplar, a partir do preço de referência. */
    public function custoSugerido(): ?float
    {
        $capa     = (float) ($this->preco_referencia ?? 0);
        $desconto = $this->descontoEfetivo();

        if ($capa <= 0 || $desconto === null) {
            return null;
        }

        return round($capa * (1 - $desconto / 100), 2);
    }

    public function usaVariacao(): bool
    {
        return (bool) $this->category?->usa_variacao;
    }

    public function scopeAtivos($query)
    {
        return $query->where('ativo', true);
    }
}
