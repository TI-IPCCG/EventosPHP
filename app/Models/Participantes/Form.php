<?php

namespace App\Models\Participantes;

use App\Models\Concerns\BelongsToChurch;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * O formulário de inscrição — fase 2. Permanente e reusável entre eventos:
 * montado uma vez, reaproveitado todo ano. Espelha Livraria\Category.
 */
class Form extends Model
{
    use BelongsToChurch;

    protected $table = 'par_forms';

    protected $connection = 'mysql';

    protected $fillable = ['church_id', 'nome', 'descricao', 'ativo'];

    protected $casts = ['ativo' => 'boolean'];

    /**
     * ⚠ O desempate por id não é enfeite: sem ele, duas perguntas com a mesma
     * `ordem` saem em ordem indefinida e o formulário troca de layout entre uma
     * requisição e outra. É a lição de Livraria\Category::fields().
     */
    public function questions(): HasMany
    {
        return $this->hasMany(Question::class, 'form_id')
            ->orderBy('ordem')
            ->orderBy('id');
    }

    /** As que ainda são perguntadas — arquivada some do formulário, não do histórico. */
    public function questionsVigentes(): HasMany
    {
        return $this->questions()->whereNull('arquivada_em');
    }

    public function scopeAtivos($query)
    {
        return $query->where('ativo', true)->orderBy('nome');
    }
}
