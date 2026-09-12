<?php

namespace App\Support;

use App\Rules\Cpf;

/**
 * Tipo de campo dinâmico → regras de validação.
 *
 * Extraído de Livraria\CategoryField::regras() para ser compartilhado entre o
 * catálogo de produtos e o formulário de inscrição: os dois montam o formulário
 * a partir de uma tabela de definição, e a validação tem de sair da mesma
 * definição, senão as duas telas divergem com o tempo.
 *
 * ⚠ Cada regra é UM elemento do array. A sintaxe com pipe ("string|max:255") só
 * vale quando as regras vêm numa string única — dentro de um array o Laravel
 * procura uma regra chamada literalmente "string|max:255" e estoura.
 */
class CampoDinamico
{
    /** Os 7 tipos que o catálogo já usava, na mesma ordem do ENUM. */
    public const TIPOS_BASE = [
        'texto', 'texto_longo', 'inteiro', 'decimal', 'data', 'selecao', 'booleano',
    ];

    /** Os que o formulário de inscrição acrescenta. */
    public const TIPOS_INSCRICAO = ['multipla_escolha', 'email', 'telefone', 'cpf'];

    public static function regras(string $tipo, ?array $opcoes, bool $obrigatorio): array
    {
        $regras = [$obrigatorio ? 'required' : 'nullable'];

        return array_merge($regras, match ($tipo) {
            'inteiro'          => ['integer'],
            'decimal'          => ['numeric'],
            'data'             => ['date'],
            'booleano'         => ['boolean'],
            'selecao'          => ['in:'.implode(',', $opcoes ?? [])],
            'texto_longo'      => ['string', 'max:2000'],
            'multipla_escolha' => ['array'],
            // rfc e não dns: validar DNS é lento e falha no wi-fi do evento,
            // justo quando a pessoa está tentando se inscrever.
            'email'            => ['email:rfc', 'max:191'],
            'telefone'         => ['string', 'max:20'],
            'cpf'              => [new Cpf],
            default            => ['string', 'max:255'],
        });
    }

    /** Múltipla escolha valida cada item escolhido, não o array inteiro. */
    public static function regrasDosItens(string $tipo, ?array $opcoes): ?array
    {
        return $tipo === 'multipla_escolha'
            ? ['in:'.implode(',', $opcoes ?? [])]
            : null;
    }
}
