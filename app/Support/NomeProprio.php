<?php

namespace App\Support;

/**
 * Nome de gente, escrito como gente escreve.
 *
 * O formulário vem cheio de CAIXA ALTA ("DEISE SOUZA FERNANDES MENDONÇA") e de
 * espaço duplo, e jogar tudo em MB_CASE_TITLE resolvia o grito mas criava dois
 * defeitos próprios:
 *
 *  · "Shara da Silva" virava "Shara Da Silva" — preposição não é sobrenome;
 *  · "Mont'Alvão" virava "Mont'alvão", porque a letra depois do apóstrofo
 *    perdia a maiúscula.
 *
 * Isso também tornava a CORREÇÃO manual impossível: o operador digitava a
 * forma certa, o mutator reescrevia por cima, e não havia como consertar.
 */
final class NomeProprio
{
    /**
     * Partículas que ficam em minúscula quando não abrem o nome.
     *
     * "Do Carmo" abrindo o nome continua maiúsculo — é o primeiro nome dela.
     */
    private const PARTICULAS = [
        'da', 'das', 'de', 'del', 'della', 'di', 'do', 'dos', 'du',
        'e', 'la', 'le', 'van', 'von', 'y',
    ];

    public static function formatar(?string $valor): ?string
    {
        $limpo = preg_replace('/\s+/', ' ', trim((string) $valor));

        if ($limpo === '') {
            return null;
        }

        $palavras = explode(' ', mb_strtolower($limpo, 'UTF-8'));

        foreach ($palavras as $i => $palavra) {
            $palavras[$i] = $i > 0 && in_array($palavra, self::PARTICULAS, true)
                ? $palavra
                : self::capitalizar($palavra);
        }

        return implode(' ', $palavras);
    }

    /**
     * Maiúscula no começo e depois de apóstrofo ou hífen: Mont'Alvão, D'Ávila,
     * Jean-Pierre. O \p{L} é o que faz "Ávila" e "Ângela" funcionarem.
     */
    private static function capitalizar(string $palavra): string
    {
        return preg_replace_callback(
            "/(^|['’\-])(\p{L})/u",
            fn ($m) => $m[1].mb_strtoupper($m[2], 'UTF-8'),
            $palavra,
        );
    }
}
