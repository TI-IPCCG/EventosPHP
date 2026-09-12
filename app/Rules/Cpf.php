<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * CPF com dígito verificador.
 *
 * ⚠ Não é preciosismo de validação: aqui o CPF é CHAVE DE IDENTIDADE
 * (par_people.cpf é UNIQUE por congregação). Um CPF digitado errado não produz
 * um erro de formulário — produz uma PESSOA errada, e pode ocupar o CPF real
 * de outra, travando a inscrição dela depois.
 *
 * Espera 11 dígitos, sem pontuação: quem normaliza é o model.
 */
class Cpf implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $cpf = preg_replace('/\D/', '', (string) $value);

        if (strlen($cpf) !== 11 || preg_match('/^(\d)\1{10}$/', $cpf)) {
            $fail('O CPF informado não é válido.');

            return;
        }

        // Os dois dígitos verificadores, cada um sobre os anteriores.
        foreach ([9, 10] as $posicao) {
            $soma = 0;

            for ($i = 0; $i < $posicao; $i++) {
                $soma += (int) $cpf[$i] * (($posicao + 1) - $i);
            }

            $digito = ((10 * $soma) % 11) % 10;

            if ((int) $cpf[$posicao] !== $digito) {
                $fail('O CPF informado não é válido.');

                return;
            }
        }
    }
}
