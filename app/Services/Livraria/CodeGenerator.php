<?php

namespace App\Services\Livraria;

use App\Models\Livraria\Copy;
use App\Models\Livraria\Supplier;
use Illuminate\Support\Facades\DB;

/**
 * Códigos dos exemplares: prefixo do fornecedor + sequência dentro do evento.
 * ECC001, EFL042, CAM007.
 *
 * A sequência é POR EVENTO, não global: cada evento recomeça do 001, que é o
 * que a etiqueta precisa ser — curta o suficiente para ler de relance na mesa.
 */
class CodeGenerator
{
    /**
     * Próximo número livre para o prefixo dentro do evento.
     *
     * Lê o MAIOR sufixo já usado em vez de contar linhas: exemplar apagado
     * abriria buraco na contagem e devolveria um código já existente.
     */
    public function proximaSequencia(int $eventId, string $prefixo): int
    {
        $maior = DB::table('liv_copies')
            ->where('event_id', $eventId)
            ->where('codigo', 'like', $prefixo.'%')
            ->selectRaw('MAX(CAST(SUBSTRING(codigo, ?) AS UNSIGNED)) as maior', [mb_strlen($prefixo) + 1])
            ->value('maior');

        return (int) $maior + 1;
    }

    /**
     * Gera os códigos de um lote, sem gravar.
     *
     * @return array<string>
     */
    public function gerar(int $eventId, Supplier $fornecedor, int $quantidade): array
    {
        $inicio  = $this->proximaSequencia($eventId, $fornecedor->prefixo);
        $codigos = [];

        for ($i = 0; $i < $quantidade; $i++) {
            $codigos[] = sprintf('%s%03d', $fornecedor->prefixo, $inicio + $i);
        }

        return $codigos;
    }

    /** O código existe neste evento? Usado na conferência da etiqueta. */
    public function existe(int $eventId, string $codigo): bool
    {
        return Copy::where('event_id', $eventId)->where('codigo', $codigo)->exists();
    }
}
