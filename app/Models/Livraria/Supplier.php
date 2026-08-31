<?php

namespace App\Models\Livraria;

use App\Models\Concerns\BelongsToChurch;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Fornecedor. `prefixo` gera o código do exemplar (ECC + 001 = ECC001) e
 * `condicao_padrao` diz se o que sobra volta (consignado) ou não (firme).
 */
class Supplier extends Model
{
    use BelongsToChurch;

    protected $table = 'liv_suppliers';

    protected $connection = 'mysql';

    protected $fillable = ['church_id', 'nome', 'prefixo', 'condicao_padrao', 'desconto_padrao', 'contato', 'ativo'];

    protected $casts = ['desconto_padrao' => 'decimal:2', 'ativo' => 'boolean'];

    public function products(): HasMany
    {
        return $this->hasMany(Product::class, 'supplier_id');
    }

    public function shipments(): HasMany
    {
        return $this->hasMany(Shipment::class, 'supplier_id');
    }

    public function isConsignado(): bool
    {
        return $this->condicao_padrao === 'consignado';
    }

    public function scopeAtivos($query)
    {
        return $query->where('ativo', true)->orderBy('nome');
    }
}
