<?php

namespace App\Services\Livraria;

use App\Models\Event;
use App\Models\Livraria\Copy;
use Illuminate\Support\Facades\DB;

/**
 * O RESULTADO DO EVENTO — a entrega central do módulo.
 *
 *   resultado = receita − devido aos fornecedores − custos − taxas
 *
 * Nada disto é coluna armazenada: é sempre derivado, para não existir número
 * guardado que possa discordar dos fatos.
 *
 * Os cinco erros da planilha antiga que este cálculo não comete:
 *   1. o frete entra UMA vez, pelo valor real, e não rateado sobre a venda
 *      prevista (a planilha cobrava R$ 994 de um frete de R$ 700)
 *   2. a taxa de cartão incide sobre a venda, por transação, e só onde houve
 *      cartão — nunca sobre o custo, nunca sobre 100% dos itens
 *   3. itens sem custo cadastrado não viram lucro fantasma
 *   4. a soma cobre todas as linhas, não um intervalo que parou no meio
 *   5. sorteio soma o custo que ele gera, em vez de só sumir da conta
 */
class EventResult
{
    public function __construct(private Event $event) {}

    public static function para(Event $event): self
    {
        return new self($event);
    }

    /** Vendas não canceladas. */
    public function receita(): float
    {
        return (float) DB::table('liv_sale_items as si')
            ->join('liv_sales as s', 's.id', '=', 'si.sale_id')
            ->where('s.event_id', $this->event->id)
            ->whereNull('s.cancelada_em')
            ->whereNull('si.cancelado_em')
            ->sum('si.preco');
    }

    /** Custo dos exemplares vendidos. */
    public function custoVendidos(): float
    {
        return (float) DB::table('liv_sale_items as si')
            ->join('liv_sales as s', 's.id', '=', 'si.sale_id')
            ->where('s.event_id', $this->event->id)
            ->whereNull('s.cancelada_em')
            ->whereNull('si.cancelado_em')
            ->sum('si.custo_unitario');
    }

    /**
     * Custo das baixas que geram custo. O exemplar consignado que saiu por
     * sorteio não volta ao fornecedor — é devido igual ao vendido.
     */
    public function custoBaixas(): float
    {
        return (float) DB::table('liv_writeoff_items as wi')
            ->join('liv_writeoffs as w', 'w.id', '=', 'wi.writeoff_id')
            ->where('w.event_id', $this->event->id)
            ->whereNull('w.cancelada_em')
            ->whereNull('wi.cancelado_em')
            ->where('wi.gera_custo', true)
            ->sum('wi.custo_unitario');
    }

    public function devidoFornecedores(): float
    {
        return round($this->custoVendidos() + $this->custoBaixas(), 2);
    }

    /** Frete e o que mais tiver sido lançado. Valor real, uma vez só. */
    public function custos(): float
    {
        return (float) DB::table('liv_event_costs')
            ->where('event_id', $this->event->id)
            ->sum('valor');
    }

    /**
     * Taxas de transação: as das vendas MAIS as das cobranças adicionais de
     * troca. Trocar por item mais caro e cobrar a diferença no cartão gera
     * taxa nova, que a operadora retém igual.
     */
    public function taxas(): float
    {
        $vendas = (float) DB::table('liv_sales')
            ->where('event_id', $this->event->id)
            ->whereNull('cancelada_em')
            ->sum('taxa_valor');

        return round($vendas + $this->taxasDeTroca(), 2);
    }

    private function taxasDeTroca(): float
    {
        return (float) DB::table('liv_exchanges as x')
            ->join('liv_sales as s', 's.id', '=', 'x.sale_id')
            ->where('x.event_id', $this->event->id)
            // Estornar a venda desfaz o dinheiro todo, inclusive o que a troca
            // movimentou — as trocas dela saem da conta junto.
            ->whereNull('s.cancelada_em')
            ->sum('x.taxa_valor');
    }

    /**
     * O que as trocas movimentaram no caixa, sem sinal, para a conferência.
     *
     * @return array{devolvido: float, cobrado: float, quantidade: int}
     */
    public function movimentoDeTrocas(): array
    {
        $linha = DB::table('liv_exchanges as x')
            ->join('liv_sales as s', 's.id', '=', 'x.sale_id')
            ->where('x.event_id', $this->event->id)
            ->whereNull('s.cancelada_em')
            ->selectRaw('COUNT(*) qtd,
                         COALESCE(SUM(CASE WHEN x.diferenca < 0 THEN -x.diferenca END), 0) devolvido,
                         COALESCE(SUM(CASE WHEN x.diferenca > 0 THEN  x.diferenca END), 0) cobrado')
            ->first();

        return [
            'quantidade' => (int) ($linha->qtd ?? 0),
            'devolvido'  => (float) ($linha->devolvido ?? 0),
            'cobrado'    => (float) ($linha->cobrado ?? 0),
        ];
    }

    public function resultado(): float
    {
        return round(
            $this->receita() - $this->devidoFornecedores() - $this->custos() - $this->taxas(),
            2
        );
    }

    /**
     * Quanto ainda falta vender para cobrir os custos. É o número do topo do
     * painel ao vivo: com meta zero a zero, "falta" é a única pergunta que
     * muda uma decisão no meio do dia. Negativo = já passou da meta.
     */
    public function faltaParaMeta(): float
    {
        $meta = $this->event->livrariaSettings?->meta_tipo === 'valor'
            ? (float) $this->event->livrariaSettings->meta_valor
            : 0.0;

        return round($meta - $this->resultado(), 2);
    }

    /** Exemplares devolvidos e o custo que NÃO precisou ser pago por eles. */
    public function devolucao(): array
    {
        $linha = DB::table('liv_copies as c')
            ->join('liv_shipment_items as si', 'si.id', '=', 'c.shipment_item_id')
            ->where('c.event_id', $this->event->id)
            ->whereIn('c.status', [Copy::DISPONIVEL, Copy::DEVOLVIDO])
            ->selectRaw('COUNT(*) as qtd, IFNULL(SUM(si.custo_unitario),0) as custo')
            ->first();

        return ['quantidade' => (int) $linha->qtd, 'custo_evitado' => (float) $linha->custo];
    }

    /**
     * Composição por forma de pagamento, para conferir com o extrato.
     *
     * Soma vendas E trocas. A diferença de uma troca é movimento real de
     * dinheiro — o troco saiu do caixa, a cobrança extra entrou no PIX — e
     * sem ela a conferência não fecha com o que foi contado na mesa.
     *
     * A diferença entra COM SINAL: devolução abate o total daquela forma, que
     * é exatamente o que aconteceu com o dinheiro.
     */
    public function porFormaDePagamento(): array
    {
        $vendas = $this->vendasPorForma();

        foreach ($this->trocasPorForma() as $forma => $t) {
            $vendas[$forma] ??= ['forma' => $forma, 'transacoes' => 0, 'valor' => 0.0, 'taxa' => 0.0];
            $vendas[$forma]['transacoes'] += $t['transacoes'];
            $vendas[$forma]['valor']      += $t['valor'];
            $vendas[$forma]['taxa']       += $t['taxa'];
        }

        return array_map(
            fn ($l) => [...$l, 'valor' => round($l['valor'], 2), 'taxa' => round($l['taxa'], 2)],
            array_values($vendas)
        );
    }

    /** @return array<string, array{forma:string, transacoes:int, valor:float, taxa:float}> */
    private function trocasPorForma(): array
    {
        return DB::table('liv_exchanges as x')
            ->join('liv_sales as s', 's.id', '=', 'x.sale_id')
            ->leftJoin('liv_payment_methods as pm', 'pm.id', '=', 'x.payment_method_id')
            ->where('x.event_id', $this->event->id)
            ->whereNull('s.cancelada_em')
            // troca que saiu par não moveu dinheiro: não é linha de extrato
            ->where('x.diferenca', '<>', 0)
            ->groupBy('pm.id', 'pm.nome')
            ->selectRaw('IFNULL(pm.nome, "Não informado") as forma,
                         COUNT(*) as transacoes,
                         SUM(x.diferenca) as valor,
                         SUM(x.taxa_valor) as taxa')
            ->get()
            ->keyBy('forma')
            ->map(fn ($r) => [
                'forma'      => $r->forma,
                'transacoes' => (int) $r->transacoes,
                'valor'      => (float) $r->valor,
                'taxa'       => (float) $r->taxa,
            ])->all();
    }

    /** @return array<string, array{forma:string, transacoes:int, valor:float, taxa:float}> */
    private function vendasPorForma(): array
    {
        return DB::table('liv_sales as s')
            ->leftJoin('liv_payment_methods as pm', 'pm.id', '=', 's.payment_method_id')
            ->where('s.event_id', $this->event->id)
            ->whereNull('s.cancelada_em')
            ->groupBy('pm.id', 'pm.nome', 'pm.ordem')
            ->orderBy('pm.ordem')
            ->selectRaw('IFNULL(pm.nome, "Não informado") as forma,
                         COUNT(*) as transacoes,
                         SUM(s.valor_bruto) as valor,
                         SUM(s.taxa_valor) as taxa')
            ->get()
            ->keyBy('forma')
            ->map(fn ($r) => [
                'forma'      => $r->forma,
                'transacoes' => (int) $r->transacoes,
                'valor'      => (float) $r->valor,
                'taxa'       => (float) $r->taxa,
            ])->all();
    }

    /**
     * O evento partido por FORNECEDOR, com três números que respondem três
     * perguntas diferentes:
     *
     *  · custo_total — o que veio na caixa dele, ao custo. Sob consignação é o
     *    risco máximo assumido; em compra firme, o que já foi pago.
     *  · devido — o que sai do caixa para ele: custo do que foi vendido MAIS o
     *    das baixas que geram custo (sorteio não volta para a editora).
     *  · igreja — o que fica: a receita daquele fornecedor menos o devido dele.
     *
     * `devido + igreja` é exatamente a receita gerada pelos itens dele, e é por
     * isso que os três lados juntos contam a história inteira sem precisar de
     * uma quarta barra.
     *
     * Três consultas em vez de uma com subselects: o número de fornecedores é
     * de um dígito, e três queries legíveis valem mais que uma indecifrável.
     *
     * @return array<array{fornecedor:string, custo_total:float, devido:float, igreja:float}>
     */
    public function porFornecedor(): array
    {
        $porChave = fn ($linhas) => collect($linhas)->keyBy('id');

        // o que veio: toda cópia do evento, ao custo da remessa
        $veio = $porChave(DB::table('liv_copies as c')
            ->join('liv_shipment_items as si', 'si.id', '=', 'c.shipment_item_id')
            ->join('liv_shipments as sh', 'sh.id', '=', 'si.shipment_id')
            ->join('liv_suppliers as f', 'f.id', '=', 'sh.supplier_id')
            ->where('c.event_id', $this->event->id)
            ->groupBy('f.id', 'f.nome')
            ->selectRaw('f.id, f.nome, SUM(si.custo_unitario) as valor')
            ->get());

        // o que foi vendido: custo (devido) e preço (receita) na mesma passada
        $vendido = $porChave(DB::table('liv_sale_items as it')
            ->join('liv_sales as s', 's.id', '=', 'it.sale_id')
            ->join('liv_copies as c', 'c.id', '=', 'it.copy_id')
            ->join('liv_shipment_items as si', 'si.id', '=', 'c.shipment_item_id')
            ->join('liv_shipments as sh', 'sh.id', '=', 'si.shipment_id')
            ->join('liv_suppliers as f', 'f.id', '=', 'sh.supplier_id')
            ->where('s.event_id', $this->event->id)
            ->whereNull('s.cancelada_em')
            ->whereNull('it.cancelado_em')
            ->groupBy('f.id', 'f.nome')
            ->selectRaw('f.id, f.nome, SUM(it.custo_unitario) as custo, SUM(it.preco) as receita')
            ->get());

        // baixa que gera custo é devida igual ao vendido, mas sem receita
        $baixado = $porChave(DB::table('liv_writeoff_items as wi')
            ->join('liv_writeoffs as w', 'w.id', '=', 'wi.writeoff_id')
            ->join('liv_copies as c', 'c.id', '=', 'wi.copy_id')
            ->join('liv_shipment_items as si', 'si.id', '=', 'c.shipment_item_id')
            ->join('liv_shipments as sh', 'sh.id', '=', 'si.shipment_id')
            ->join('liv_suppliers as f', 'f.id', '=', 'sh.supplier_id')
            ->where('w.event_id', $this->event->id)
            ->whereNull('w.cancelada_em')
            ->whereNull('wi.cancelado_em')
            ->where('wi.gera_custo', true)
            ->groupBy('f.id', 'f.nome')
            ->selectRaw('f.id, f.nome, SUM(wi.custo_unitario) as valor')
            ->get());

        return $veio->map(function ($linha, $id) use ($vendido, $baixado) {
            $devido  = (float) ($vendido[$id]->custo ?? 0) + (float) ($baixado[$id]->valor ?? 0);
            $receita = (float) ($vendido[$id]->receita ?? 0);

            return [
                'fornecedor'  => $linha->nome,
                'custo_total' => round((float) $linha->valor, 2),
                'devido'      => round($devido, 2),
                'igreja'      => round($receita - $devido, 2),
            ];
        })
            // o maior primeiro: quem concentra o risco abre a lista
            ->sortByDesc('custo_total')
            ->values()
            ->all();
    }

    /** Tudo de uma vez, para a tela de fechamento. */
    public function apuracao(): array
    {
        return [
            'receita'    => $this->receita(),
            'devido'     => $this->devidoFornecedores(),
            'custos'     => $this->custos(),
            'taxas'      => $this->taxas(),
            'resultado'  => $this->resultado(),
            'devolucao'  => $this->devolucao(),
            'trocas'     => $this->movimentoDeTrocas(),
            'pagamentos' => $this->porFormaDePagamento(),
        ];
    }
}
