<?php

namespace Tests\Feature\Livraria;

use App\Models\Event;
use App\Models\Livraria\Category;
use App\Models\Livraria\Copy;
use App\Models\Livraria\EventCost;
use App\Models\Livraria\PaymentMethod;
use App\Models\Livraria\Product;
use App\Models\Livraria\Sale;
use App\Models\Livraria\Shipment;
use App\Models\Livraria\ShipmentItem;
use App\Models\Livraria\Supplier;
use App\Models\Livraria\Variant;
use App\Models\Livraria\Writeoff;
use App\Models\Livraria\WriteoffReason;
use App\Services\Livraria\EventResult;
use App\Services\Livraria\SaleService;
use App\Services\Livraria\WriteoffService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use RuntimeException;
use Tests\TestCase;

/**
 * Cenário de referência do módulo, com LIVRO e CAMISETA:
 *
 *   Livro A   custo 30,00  venda 45,00   5 exemplares   LA001..LA005
 *   Livro B   custo 24,00  venda 36,00   5 exemplares   LB001..LB005
 *   Camiseta  custo 20,00  venda 40,00   P:2  M:3  G:2  CP/CM/CG
 *   Frete R$ 200,00        PIX 0%        Crédito 4,5%
 */
class ResultadoDoEventoTest extends TestCase
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

    // ─────────────────────────── cenário ───────────────────────────

    private function montarCenario(): void
    {
        $sufixo   = 't'.uniqid();
        $catLivro = Category::create(['church_id' => 1, 'nome' => 'Livro T', 'slug' => "livro-$sufixo", 'usa_variacao' => false]);
        $catCam   = Category::create(['church_id' => 1, 'nome' => 'Camiseta T', 'slug' => "camiseta-$sufixo",
                                      'usa_variacao' => true, 'rotulo_variacao' => 'Tamanho']);

        $catLivro->fields()->create(['chave' => 'autor', 'rotulo' => 'Autor', 'tipo' => 'texto', 'mostrar_na_lista' => true]);
        $catCam->fields()->create(['chave' => 'modelo', 'rotulo' => 'Modelo', 'tipo' => 'texto', 'mostrar_na_lista' => true]);
        $catCam->fields()->create(['chave' => 'cor', 'rotulo' => 'Cor', 'tipo' => 'texto', 'mostrar_na_lista' => true]);

        $editora   = Supplier::create(['church_id' => 1, 'nome' => 'Editora T',   'prefixo' => substr($sufixo, 0, 4), 'condicao_padrao' => 'consignado']);
        $confeccao = Supplier::create(['church_id' => 1, 'nome' => 'Confecção T', 'prefixo' => substr($sufixo, 1, 4), 'condicao_padrao' => 'firme']);

        $livroA = Product::create(['church_id' => 1, 'category_id' => $catLivro->id, 'supplier_id' => $editora->id,
            'nome' => 'Título A', 'preco_referencia' => 50, 'atributos' => ['autor' => 'Autor A', 'num_paginas' => 224]]);
        $livroB = Product::create(['church_id' => 1, 'category_id' => $catLivro->id, 'supplier_id' => $editora->id,
            'nome' => 'Título B', 'preco_referencia' => 40, 'atributos' => ['autor' => 'Autor B']]);
        $camisa = Product::create(['church_id' => 1, 'category_id' => $catCam->id, 'supplier_id' => $confeccao->id,
            'nome' => 'Camiseta do Evento', 'preco_referencia' => 45, 'atributos' => ['modelo' => 'Básica', 'cor' => 'Preta']]);

        $this->evento = Event::create(['church_id' => 1, 'nome' => 'Evento de Teste',
            'inicio' => today(), 'status' => 'em_andamento']);

        $this->pix     = PaymentMethod::create(['event_id' => $this->evento->id, 'nome' => 'PIX',     'taxa_percentual' => 0,   'ordem' => 1]);
        $this->credito = PaymentMethod::create(['event_id' => $this->evento->id, 'nome' => 'Crédito', 'taxa_percentual' => 4.5, 'ordem' => 2]);

        EventCost::create(['event_id' => $this->evento->id, 'supplier_id' => $editora->id,
            'descricao' => 'Frete ida e volta', 'valor' => 200, 'rateio' => 'direto']);

        $remLivros = Shipment::create(['event_id' => $this->evento->id, 'supplier_id' => $editora->id,   'condicao' => 'consignado']);
        $remCamis  = Shipment::create(['event_id' => $this->evento->id, 'supplier_id' => $confeccao->id, 'condicao' => 'firme']);

        $this->gerar($remLivros, $livroA, null, 5, 30, 45, 'LA');
        $this->gerar($remLivros, $livroB, null, 5, 24, 36, 'LB');

        foreach (['P' => 2, 'M' => 3, 'G' => 2] as $tam => $qtd) {
            $variacao = Variant::create(['product_id' => $camisa->id, 'nome' => $tam]);
            $this->gerar($remCamis, $camisa, $variacao, $qtd, 20, 40, 'C'.$tam);
        }
    }

    private function gerar(Shipment $rem, Product $p, ?Variant $v, int $qtd, float $custo, float $preco, string $pref): void
    {
        $item = ShipmentItem::create([
            'shipment_id' => $rem->id, 'product_id' => $p->id, 'variant_id' => $v?->id,
            'quantidade' => $qtd, 'custo_unitario' => $custo, 'preco_venda' => $preco,
        ]);

        for ($i = 1; $i <= $qtd; $i++) {
            $codigo = sprintf('%s%03d', $pref, $i);
            $this->copies[$codigo] = Copy::create([
                'event_id' => $this->evento->id, 'shipment_item_id' => $item->id, 'codigo' => $codigo,
            ]);
        }
    }

    // ─────────────────────────── atalhos ───────────────────────────

    private function ids(array $codigos): array
    {
        return array_map(fn ($c) => $this->copies[$c]->id, $codigos);
    }

    private function vender(array $codigos, PaymentMethod $forma): Sale
    {
        return app(SaleService::class)->registrar($this->evento->id, $this->ids($codigos), $forma->id);
    }

    private function baixar(array $codigos, bool $geraCusto = true): Writeoff
    {
        $motivo = WriteoffReason::create([
            'church_id' => 1, 'nome' => 'Motivo '.uniqid(), 'gera_custo' => $geraCusto, 'ativo' => true,
        ]);

        return app(WriteoffService::class)->registrar(
            $this->evento->id, $this->ids($codigos), $motivo->id, 'Pr. Fulano'
        );
    }

    private function resultado(): EventResult
    {
        return EventResult::para($this->evento->fresh());
    }

    private function statusDe(string $codigo): string
    {
        return Copy::find($this->copies[$codigo]->id)->status;
    }

    private function saldoDisponivel(string $prefixo): int
    {
        return Copy::where('event_id', $this->evento->id)
            ->where('codigo', 'like', $prefixo.'%')
            ->where('status', Copy::DISPONIVEL)
            ->count();
    }

    // ─────────────────────────── testes ────────────────────────────

    /**
     * A conta à mão:
     *   receita   = 45 + 36 + 45 + 40                     = 166,00
     *   devido    = 30 + 24 + 30 + 20 + 24 (sorteio)      = 128,00
     *   custos    = 200,00     taxas = 2,03
     *   resultado = 166 − 128 − 200 − 2,03                = −164,03
     */
    public function test_resultado_do_evento_bate_com_a_conta_a_mao(): void
    {
        $this->vender(['LA001', 'LB001'], $this->pix);
        $this->vender(['LA002'], $this->credito);
        $this->vender(['CM001'], $this->pix);
        $this->baixar(['LB002']);

        $r = $this->resultado();

        $this->assertSame(166.00, $r->receita());
        $this->assertSame(128.00, $r->devidoFornecedores());
        $this->assertSame(200.00, $r->custos());
        $this->assertSame(2.03,   $r->taxas());
        $this->assertSame(-164.03, $r->resultado());
    }

    /** A taxa é da TRANSAÇÃO, não de cada item — o erro da planilha antiga. */
    public function test_taxa_incide_uma_vez_por_transacao(): void
    {
        $venda = $this->vender(['LA001', 'LB001'], $this->credito);   // 45 + 36 = 81

        $this->assertSame('81.00', $venda->valor_bruto);
        $this->assertSame('3.65', $venda->taxa_valor);        // 4,5% de 81
        $this->assertCount(2, $venda->items);
        $this->assertSame(3.65, $this->resultado()->taxas()); // uma taxa, não duas
    }

    /** Cada tamanho tem saldo próprio: é isso que responde "tem no M?". */
    public function test_saldo_de_cada_tamanho_e_independente(): void
    {
        $this->vender(['CM001'], $this->pix);

        $this->assertSame(2, $this->saldoDisponivel('CP'));
        $this->assertSame(2, $this->saldoDisponivel('CM'));   // 3 − 1
        $this->assertSame(2, $this->saldoDisponivel('CG'));
    }

    public function test_nao_vende_o_mesmo_exemplar_duas_vezes(): void
    {
        $this->vender(['LA001'], $this->pix);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/indispon/i');

        $this->vender(['LA001'], $this->pix);
    }

    /** Cross-tabela: vender e depois sortear o mesmo exemplar. */
    public function test_nao_baixa_exemplar_ja_vendido(): void
    {
        $this->vender(['LA001'], $this->pix);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/indispon/i');

        $this->baixar(['LA001']);
    }

    public function test_estorno_devolve_o_exemplar_e_zera_o_resultado(): void
    {
        $venda = $this->vender(['LA001'], $this->credito);

        $this->assertSame(Copy::VENDIDO, $this->statusDe('LA001'));
        $this->assertSame(45.00, $this->resultado()->receita());

        app(SaleService::class)->estornar($venda);

        $this->assertSame(Copy::DISPONIVEL, $this->statusDe('LA001'));
        $this->assertSame(0.00, $this->resultado()->receita());
        $this->assertSame(0.00, $this->resultado()->taxas());

        // e o exemplar pode ser vendido de novo
        $this->vender(['LA001'], $this->pix);
        $this->assertSame(45.00, $this->resultado()->receita());
    }

    /** Sorteio de consignado é devido ao fornecedor como se tivesse vendido. */
    public function test_baixa_com_custo_soma_ao_devido_sem_somar_receita(): void
    {
        $this->baixar(['LB001']);

        $this->assertSame(0.00,  $this->resultado()->receita());
        $this->assertSame(24.00, $this->resultado()->devidoFornecedores());
        $this->assertSame(Copy::BAIXADO, $this->statusDe('LB001'));
    }

    /** Exemplar doado pela editora sai sem gerar custo. */
    public function test_baixa_sem_custo_nao_soma_ao_devido(): void
    {
        $this->baixar(['LB001'], geraCusto: false);

        $this->assertSame(0.00, $this->resultado()->devidoFornecedores());
        $this->assertSame(Copy::BAIXADO, $this->statusDe('LB001'));
    }

    public function test_cancelar_baixa_devolve_o_exemplar(): void
    {
        $baixa = $this->baixar(['LB001']);
        $this->assertSame(24.00, $this->resultado()->devidoFornecedores());

        app(WriteoffService::class)->cancelar($baixa);

        $this->assertSame(Copy::DISPONIVEL, $this->statusDe('LB001'));
        $this->assertSame(0.00, $this->resultado()->devidoFornecedores());
    }

    /** Uma baixa, vários exemplares, motivo e autorização informados uma vez. */
    public function test_baixa_em_lote_registra_uma_autorizacao_para_varios(): void
    {
        $baixa = $this->baixar(['LB001', 'LB002', 'LB003']);

        $this->assertCount(3, $baixa->items);
        $this->assertSame('Pr. Fulano', $baixa->autorizado_por);
        $this->assertSame(72.00, $this->resultado()->devidoFornecedores());  // 3 × 24
    }

    /** A listagem não sabe o que é livro e o que é camiseta — a categoria diz. */
    public function test_destaques_da_listagem_variam_por_categoria(): void
    {
        $livro   = Product::where('nome', 'Título A')->with('category.fields')->first();
        $camiseta = Product::where('nome', 'Camiseta do Evento')->with('category.fields')->first();

        $this->assertSame([['rotulo' => 'Autor', 'valor' => 'Autor A']], $livro->destaques());
        $this->assertSame([
            ['rotulo' => 'Modelo', 'valor' => 'Básica'],
            ['rotulo' => 'Cor',    'valor' => 'Preta'],
        ], $camiseta->destaques());

        $this->assertFalse($livro->usaVariacao());
        $this->assertTrue($camiseta->usaVariacao());
    }

    /** Devolvidos não geram dívida: é o que a consignação significa. */
    public function test_devolucao_mostra_o_custo_que_nao_foi_pago(): void
    {
        $this->vender(['LA001'], $this->pix);

        $devolucao = $this->resultado()->devolucao();

        // 17 exemplares no total, 1 vendido → 16 voltam
        $this->assertSame(16, $devolucao['quantidade']);
        $this->assertGreaterThan(0, $devolucao['custo_evitado']);
    }
}
