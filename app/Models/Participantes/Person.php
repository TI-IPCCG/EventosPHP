<?php

namespace App\Models\Participantes;

use App\Models\Concerns\BelongsToChurch;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A pessoa ENTRE eventos — o "master_customer" do escopo.
 *
 * Cadastro permanente da congregação: é o que faz os dados voltarem preenchidos
 * quando a mesma pessoa se inscreve no ano seguinte.
 *
 * ⚠ `email` e `cpf` aqui são CHAVES DE IDENTIDADE, não canais de contato. Quem
 * recebe a credencial é o e-mail gravado na INSCRIÇÃO — e é essa separação que
 * deixa um casal usar um e-mail só.
 *
 * ⚠ A normalização nos mutators não é cosmética: o UNIQUE não serve para nada
 * se "123.456.789-00" e "12345678900" forem duas linhas.
 */
class Person extends Model
{
    use BelongsToChurch;

    protected $table = 'par_people';

    protected $connection = 'mysql';

    protected $fillable = [
        'church_id', 'nome', 'email', 'cpf', 'telefone', 'data_nascimento',
        'perfil', 'observacao', 'ativo', 'fundida_em_id', 'fundida_em',
    ];

    protected $casts = [
        'data_nascimento' => 'date',
        'perfil'          => 'array',
        'ativo'           => 'boolean',
        'fundida_em'      => 'datetime',
    ];

    /** Só dígitos. String vazia vira NULL — senão o primeiro cadastro sem CPF trava todos os outros. */
    public function setCpfAttribute($valor): void
    {
        $this->attributes['cpf'] = preg_replace('/\D/', '', (string) $valor) ?: null;
    }

    /**
     * Minúsculas e sem espaço. A collation utf8mb4_unicode_ci já é
     * case-insensitive, então o UNIQUE pegaria "Ana@x" e "ana@x" de qualquer
     * forma — normalizar é para o dado ficar limpo na tela e na comparação.
     */
    public function setEmailAttribute($valor): void
    {
        $this->attributes['email'] = mb_strtolower(trim((string) $valor)) ?: null;
    }

    public function setTelefoneAttribute($valor): void
    {
        $this->attributes['telefone'] = preg_replace('/\D/', '', (string) $valor) ?: null;
    }

    /** Nome de gente, não grito: o Forms vem cheio de CAIXA ALTA e espaço duplo. */
    public function setNomeAttribute($valor): void
    {
        $limpo = preg_replace('/\s+/', ' ', trim((string) $valor));

        $this->attributes['nome'] = mb_convert_case($limpo, MB_CASE_TITLE, 'UTF-8');
    }

    public function registrations(): HasMany
    {
        return $this->hasMany(Registration::class, 'person_id');
    }

    /** Para onde esta pessoa foi, quando duas viraram uma. */
    public function fundidaEm(): BelongsTo
    {
        return $this->belongsTo(self::class, 'fundida_em_id');
    }

    /** Uma resposta de escopo=pessoa: o valor de HOJE. */
    public function doPerfil(string $chave, mixed $padrao = null): mixed
    {
        return data_get($this->perfil, $chave, $padrao);
    }

    public function scopeAtivas($query)
    {
        return $query->where('ativo', true)->whereNull('fundida_em_id');
    }
}
