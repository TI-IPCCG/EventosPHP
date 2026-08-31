<?php

namespace App\Models\Livraria;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Um campo que a categoria pede. A tela do item se monta a partir daqui, e os
 * valores caem no JSON Product::atributos — por isso dá para acrescentar
 * "ISBN" ou "Gramatura" sem desenvolvimento.
 *
 * ⚠ Tamanho NÃO é um field. Tamanho divide estoque, então é Variant.
 */
class CategoryField extends Model
{
    protected $table = 'liv_category_fields';

    protected $connection = 'mysql';

    public $timestamps = false;

    protected $fillable = ['category_id', 'chave', 'rotulo', 'tipo', 'opcoes', 'obrigatorio', 'mostrar_na_lista', 'ordem'];

    protected $casts = [
        'opcoes'           => 'array',
        'obrigatorio'      => 'boolean',
        'mostrar_na_lista' => 'boolean',
    ];

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'category_id');
    }

    /**
     * Regras de validação do Laravel equivalentes a este campo.
     *
     * ⚠ Cada regra é UM elemento do array. A sintaxe com pipe ("string|max:255")
     * só vale quando as regras vêm numa string única — dentro de um array o
     * Laravel procura uma regra chamada literalmente "string|max:255" e estoura.
     */
    public function regras(): array
    {
        $r = [$this->obrigatorio ? 'required' : 'nullable'];

        return array_merge($r, match ($this->tipo) {
            'inteiro'     => ['integer'],
            'decimal'     => ['numeric'],
            'data'        => ['date'],
            'booleano'    => ['boolean'],
            'selecao'     => ['in:'.implode(',', $this->opcoes ?? [])],
            'texto_longo' => ['string', 'max:2000'],
            default       => ['string', 'max:255'],
        });
    }
}
