<?php

namespace App\Models\Livraria;

use App\Models\Concerns\BelongsToChurch;
use Illuminate\Database\Eloquent\Model;

/**
 * Motivo de baixa sem venda: Sorteio, Cortesia, Doação, Perda.
 *
 * `gera_custo` é a regra virada dado: exemplar consignado que sai não volta ao
 * fornecedor, logo é devido a ele — como se tivesse sido vendido. A exceção é
 * o exemplar que a própria editora doou para o sorteio.
 */
class WriteoffReason extends Model
{
    use BelongsToChurch;

    protected $table = 'liv_writeoff_reasons';

    protected $connection = 'mysql';

    public $timestamps = false;

    protected $fillable = ['church_id', 'nome', 'gera_custo', 'ativo'];

    protected $casts = ['gera_custo' => 'boolean', 'ativo' => 'boolean'];

    public function scopeAtivos($query)
    {
        return $query->where('ativo', true)->orderBy('nome');
    }
}
