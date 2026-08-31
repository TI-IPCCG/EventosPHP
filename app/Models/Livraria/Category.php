<?php

namespace App\Models\Livraria;

use App\Models\Concerns\BelongsToChurch;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Categoria do item: Livro, Camiseta, Caneca. É o que faz o catálogo não ser
 * só de livros — a categoria define quais campos o item pede (fields).
 *
 * usaVariacao() diz se os itens desta categoria têm saldo por tamanho/cor.
 */
class Category extends Model
{
    use BelongsToChurch;

    protected $table = 'liv_categories';

    protected $connection = 'mysql';

    public $timestamps = false;

    protected $fillable = ['church_id', 'nome', 'slug', 'usa_variacao', 'rotulo_variacao', 'ordem', 'ativo', 'created_at'];

    protected $casts = ['usa_variacao' => 'boolean', 'ativo' => 'boolean', 'created_at' => 'datetime'];

    /**
     * Ordena por `ordem` e desempata por id: sem o segundo critério, dois
     * campos com a mesma ordem saem em ordem indefinida, e o formulário do
     * item trocaria de layout entre uma requisição e outra.
     */
    public function fields(): HasMany
    {
        return $this->hasMany(CategoryField::class, 'category_id')
            ->orderBy('ordem')
            ->orderBy('id');
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class, 'category_id');
    }

    /** Como chamar a variação na tela: "Tamanho", "Cor". */
    public function rotuloVariacao(): string
    {
        return $this->rotulo_variacao ?: 'Variação';
    }

    public function scopeAtivas($query)
    {
        return $query->where('ativo', true)->orderBy('ordem');
    }
}
