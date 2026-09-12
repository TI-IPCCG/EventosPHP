<?php

namespace App\Services\Participantes;

use App\Models\Participantes\Person;

/**
 * O que o PersonResolver devolve: a pessoa, como ela foi encontrada, e se
 * houve ambiguidade que exige olho humano.
 */
class ResultadoDaPessoa
{
    public function __construct(
        public readonly Person $pessoa,
        /** cpf | email | historico | nova */
        public readonly string $via,
        /** CPF e e-mail apontaram para pessoas DIFERENTES. */
        public readonly bool $conflito = false,
    ) {}

    public function ehNova(): bool
    {
        return $this->via === 'nova';
    }
}
