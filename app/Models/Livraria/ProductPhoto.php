<?php

namespace App\Models\Livraria;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/**
 * Foto do item. Uma delas é a capa, e o banco garante que seja só uma
 * (índice único sobre coluna gerada).
 *
 * `caminho_thumb` não é luxo: a mesa opera em 4G no meio do evento, e mandar
 * a foto original em cada linha da listagem derruba a tela de venda.
 */
class ProductPhoto extends Model
{
    protected $table = 'liv_product_photos';

    protected $connection = 'mysql';

    public $timestamps = false;

    protected $fillable = ['product_id', 'caminho', 'caminho_thumb', 'capa', 'ordem', 'bytes', 'largura', 'altura', 'created_at'];

    protected $casts = ['capa' => 'boolean', 'created_at' => 'datetime'];

    /** capa_unica é coluna GERADA pelo banco: nunca gravar. */
    protected $guarded = ['capa_unica'];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    public function url(): string
    {
        return Storage::disk('public')->url($this->caminho);
    }

    /** Cai na original quando ainda não há miniatura. */
    public function urlThumb(): string
    {
        return Storage::disk('public')->url($this->caminho_thumb ?: $this->caminho);
    }
}
