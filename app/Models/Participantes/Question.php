<?php

namespace App\Models\Participantes;

use App\Support\CampoDinamico;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Uma pergunta do formulário — fase 2. Espelha Livraria\CategoryField.
 *
 * ⚠ `chave` é IMUTÁVEL depois de criada: é ela que endereça a resposta dentro
 * do JSON. `rotulo` é editável, e é assim que se corrige o texto da pergunta
 * sem perder o que já foi respondido.
 *
 * ⚠ `escopo` decide ONDE o valor mora. `pessoa` também vai para
 * Person::perfil (o valor de hoje, que pré-preenche o próximo evento); a
 * resposta da inscrição continua congelada de qualquer jeito.
 */
class Question extends Model
{
    protected $table = 'par_questions';

    protected $connection = 'mysql';

    protected $fillable = [
        'form_id', 'chave', 'rotulo', 'ajuda', 'tipo', 'opcoes', 'obrigatorio',
        'escopo', 'mostrar_na_lista', 'unica', 'ordem', 'arquivada_em',
    ];

    protected $casts = [
        'opcoes'           => 'array',
        'obrigatorio'      => 'boolean',
        'mostrar_na_lista' => 'boolean',
        'unica'            => 'boolean',
        'arquivada_em'     => 'datetime',
    ];

    public function form(): BelongsTo
    {
        return $this->belongsTo(Form::class, 'form_id');
    }

    /** As regras saem da própria definição — mesma fonte que desenha o campo. */
    public function regras(): array
    {
        return CampoDinamico::regras($this->tipo, $this->opcoes, $this->obrigatorio);
    }

    public function regrasDosItens(): ?array
    {
        return CampoDinamico::regrasDosItens($this->tipo, $this->opcoes);
    }

    public function getArquivadaAttribute(): bool
    {
        return $this->arquivada_em !== null;
    }

    public function ehDaPessoa(): bool
    {
        return $this->escopo === 'pessoa';
    }
}
