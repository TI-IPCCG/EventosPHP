<?php

namespace Tests\Feature\Livraria;

use App\Models\Event;
use App\Models\Livraria\Category;
use App\Models\Livraria\Copy;
use App\Models\Livraria\PaymentMethod;
use App\Models\Livraria\Product;
use App\Models\Livraria\Shipment;
use App\Models\Livraria\ShipmentItem;
use App\Models\Livraria\Supplier;
use App\Models\Livraria\Writeoff;
use App\Models\Livraria\WriteoffReason;
use App\Models\Membership;
use App\Models\Permission;
use App\Models\SystemRole;
use App\Models\User;
use App\Services\Livraria\EventResult;
use App\Services\Livraria\SaleService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Baixa sem venda pela tela.
 *
 * O serviço existia desde o início e nunca teve interface — o exemplar
 * sorteado ficava eternamente "disponível" no saldo, ou alguém o "vendia" por
 * R$ 0, inventando uma venda que nunca houve.
 *
 * O que estes testes travam, além do óbvio: que o MOTIVO decide o custo (e que
 * o valor é snapshot), que o exemplar sai mesmo do estoque, e que consultar
 * não é o mesmo que poder dar baixa.
 */
class TelaDeBaixasTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = ['mysql'];

    private Event $evento;
    private User $coordenador;
    private WriteoffReason $sorteio;      // gera custo
    private WriteoffReason $daEditora;    // não gera
    private array $copies = [];

    protected function setUp(): void
    {
        parent::setUp();
        session(['church_id' => 1]);
        $this->montarCenario();
        session(['event_id' => $this->evento->id]);

        $this->coordenador = $this->pessoaCom(['livraria.ver', 'livraria.baixar']);
    }

    private function pessoaCom(array $slugs): User
    {
        foreach ($slugs as $slug) {
            Permission::firstOrCreate(['slug' => $slug], ['description' => $slug]);
        }

        $perfil = SystemRole::create(['church_id' => 1, 'name' => 'P'.uniqid()]);
        $perfil->permissions()->sync(Permission::whereIn('slug', $slugs)->pluck('id'));

        $u = User::create(['name' => 'Operador', 'email' => 'b'.uniqid().'@ipccg.org.br',
            'password' => 'segredo123']);

        Membership::create(['user_id' => $u->id, 'church_id' => 1,
            'system_role_id' => $perfil->id, 'status' => true, 'created_at' => now()]);

        return $u;
    }

    private function montarCenario(): void
    {
        $sufixo = 'b'.uniqid();

        $cat  = Category::create(['church_id' => 1, 'nome' => 'Livro B',
            'slug' => "livro-$sufixo", 'usa_variacao' => false]);
        $forn = Supplier::create(['church_id' => 1, 'nome' => 'Editora B',
            'prefixo' => bin2hex(random_bytes(2)), 'condicao_padrao' => 'consignado']);

        $this->evento = Event::create(['church_id' => 1, 'nome' => 'Simpósio',
            'inicio' => today(), 'status' => 'em_andamento']);

        $this->sorteio = WriteoffReason::create(['church_id' => 1,
            'nome' => 'Sorteio '.$sufixo, 'gera_custo' => true, 'ativo' => true]);
        $this->daEditora = WriteoffReason::create(['church_id' => 1,
            'nome' => 'Doação da editora '.$sufixo, 'gera_custo' => false, 'ativo' => true]);

        $remessa = Shipment::create(['event_id' => $this->evento->id,
            'supplier_id' => $forn->id, 'condicao' => 'consignado']);

        $produto = Product::create(['church_id' => 1, 'category_id' => $cat->id,
            'supplier_id' => $forn->id, 'nome' => 'Título Sorteável']);

        $item = ShipmentItem::create(['shipment_id' => $remessa->id,
            'product_id' => $produto->id, 'quantidade' => 8,
            'custo_unitario' => 30.00, 'preco_venda' => 45.00]);

        for ($i = 1; $i <= 8; $i++) {
            $codigo = sprintf('B%s%03d', $sufixo, $i);
            $this->copies["B{$i}"] = Copy::create(['event_id' => $this->evento->id,
                'shipment_item_id' => $item->id, 'codigo' => $codigo]);
        }
    }

    private function tela(?User $quem = null)
    {
        return Livewire::actingAs($quem ?? $this->coordenador)->test('livraria.baixas');
    }

    private function darBaixa(array $chaves, ?WriteoffReason $motivo = null)
    {
        $componente = $this->tela()
            ->call('escolherMotivo', ($motivo ?? $this->sorteio)->id)
            ->set('autorizado_por', 'Pr. Fulano');

        foreach ($chaves as $c) {
            $componente->call('adicionarExemplar', $this->copies[$c]->id);
        }

        return $componente->call('registrar');
    }

    // ─────────────────────────── registrar ───────────────────────────

    public function test_baixa_tira_o_exemplar_do_estoque(): void
    {
        $this->darBaixa(['B1'])->assertHasNoErrors();

        $this->assertSame(Copy::BAIXADO, Copy::find($this->copies['B1']->id)->status);
        $this->assertSame(1, Writeoff::where('event_id', $this->evento->id)->count());
    }

    /** Sortear três informa motivo e autorização UMA vez, não três. */
    public function test_uma_baixa_leva_varios_exemplares(): void
    {
        $this->darBaixa(['B1', 'B2', 'B3'])->assertHasNoErrors();

        $baixa = Writeoff::where('event_id', $this->evento->id)->first();

        $this->assertSame(3, $baixa->items()->count());
        $this->assertSame('Pr. Fulano', $baixa->autorizado_por);
    }

    /**
     * O exemplar consignado que sai não volta para a editora: é devido igual
     * ao vendido, mas sem receita nenhuma. É a razão de a baixa existir.
     */
    public function test_motivo_com_custo_entra_no_devido_ao_fornecedor(): void
    {
        $this->darBaixa(['B1', 'B2']);            // 2 × 30,00

        $apuracao = EventResult::para($this->evento);

        $this->assertSame(60.0, $apuracao->devidoFornecedores());
        $this->assertSame(0.0, $apuracao->receita(), 'baixa não é venda');
    }

    public function test_motivo_sem_custo_nao_gera_divida(): void
    {
        $this->darBaixa(['B1'], $this->daEditora);

        $this->assertSame(0.0, EventResult::para($this->evento)->devidoFornecedores());
        $this->assertSame(Copy::BAIXADO, Copy::find($this->copies['B1']->id)->status);
    }

    /**
     * `gera_custo` é copiado para o item. Desmarcar o motivo depois não pode
     * mexer no acerto de um evento já fechado.
     */
    public function test_o_custo_e_snapshot_do_momento(): void
    {
        $this->darBaixa(['B1']);

        $this->sorteio->update(['gera_custo' => false]);

        $this->assertSame(30.0, EventResult::para($this->evento)->devidoFornecedores());
    }

    /** A conta aparece ANTES de confirmar: é o que muda a decisão de quem autoriza. */
    public function test_a_previa_mostra_quanto_vai_custar(): void
    {
        $componente = $this->tela()
            ->call('escolherMotivo', $this->sorteio->id)
            ->call('adicionarExemplar', $this->copies['B1']->id)
            ->call('adicionarExemplar', $this->copies['B2']->id);

        $this->assertSame(60.0, $componente->instance()->custoPrevisto);
        $this->assertSame(0, Writeoff::where('event_id', $this->evento->id)->count(), 'nada gravado só por calcular');
    }

    public function test_previa_de_motivo_sem_custo_e_zero(): void
    {
        $componente = $this->tela()
            ->call('escolherMotivo', $this->daEditora->id)
            ->call('adicionarExemplar', $this->copies['B1']->id);

        $this->assertSame(0.0, $componente->instance()->custoPrevisto);
    }

    // ─────────────────────────── recusas ───────────────────────────

    public function test_exige_motivo_autorizacao_e_exemplar(): void
    {
        $this->tela()->call('registrar')
            ->assertHasErrors(['reason_id', 'autorizado_por', 'escolhidos']);

        $this->assertSame(0, Writeoff::where('event_id', $this->evento->id)->count());
    }

    /** Outro voluntário vendeu entre a busca e o toque: avisa, não estoura. */
    public function test_exemplar_vendido_no_meio_do_caminho_avisa(): void
    {
        $forma = PaymentMethod::create(['event_id' => $this->evento->id,
            'nome' => 'PIX '.uniqid(), 'taxa_percentual' => 0, 'ordem' => 1]);

        $componente = $this->tela()
            ->call('escolherMotivo', $this->sorteio->id)
            ->set('autorizado_por', 'Pr. Fulano')
            ->call('adicionarExemplar', $this->copies['B1']->id);

        app(SaleService::class)->registrar($this->evento->id, [$this->copies['B1']->id], $forma->id);

        $componente->call('registrar')->assertHasNoErrors();   // toast, não exceção

        $this->assertSame(0, Writeoff::where('event_id', $this->evento->id)->count());
    }

    // ────────────────── escolher o que sai ──────────────────
    //
    // A lista é agrupada por ITEM: 25 camisetas M são uma coisa só para quem
    // opera, e exemplar por exemplar enterrava os outros títulos sob uma
    // parede de códigos quase idênticos. O código continua importando — é ele
    // que faz a conferência física bater — mas desce um nível, para o pop-up.

    public function test_a_lista_agrupa_os_exemplares_num_item_so(): void
    {
        $estoque = $this->tela()->instance()->estoque;

        $this->assertCount(1, $estoque, 'oito exemplares do mesmo título são uma linha');
        $this->assertSame(8, (int) $estoque->first()->disponiveis);
        $this->assertSame('Título Sorteável', $estoque->first()->item);
    }

    public function test_a_contagem_desconta_o_que_ja_foi_baixado(): void
    {
        $this->darBaixa(['B1']);

        $this->assertSame(7, (int) $this->tela()->instance()->estoque->first()->disponiveis);
    }

    public function test_a_contagem_desconta_o_que_ja_esta_no_rascunho(): void
    {
        $estoque = $this->tela()
            ->call('adicionarExemplar', $this->copies['B1']->id)
            ->instance()->estoque;

        $this->assertSame(7, (int) $estoque->first()->disponiveis);
    }

    public function test_a_lista_abre_sem_busca(): void
    {
        // Na venda o mesmo: exigir que se digite deixa a tela em branco e
        // ninguém adivinha o que fazer.
        $this->assertCount(1, $this->tela()->instance()->estoque);
    }

    public function test_a_busca_filtra_por_nome_e_por_codigo(): void
    {
        $this->assertCount(1, $this->tela()->set('buscaEstoque', 'Sorteável')->instance()->estoque);
        $this->assertCount(1, $this->tela()->set('buscaEstoque', $this->copies['B1']->codigo)->instance()->estoque);
        $this->assertCount(0, $this->tela()->set('buscaEstoque', 'Inexistente')->instance()->estoque);
    }

    public function test_o_popup_lista_os_exemplares_daquela_linha(): void
    {
        $shipmentItemId = $this->copies['B1']->shipment_item_id;

        $exemplares = $this->tela()
            ->call('abrirLinha', $shipmentItemId)
            ->instance()->exemplaresDaLinha;

        $this->assertCount(8, $exemplares);
        $this->assertContains($this->copies['B1']->codigo, $exemplares->pluck('codigo')->all());
    }

    /**
     * O exemplar escolhido CONTINUA no pop-up, marcado. Sumir ao ser tocado
     * faria a lista pular sob o dedo e esconderia o que se acabou de fazer.
     */
    public function test_o_escolhido_continua_visivel_no_popup(): void
    {
        $shipmentItemId = $this->copies['B1']->shipment_item_id;

        $componente = $this->tela()
            ->call('abrirLinha', $shipmentItemId)
            ->call('adicionarExemplar', $this->copies['B1']->id);

        $codigos = $componente->instance()->exemplaresDaLinha->pluck('codigo')->all();

        $this->assertContains($this->copies['B1']->codigo, $codigos);
        $this->assertCount(8, $codigos);
    }

    public function test_o_popup_nao_oferece_exemplar_ja_baixado(): void
    {
        $this->darBaixa(['B1']);

        $codigos = $this->tela()
            ->call('abrirLinha', $this->copies['B2']->shipment_item_id)
            ->instance()->exemplaresDaLinha->pluck('codigo')->all();

        $this->assertNotContains($this->copies['B1']->codigo, $codigos);
        $this->assertCount(7, $codigos);
    }

    /**
     * Escolher NÃO limpa o filtro: quem sorteia três livros do mesmo título
     * continua na mesma lista, sem redigitar entre um e outro.
     */
    public function test_escolher_mantem_o_filtro(): void
    {
        $componente = $this->tela()
            ->set('buscaEstoque', 'Sorteável')
            ->call('abrirLinha', $this->copies['B1']->shipment_item_id)
            ->call('adicionarExemplar', $this->copies['B1']->id);

        $this->assertSame('Sorteável', $componente->instance()->buscaEstoque);
    }

    public function test_fechar_o_popup_limpa_o_filtro_e_a_linha(): void
    {
        $componente = $this->tela()
            ->set('buscaEstoque', 'Sorteável')
            ->call('abrirLinha', $this->copies['B1']->shipment_item_id)
            ->call('fecharLinha');

        $this->assertNull($componente->instance()->linhaAberta);
        $this->assertSame('', $componente->instance()->buscaEstoque);
    }

    // ─────────────────────────── cancelar ───────────────────────────

    public function test_cancelar_devolve_os_exemplares_ao_estoque(): void
    {
        $this->darBaixa(['B1', 'B2']);
        $baixa = Writeoff::where('event_id', $this->evento->id)->first();

        $this->tela()->call('cancelar', $baixa->id)->assertHasNoErrors();

        $this->assertNotNull($baixa->fresh()->cancelada_em);
        $this->assertSame(Copy::DISPONIVEL, Copy::find($this->copies['B1']->id)->status);
        $this->assertSame(0.0, EventResult::para($this->evento)->devidoFornecedores());
    }

    public function test_cancelar_duas_vezes_avisa_em_vez_de_estourar(): void
    {
        $this->darBaixa(['B1']);
        $baixa = Writeoff::where('event_id', $this->evento->id)->first();

        $this->tela()->call('cancelar', $baixa->id)->call('cancelar', $baixa->id)
            ->assertHasNoErrors();

        $this->assertSame(Copy::DISPONIVEL, Copy::find($this->copies['B1']->id)->status);
    }

    /**
     * Dois nomes, e a diferença importa: quem AUTORIZOU decidiu (texto livre,
     * pode não ter login nenhum) e quem REGISTROU foi ao app e lançou.
     */
    public function test_a_lista_mostra_quem_autorizou_e_quem_lancou(): void
    {
        $this->coordenador->update(['name' => 'Quem Lançou']);
        $this->darBaixa(['B1']);

        $this->tela()
            ->assertSee('autorizado por Pr. Fulano')
            ->assertSee('Quem Lançou');
    }

    public function test_a_lista_mostra_quem_cancelou(): void
    {
        $this->darBaixa(['B1']);
        $baixa = Writeoff::where('event_id', $this->evento->id)->first();

        $this->coordenador->update(['name' => 'Quem Cancelou']);

        $this->tela()->call('cancelar', $baixa->id)
            ->assertSee('Cancelada em')
            ->assertSee('Quem Cancelou');
    }

    // ─────────────────────────── permissões ───────────────────────────

    /**
     * Consultar não é o mesmo que poder dar baixa: quem vê a livraria abre a
     * tela, mas sem livraria.baixar não registra nem cancela.
     */
    public function test_quem_so_ve_a_livraria_consulta_mas_nao_da_baixa(): void
    {
        $curioso = $this->pessoaCom(['livraria.ver']);

        $this->actingAs($curioso)->get(route('livraria.baixas'))->assertOk();

        $this->tela($curioso)
            ->call('escolherMotivo', $this->sorteio->id)
            ->set('autorizado_por', 'Alguém')
            ->call('adicionarExemplar', $this->copies['B1']->id)
            ->call('registrar')
            ->assertForbidden();

        $this->assertSame(Copy::DISPONIVEL, Copy::find($this->copies['B1']->id)->status);
    }

    public function test_cancelar_exige_permissao(): void
    {
        $this->darBaixa(['B1']);
        $baixa = Writeoff::where('event_id', $this->evento->id)->first();

        $curioso = $this->pessoaCom(['livraria.ver']);

        $this->tela($curioso)->call('cancelar', $baixa->id)->assertForbidden();

        $this->assertNull($baixa->fresh()->cancelada_em);
    }

    public function test_quem_nao_ve_a_livraria_leva_403(): void
    {
        $estranho = $this->pessoaCom(['eventos.ver']);

        $this->actingAs($estranho)->get(route('livraria.baixas'))->assertForbidden();
    }

    // ─────────────────────────── lista ───────────────────────────

    public function test_a_lista_pagina_e_respeita_o_teto(): void
    {
        // seis baixas e página de cinco: o piso do seletor é 5, então pedir 3
        // não dividiria nada — o clamp devolveria 5 e a lista viria inteira
        foreach (['B1', 'B2', 'B3', 'B4', 'B5', 'B6'] as $c) {
            $this->darBaixa([$c]);
        }

        $r = $this->tela()->set('porPagina', 5)->instance()->baixas;

        $this->assertCount(5, $r->items());
        $this->assertSame(6, $r->total());
        $this->assertSame(2, $r->lastPage());
        $this->assertSame(50, $this->tela()->set('porPagina', 99999)->instance()->baixas->perPage());
    }

    /**
     * A hora é a de PAREDE da congregação, não a do servidor. Às 21h em Campo
     * Grande o UTC já virou o dia seguinte, e a conferência do dia não fecharia.
     */
    public function test_a_hora_registrada_e_a_da_congregacao(): void
    {
        $this->darBaixa(['B1']);

        $baixa = Writeoff::where('event_id', $this->evento->id)->first();

        $this->assertSame(
            \App\Models\Church::agora(1)->toDateString(),
            $baixa->registrada_em->toDateString(),
        );
    }
}
