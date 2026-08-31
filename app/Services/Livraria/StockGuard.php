<?php

namespace App\Services\Livraria;

use App\Models\Livraria\Copy;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * A trava de "um exemplar não sai duas vezes" — TERCEIRA camada.
 *
 * As outras duas estão no banco:
 *   1. índice UNIQUE em copy_ativo → impede a MESMA tabela repetir o exemplar
 *   2. gatilhos BEFORE INSERT      → impedem vender e depois sortear o mesmo
 *
 * Nenhuma das duas resolve a CORRIDA: duas transações simultâneas podem ler
 * "disponivel" antes de qualquer uma gravar. É o que esta classe faz, e só
 * ela pode fazer — com SELECT ... FOR UPDATE, que bloqueia a linha do
 * exemplar até o commit.
 *
 * Todo caminho que tira exemplar do estoque passa por aqui.
 */
class StockGuard
{
    /**
     * Trava as linhas dos exemplares e devolve só as que estão disponíveis.
     * Deve ser chamado DENTRO de uma transação — fora dela o lock morre na hora.
     *
     * @param  array<int>  $copyIds
     * @return Collection<int, Copy>
     *
     * @throws RuntimeException se algum exemplar não estiver disponível
     */
    public function travar(int $eventId, array $copyIds): Collection
    {
        if (empty($copyIds)) {
            throw new RuntimeException('Nenhum exemplar informado.');
        }

        // lockForUpdate() = SELECT ... FOR UPDATE. Segura a linha até o commit,
        // então a transação concorrente espera aqui em vez de ler "disponivel"
        // desatualizado.
        $copies = Copy::where('event_id', $eventId)
            ->whereIn('id', $copyIds)
            ->lockForUpdate()
            ->get();

        if ($copies->count() !== count(array_unique($copyIds))) {
            throw new RuntimeException('Exemplar não encontrado neste evento.');
        }

        $ocupados = $copies->reject->estaDisponivel();

        if ($ocupados->isNotEmpty()) {
            throw new RuntimeException(
                'Exemplar indisponível: '.$ocupados->map(
                    fn (Copy $c) => "{$c->codigo} ({$c->status})"
                )->join(', ')
            );
        }

        return $copies;
    }

    /**
     * Muda o status dos exemplares.
     *
     * ⚠ ORDEM IMPORTA: os gatilhos do banco exigem que o exemplar ainda esteja
     * 'disponivel' na hora do INSERT do item. Então primeiro grave o item,
     * depois chame isto.
     *
     * @param  Collection<int, Copy>  $copies
     */
    public function marcar(Collection $copies, string $status): void
    {
        Copy::whereIn('id', $copies->pluck('id'))->update([
            'status'     => $status,
            'updated_at' => now(),
        ]);
    }

    /** Devolve os exemplares ao estoque — usado no estorno e no cancelamento. */
    public function liberar(Collection|array $copyIds): void
    {
        $ids = $copyIds instanceof Collection ? $copyIds->pluck('id') : $copyIds;

        Copy::whereIn('id', $ids)->update([
            'status'     => Copy::DISPONIVEL,
            'updated_at' => now(),
        ]);
    }
}
