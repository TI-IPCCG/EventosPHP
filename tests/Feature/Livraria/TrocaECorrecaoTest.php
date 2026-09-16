<?php

namespace Tests\Feature\Livraria;

use App\Models\Event;
use App\Models\Livraria\Category;
use App\Models\Livraria\Copy;
use App\Models\Livraria\PaymentMethod;
use App\Models\Livraria\Product;
use App\Models\Livraria\Sale;
use App\Models\Livraria\Shipment;
use App\Models\Livraria\ShipmentItem;
use App\Models\Livraria\Supplier;
use App\Services\Livraria\EventResult;
use App\Services\Livraria\ExchangeService;
use App\Services\Livraria\SaleService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use RuntimeException;
use Tests\TestCase;

/**
 * Trocar e corrigir venda já registrada.
 *
 * As duas operações existem separadas porque o efeito financeiro é OPOSTO, e
 * é isso que a maioria destes testes trava:
 *
 *   · TROCA    — a venda estava certa e o comprador voltou. `valor_bruto` e
 *                `taxa_valor` NÃO mudam (o extrato do PIX continua mostrando
 *                o que passou), e a diferença vira movimento próprio.
 *   · CORREÇÃO — o lançamento estava errado desde o início. Aí sim reescreve,
 *                porque o número que está lá nunca foi verdade.
 *
 * Cenário:  Livro A R$ 45 · Livro B R$ 36 · Camiseta R$ 60 · PIX 0% · Crédito 4,5%
 */
class TrocaECorrecaoTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = ['mysql'];

    private Event $evento;
    private array $copies = [];
    private PaymentMethod $pix;
    private PaymentMethod $credito;

    protected function setUp(): void
    {
        parent::setUp();
        session(['church_id' => 1]);
        $this->montarCenario();
    }

    private function montarCenario(): void
    {
        $sufixo = 'x'.uniqid();

        $cat  = Category::create(['church_id' => 1, 'nome' => 'Livro X',
            'slug' => "livro-$sufixo", 'usa_variacao' => false]);
        $forn = Supplier::create(['church_id' => 1, 'nome' => 'Editora X',
            'prefixo' => bin2hex(random_bytes(2)), 'condicao_padrao' => 'firme']);

        $this->evento = Event::create(['church_id' => 1, 'nome' => 'Evento Troca',
            'inicio' => today(), 'status' => 'em_andamento']);

        $this->pix     = PaymentMethod::create(['event_id' => $this->evento->id,
            'nome' => 'PIX', 'taxa_percentual' => 0, 'ordem' => 1]);
        $this->credito = PaymentMethod::create(['event_id' => $this->evento->id,
            'nome' => 'Crédito', 'taxa_percentual' => 4.5, 'ordem' => 2]);

        $remessa = Shipment::create(['event_id' => $this->evento->id,
            'supplier_id' => $forn->id, 'condicao' => 'firme']);

        foreach ([['A', 45.0, 30.0], ['B', 36.0, 24.0], ['C', 60.0, 40.0]] as [$letra, $preco, $custo]) {
            $produto = Product::create(['church_id' => 1, 'category_id' => $cat->id,
                'supplier_id' => $forn->id, 'nome' => "Título {$letra}"]);

            $item = ShipmentItem::create(['shipment_id' => $remessa->id,
                'product_id' => $produto->id, 'quantidade' => 3,
                'custo_unitario' => $custo, 'preco_venda' => $preco]);

            for ($i = 1; $i <= 3; $i++) {
                $codigo = sprintf('L%s%03d', $letra, $i);
                $this->copies[$codigo] = Copy::create(['event_id' => $this->evento->id,
                    'shipment_item_id' => $item->id, 'codigo' => $codigo]);
            }
        }
    }

    // ─────────────────────────── atalhos ───────────────────────────

    private function ids(array $codigos): array
    {
        return array_map(fn ($c) => $this->copies[$c]->id, $codigos);
    }

    private function vender(array $codigos, ?PaymentMethod $forma = null): Sale
    {
        return app(SaleService::class)->registrar(
            $this->evento->id, $this->ids($codigos), ($forma ?? $this->pix)->id
        );
    }

    private function trocar(Sale $venda, array $saem, array $entram, ?PaymentMethod $forma = null)
    {
        $itens = $venda->items()->whereNull('cancelado_em')
            ->whereIn('copy_id', $this->ids($saem))->pluck('id')->all();

        return app(ExchangeService::class)->trocar(
            $venda, $itens, $this->ids($entram), $forma?->id, 'teste'
        );
    }

    private function statusDe(string $codigo): string
    {
        return Copy::find($this->copies[$codigo]->id)->status;
    }

    // ───────────────────────────  troca  ────────────────────────────

    public function test_troca_por_item_mais_barato_devolve_a_diferenca(): void
    {
        $venda = $this->vender(['LA001']);                 // 45,00

        $troca = $this->trocar($venda, ['LA001'], ['LB001']);   // 36,00

        $this->assertSame('-9.00', $troca->diferenca);
        $this->assertTrue($troca->devolveu());
        $this->assertSame(9.0, $troca->valorMovimentado());
    }

    public function test_troca_por_item_mais_caro_cobra_a_diferenca_com_taxa(): void
    {
        $venda = $this->vender(['LA001']);                 // 45,00

        $troca = $this->trocar($venda, ['LA001'], ['LC001'], $this->credito);   // 60,00

        $this->assertSame('15.00', $troca->diferenca);
        $this->assertTrue($troca->cobrou());
        // 4,5% de 15,00 = 0,675, e o binário arredonda para baixo. Vale o mesmo
        // taxaSobre() das vendas: a troca não inventa arredondamento próprio.
        $this->assertSame('0.67', $troca->taxa_valor);
    }

    /**
     * Devolver troco não paga taxa a operadora nenhuma — cobrar taxa sobre
     * devolução inventaria um custo que não existe.
     */
    public function test_devolucao_nao_gera_taxa(): void
    {
        $venda = $this->vender(['LA001']);

        $troca = $this->trocar($venda, ['LA001'], ['LB001'], $this->credito);

        $this->assertSame('0.00', $troca->taxa_valor);
        $this->assertSame('0.00', $troca->taxa_percentual);
    }

    public function test_troca_par_nao_movimenta_dinheiro(): void
    {
        $venda = $this->vender(['LA001']);

        $troca = $this->trocar($venda, ['LA001'], ['LA002'], $this->credito);

        $this->assertSame('0.00', $troca->diferenca);
        $this->assertNull($troca->payment_method_id, 'troca par não é linha de extrato');
    }

    public function test_a_troca_move_o_estoque_dos_dois_lados(): void
    {
        $venda = $this->vender(['LA001']);
        $this->trocar($venda, ['LA001'], ['LB001']);

        $this->assertSame(Copy::DISPONIVEL, $this->statusDe('LA001'), 'o devolvido tem de voltar ao estoque');
        $this->assertSame(Copy::VENDIDO,    $this->statusDe('LB001'), 'o levado tem de sair do estoque');
    }

    /**
     * O CORAÇÃO DA DECISÃO. Quem pagou R$ 45 no PIX aparece no extrato como
     * R$ 45, e a operadora reteve taxa sobre 45 — trocar depois não desfaz
     * nada disso. Se este teste quebrar, o relatório passou a divergir do
     * extrato do banco.
     */
    public function test_a_troca_nao_reescreve_o_valor_da_venda_original(): void
    {
        $venda = $this->vender(['LA001'], $this->credito);
        $brutoAntes = $venda->valor_bruto;
        $taxaAntes  = $venda->taxa_valor;

        $this->trocar($venda, ['LA001'], ['LB001']);

        $venda->refresh();
        $this->assertSame($brutoAntes, $venda->valor_bruto);
        $this->assertSame($taxaAntes,  $venda->taxa_valor);
    }

    /** Mas o que a pessoa levou mudou — e é o que a receita enxerga. */
    public function test_a_receita_passa_a_valer_o_item_novo(): void
    {
        $venda = $this->vender(['LA001']);
        $this->trocar($venda, ['LA001'], ['LB001']);

        $this->assertSame(36.0, EventResult::para($this->evento)->receita());
        $this->assertSame(36.0, $venda->fresh()->valorDosItens());
    }

    public function test_a_taxa_da_cobranca_extra_entra_na_apuracao(): void
    {
        $venda = $this->vender(['LA001'], $this->pix);      // taxa 0
        $this->trocar($venda, ['LA001'], ['LC001'], $this->credito);

        $this->assertSame(0.67, EventResult::para($this->evento)->taxas());
    }

    public function test_a_diferenca_entra_na_conferencia_por_forma_de_pagamento(): void
    {
        $venda = $this->vender(['LA001'], $this->pix);      // + 45,00 no PIX
        $this->trocar($venda, ['LA001'], ['LB001'], $this->pix);   // − 9,00 no PIX

        $pix = collect(EventResult::para($this->evento)->porFormaDePagamento())
            ->firstWhere('forma', 'PIX');

        $this->assertSame(36.0, $pix['valor'], 'a devolução tem de abater o total da forma');
        $this->assertSame(2, $pix['transacoes']);
    }

    public function test_devolucao_pura_sem_item_novo(): void
    {
        $venda = $this->vender(['LA001', 'LB001']);         // 81,00

        $troca = $this->trocar($venda, ['LB001'], []);

        $this->assertSame('-36.00', $troca->diferenca);
        $this->assertSame(45.0, $venda->fresh()->valorDosItens());
        $this->assertSame(Copy::DISPONIVEL, $this->statusDe('LB001'));
    }

    public function test_troca_em_venda_estornada_e_recusada(): void
    {
        $venda = $this->vender(['LA001']);
        app(SaleService::class)->estornar($venda);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('estornada');

        $this->trocar($venda->fresh(), ['LA001'], ['LB001']);
    }

    public function test_item_de_outra_venda_e_recusado(): void
    {
        $minha  = $this->vender(['LA001']);
        $outra  = $this->vender(['LB001']);
        $alheio = $outra->items()->first()->id;

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('não pertence a esta venda');

        app(ExchangeService::class)->trocar($minha, [$alheio], $this->ids(['LC001']));
    }

    public function test_devolver_o_mesmo_item_duas_vezes_e_recusado(): void
    {
        $venda = $this->vender(['LA001']);
        $item  = $venda->items()->first()->id;

        app(ExchangeService::class)->trocar($venda, [$item], $this->ids(['LB001']));

        $this->expectException(RuntimeException::class);

        app(ExchangeService::class)->trocar($venda->fresh(), [$item], $this->ids(['LC001']));
    }

    public function test_trocar_por_exemplar_ja_vendido_e_recusado(): void
    {
        $venda = $this->vender(['LA001']);
        $this->vender(['LB001']);                    // LB001 saiu para outra pessoa

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('indisponível');

        $this->trocar($venda, ['LA001'], ['LB001']);
    }

    /**
     * O bug que a troca criou no estorno: um item devolvido numa troca já
     * voltou ao estoque e pode ter sido VENDIDO A OUTRA PESSOA. Estornar a
     * venda antiga não pode devolvê-lo ao estoque de novo — ele está com o
     * comprador seguinte, e o saldo passaria a mentir.
     */
    public function test_estorno_depois_de_troca_nao_devolve_exemplar_ja_revendido(): void
    {
        $venda = $this->vender(['LA001']);
        $this->trocar($venda, ['LA001'], ['LB001']);      // LA001 volta ao estoque

        $this->vender(['LA001']);                          // e sai para outra pessoa
        $this->assertSame(Copy::VENDIDO, $this->statusDe('LA001'));

        app(SaleService::class)->estornar($venda->fresh());

        $this->assertSame(Copy::VENDIDO, $this->statusDe('LA001'), 'não é mais desta venda');
        $this->assertSame(Copy::DISPONIVEL, $this->statusDe('LB001'), 'este sim volta');
    }

    /** Estornada a venda, o dinheiro volta todo — inclusive o que a troca moveu. */
    public function test_estorno_tira_a_troca_da_apuracao(): void
    {
        $venda = $this->vender(['LA001'], $this->pix);
        $this->trocar($venda, ['LA001'], ['LC001'], $this->credito);

        app(SaleService::class)->estornar($venda->fresh());

        $apuracao = EventResult::para($this->evento);
        $this->assertSame(0.0, $apuracao->receita());
        $this->assertSame(0.0, $apuracao->taxas());
        $this->assertSame(0, $apuracao->movimentoDeTrocas()['quantidade']);
    }

    public function test_movimento_de_trocas_separa_devolvido_de_cobrado(): void
    {
        $v1 = $this->vender(['LA001']);
        $this->trocar($v1, ['LA001'], ['LB001']);                     // devolve 9

        $v2 = $this->vender(['LA002']);
        $this->trocar($v2, ['LA002'], ['LC001'], $this->credito);     // cobra 15

        $m = EventResult::para($this->evento)->movimentoDeTrocas();

        $this->assertSame(2, $m['quantidade']);
        $this->assertSame(9.0,  $m['devolvido']);
        $this->assertSame(15.0, $m['cobrado']);
    }

    // ─────────────────────────── correção ───────────────────────────

    public function test_corrigir_troca_o_item_e_reescreve_o_valor(): void
    {
        $venda = $this->vender(['LA001'], $this->credito);       // 45,00 + taxa

        app(SaleService::class)->corrigir(
            $venda, $this->ids(['LB001']), $this->credito->id, 'Fulano'
        );

        $venda->refresh();
        $this->assertSame('36.00', $venda->valor_bruto, 'correção reescreve mesmo');
        $this->assertSame('1.62', $venda->taxa_valor);           // 4,5% de 36,00
        $this->assertSame('Fulano', $venda->comprador);
        $this->assertNotNull($venda->corrigida_em);
        $this->assertSame(Copy::DISPONIVEL, $this->statusDe('LA001'));
        $this->assertSame(Copy::VENDIDO, $this->statusDe('LB001'));
    }

    public function test_corrigir_a_forma_de_pagamento_recalcula_a_taxa(): void
    {
        $venda = $this->vender(['LA001'], $this->credito);
        $this->assertSame('2.03', $venda->taxa_valor);

        app(SaleService::class)->corrigir($venda, $this->ids(['LA001']), $this->pix->id);

        $this->assertSame('0.00', $venda->fresh()->taxa_valor, 'era PIX o tempo todo');
    }

    /**
     * Manter o mesmo exemplar na correção não pode esbarrar na própria trava
     * de estoque: sem liberar antes de travar, o exemplar apareceria como
     * "já vendido" para a operação que está justamente mantendo-o.
     */
    public function test_corrigir_mantendo_um_item_e_acrescentando_outro(): void
    {
        $venda = $this->vender(['LA001']);

        app(SaleService::class)->corrigir(
            $venda, $this->ids(['LA001', 'LB001']), $this->pix->id
        );

        $this->assertSame('81.00', $venda->fresh()->valor_bruto);
        $this->assertSame(2, $venda->items()->whereNull('cancelado_em')->count());
        $this->assertSame(Copy::VENDIDO, $this->statusDe('LA001'));
    }

    /**
     * Recalcular o valor sobre os itens de hoje incluiria o que entrou por
     * troca, e `valor_bruto` deixaria de significar "o que passou no meio de
     * pagamento" — que é justamente o que a troca preservou.
     */
    public function test_corrigir_venda_com_troca_e_recusado(): void
    {
        $venda = $this->vender(['LA001']);
        $this->trocar($venda, ['LA001'], ['LB001']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('troca registrada');

        app(SaleService::class)->corrigir($venda->fresh(), $this->ids(['LC001']), $this->pix->id);
    }

    public function test_corrigir_para_lista_vazia_e_recusado(): void
    {
        $venda = $this->vender(['LA001']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('estorno');

        app(SaleService::class)->corrigir($venda, [], $this->pix->id);
    }

    public function test_corrigir_venda_estornada_e_recusado(): void
    {
        $venda = $this->vender(['LA001']);
        app(SaleService::class)->estornar($venda);

        $this->expectException(RuntimeException::class);

        app(SaleService::class)->corrigir($venda->fresh(), $this->ids(['LB001']), $this->pix->id);
    }

    /**
     * Item que saiu por correção, por troca e por estorno ficam todos com
     * cancelado_em — e o motivo se lê por ausência, sem coluna a manter.
     */
    public function test_o_motivo_da_saida_do_item_e_legivel(): void
    {
        $vendaTroca = $this->vender(['LA001']);
        $this->trocar($vendaTroca, ['LA001'], ['LB001']);

        $vendaCorrigida = $this->vender(['LA002']);
        app(SaleService::class)->corrigir($vendaCorrigida, $this->ids(['LB002']), $this->pix->id);

        $vendaEstornada = $this->vender(['LA003']);
        app(SaleService::class)->estornar($vendaEstornada);

        $saida = fn (Sale $v, string $cod) => $v->items()
            ->where('copy_id', $this->copies[$cod]->id)->first()->motivoDaSaida();

        $this->assertSame('troca',    $saida($vendaTroca, 'LA001'));
        $this->assertSame('correcao', $saida($vendaCorrigida, 'LA002'));
        $this->assertSame('estorno',  $saida($vendaEstornada, 'LA003'));
    }
}
