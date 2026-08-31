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

    public function taxas(): float
    {
        return (float) DB::table('liv_sales')
            ->where('event_id', $this->event->id)
            ->whereNull('cancelada_em')
            ->sum('taxa_valor');
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

    /** Composição por forma de pagamento, para conferir com o extrato. */
    public function porFormaDePagamento(): array
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
            ->map(fn ($r) => [
                'forma'      => $r->forma,
                'transacoes' => (int) $r->transacoes,
                'valor'      => (float) $r->valor,
                'taxa'       => (float) $r->taxa,
            ])->all();
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
            'pagamentos' => $this->porFormaDePagamento(),
        ];
    }
}
