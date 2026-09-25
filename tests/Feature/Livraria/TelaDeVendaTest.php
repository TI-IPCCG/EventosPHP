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
use App\Models\Livraria\Variant;
use App\Models\Membership;
use App\Models\Permission;
use App\Models\SystemRole;
use App\Models\User;
use App\Services\Livraria\EventResult;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * A tela da mesa, ponta a ponta: login com vínculo, busca, carrinho,
 * pagamento e conclusão — do jeito que o voluntário usa.
 */
class TelaDeVendaTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = ['mysql'];

    private User $voluntario;
    private Event $evento;
    private PaymentMethod $pix;
    private array $copies = [];

    protected function setUp(): void
    {
        parent::setUp();
        session(['church_id' => 1]);
        $this->criarVoluntario();
        $this->montarEvento();
        session(['event_id' => $this->evento->id]);
    }

    private function criarVoluntario(): void
    {
        foreach (['livraria.ver', 'livraria.vender'] as $slug) {
            Permission::firstOrCreate(['slug' => $slug], ['description' => $slug]);
        }

        $perfil = SystemRole::create(['church_id' => 1, 'name' => 'Operador '.uniqid()]);
        $perfil->permissions()->sync(Permission::whereIn('slug', ['livraria.ver', 'livraria.vender'])->pluck('id'));

        $this->voluntario = User::create([
            'name' => 'Voluntário', 'email' => 'vol'.uniqid().'@ipccg.org.br',
            'password' => 'segredo123',
        ]);

        Membership::create([
            'user_id' => $this->voluntario->id, 'church_id' => 1,
            'system_role_id' => $perfil->id, 'status' => true, 'created_at' => now(),
        ]);
    }

    private function montarEvento(): void
    {
        $sufixo = uniqid();

        $catCam = Category::create(['church_id' => 1, 'nome' => 'Camiseta', 'slug' => "cam-$sufixo",
            'usa_variacao' => true, 'rotulo_variacao' => 'Tamanho']);
        $catCam->fields()->create(['chave' => 'modelo', 'rotulo' => 'Modelo', 'tipo' => 'texto', 'mostrar_na_lista' => true]);

        $forn = Supplier::create(['church_id' => 1, 'nome' => 'Fornecedor', 'prefixo' => substr($sufixo, 0, 4)]);

        $camisa = Product::create(['church_id' => 1, 'category_id' => $catCam->id, 'supplier_id' => $forn->id,
            'nome' => 'Camiseta Simpósio', 'atributos' => ['modelo' => 'Básica']]);

        $this->evento = Event::create(['church_id' => 1, 'nome' => 'Simpósio',
            'inicio' => today(), 'status' => 'em_andamento']);

        $this->pix = PaymentMethod::create(['event_id' => $this->evento->id, 'nome' => 'PIX',
            'taxa_percentual' => 0, 'ordem' => 1]);
        PaymentMethod::create(['event_id' => $this->evento->id, 'nome' => 'Crédito',
            'taxa_percentual' => 4.5, 'ordem' => 2]);

        $remessa = Shipment::create(['event_id' => $this->evento->id, 'supplier_id' => $forn->id, 'condicao' => 'firme']);

        foreach (['M' => 2, 'G' => 1] as $tam => $qtd) {
            $variacao = Variant::create([
                'product_id' => $camisa->id, 'nome' => $tam,
                'ordem' => $tam === 'M' ? 1 : 2,
            ]);
            $item = ShipmentItem::create(['shipment_id' => $remessa->id, 'product_id' => $camisa->id,
                'variant_id' => $variacao->id, 'quantidade' => $qtd, 'custo_unitario' => 20, 'preco_venda' => 40]);

            for ($i = 1; $i <= $qtd; $i++) {
                $codigo = "C{$tam}00{$i}";
                $this->copies[$codigo] = Copy::create([
                    'event_id' => $this->evento->id, 'shipment_item_id' => $item->id, 'codigo' => $codigo,
                ]);
            }
        }
    }

    /**
     * Estoque extra com preços DISTINTOS, para os testes de ordem e página.
     *
     * O evento base tem duas linhas e ambas custam R$ 40 — ordenar por preço
     * ali passaria por empate, provando nada. Aqui cada linha tem um preço só
     * dela, e a ordem esperada é inequívoca.
     *
     * @return array<int, float> os preços criados, em ordem crescente
     */
    private function estocarPrecosVariados(int $quantas = 6): array
    {
        $sufixo = uniqid();

        $cat = Category::create(['church_id' => 1, 'nome' => 'Livro '.$sufixo,
            'slug' => "liv-$sufixo", 'usa_variacao' => false]);

        // uniqid() começa pelo relógio: dentro do mesmo segundo os 4 primeiros
        // caracteres batem com os do fornecedor do setUp e a UNIQUE do prefixo
        // recusa. Aleatório de verdade resolve.
        $forn = Supplier::create(['church_id' => 1, 'nome' => 'Editora '.$sufixo,
            'prefixo' => bin2hex(random_bytes(2))]);

        $remessa = Shipment::create(['event_id' => $this->evento->id,
            'supplier_id' => $forn->id, 'condicao' => 'firme']);

        $precos = [];

        for ($n = 1; $n <= $quantas; $n++) {
            $preco = $n * 10.0;
            $precos[] = $preco;

            // o nome segue o preço para que ordenar por nome e por preço deem
            // resultados distinguíveis — se coincidissem, o teste não veria erro
            $produto = Product::create(['church_id' => 1, 'category_id' => $cat->id,
                'supplier_id' => $forn->id, 'nome' => sprintf('Livro %02d', $n)]);

            $item = ShipmentItem::create(['shipment_id' => $remessa->id,
                'product_id' => $produto->id, 'quantidade' => 1,
                'custo_unitario' => $preco / 2, 'preco_venda' => $preco]);

            Copy::create(['event_id' => $this->evento->id,
                'shipment_item_id' => $item->id, 'codigo' => "L{$sufixo}{$n}"]);
        }

        return $precos;
    }

    private function tela()
    {
        return Livewire::actingAs($this->voluntario)->test('livraria.venda');
    }

    public function test_tela_carrega_com_o_evento_ativo(): void
    {
        $this->tela()
            ->assertOk()
            ->assertSee('Simpósio')
            ->assertSee('Venda');
    }

    /**
     * Sem busca, a tela JÁ mostra o estoque disponível.
     *
     * Antes exigia 3 caracteres e abria em branco — uma caixa de busca solta
     * numa página vazia, sem dizer o que fazer. Numa livraria de evento, com
     * uma dezena de títulos, tocar na lista é mais rápido que digitar.
     */
    public function test_sem_busca_a_tela_ja_lista_o_estoque(): void
    {
        $resultados = $this->tela()->instance()->resultados;

        // getCollection() e não collect(): o paginator é Arrayable, e collect()
        // sobre ele devolveria os METADADOS (current_page, data, total…) em vez
        // das linhas — somando zero e passando despercebido.
        $this->assertGreaterThan(0, $resultados->count());
        $this->assertSame(3, (int) $resultados->getCollection()->sum('disponiveis'));  // 2 M + 1 G
    }

    /** Digitar filtra a mesma lista. */
    public function test_busca_filtra_a_lista(): void
    {
        $c = $this->tela()->set('busca', 'Camiseta');
        $this->assertGreaterThan(0, $c->instance()->resultados->count());

        $c->set('busca', 'Isso não existe');
        $this->assertCount(0, $c->instance()->resultados);
    }

    /** Cada tamanho é uma linha própria — é o que responde "tem no M?". */
    public function test_busca_devolve_uma_linha_por_variacao_com_o_saldo(): void
    {
        $resultados = $this->tela()->set('busca', 'Camiseta')->instance()->resultados;

        $this->assertCount(2, $resultados);                      // M e G
        $this->assertSame('M', $resultados[0]->variacao);
        $this->assertSame(2, (int) $resultados[0]->disponiveis);
        $this->assertSame('G', $resultados[1]->variacao);
        $this->assertSame(1, (int) $resultados[1]->disponiveis);
    }

    public function test_busca_por_codigo_encontra_o_exemplar(): void
    {
        $resultados = $this->tela()->set('busca', 'CM001')->instance()->resultados;

        $this->assertCount(1, $resultados);
        $this->assertSame('M', $resultados[0]->variacao);
    }

    /** Quatro toques: buscar, tocar no item, tocar na forma, concluir. */
    public function test_venda_de_item_unico_em_quatro_toques(): void
    {
        $componente = $this->tela()
            ->set('busca', 'Camiseta')                                   // 1 digitar
            ->call('adicionar', $this->copies['CM001']->id)              // 2 tocar no item
            ->call('$set', 'payment_method_id', $this->pix->id)          // 3 tocar na forma
            ->call('revisar')                                            // 4 revisar
            ->call('concluir');                                          //   confirmar

        $componente->assertHasNoErrors();

        $this->assertSame(40.00, EventResult::para($this->evento)->receita());
        $this->assertSame(Copy::VENDIDO, Copy::find($this->copies['CM001']->id)->status);

        // carrinho limpo e busca zerada, pronto para o próximo comprador
        $this->assertSame([], $componente->get('carrinho'));
        $this->assertSame('', $componente->get('busca'));
    }

    /** Uma venda com dois itens é UMA transação, com UMA taxa. */
    public function test_carrinho_com_dois_itens_gera_uma_transacao(): void
    {
        $credito = PaymentMethod::where('event_id', $this->evento->id)->where('nome', 'Crédito')->first();

        $this->tela()
            ->call('adicionar', $this->copies['CM001']->id)
            ->call('adicionar', $this->copies['CM002']->id)
            ->call('$set', 'payment_method_id', $credito->id)
            ->call('revisar')
            ->call('concluir')
            ->assertHasNoErrors();

        $this->assertSame(1, $this->evento->sales()->count());
        $this->assertSame(80.00, EventResult::para($this->evento)->receita());
        $this->assertSame(3.60, EventResult::para($this->evento)->taxas());   // 4,5% de 80, uma vez
    }

    public function test_exige_forma_de_pagamento(): void
    {
        $this->tela()
            ->call('adicionar', $this->copies['CM001']->id)
            ->call('concluir')
            ->assertHasErrors('payment_method_id');

        $this->assertSame(0.00, EventResult::para($this->evento)->receita());
    }

    public function test_carrinho_vazio_nao_conclui(): void
    {
        $this->tela()
            ->call('$set', 'payment_method_id', $this->pix->id)
            ->call('concluir')
            ->assertHasErrors('carrinho');
    }

    /** O item já no carrinho some da busca — não dá para adicionar duas vezes. */
    public function test_item_no_carrinho_sai_dos_resultados(): void
    {
        $componente = $this->tela()
            ->call('adicionar', $this->copies['CM001']->id)
            ->set('busca', 'Camiseta');

        $m = $componente->instance()->resultados->getCollection()->firstWhere('variacao', 'M');

        $this->assertSame(1, (int) $m->disponiveis);   // 2 − 1 no carrinho
    }

    // ── ordenação e paginação ───────────────────────────────────────

    public function test_ordenar_por_preco_muda_a_ordem_da_lista(): void
    {
        $this->estocarPrecosVariados();

        $precos = fn (string $ordem) => $this->tela()
            ->set('porPagina', 50)
            ->set('ordem', $ordem)
            ->instance()->resultados->getCollection()
            ->pluck('preco')->map(fn ($p) => (float) $p)->all();

        $crescente = $precos('preco_asc');

        $this->assertSame($crescente, collect($crescente)->sort()->values()->all());
        $this->assertSame(array_reverse($crescente), $precos('preco_desc'));
    }

    public function test_ordem_padrao_e_por_nome(): void
    {
        $this->estocarPrecosVariados(3);

        $nomes = $this->tela()->set('porPagina', 50)
            ->instance()->resultados->getCollection()->pluck('item')->all();

        $this->assertSame($nomes, collect($nomes)->sort()->values()->all());
    }

    public function test_por_pagina_divide_a_lista_e_conta_o_total(): void
    {
        $this->estocarPrecosVariados(6);   // + as 2 camisetas = 8 linhas

        $r = $this->tela()->set('porPagina', 5)->instance()->resultados;

        $this->assertCount(5, $r->items(), 'a página deveria parar no tamanho pedido');
        $this->assertSame(8, $r->total(), 'o total conta a lista inteira, não a página');
        $this->assertSame(2, $r->lastPage());
    }

    /**
     * `porPagina` mora na URL, então é entrada de usuário: sem o teto, um
     * ?porPagina=100000 vira uma consulta que derruba a mesa no meio da fila.
     * E sem o piso, ?porPagina=1 vira uma lista de dezenas de páginas.
     */
    public function test_por_pagina_absurdo_e_contido_nos_limites(): void
    {
        $this->assertSame(50, $this->tela()->set('porPagina', 100000)->instance()->resultados->perPage());
        $this->assertSame(5, $this->tela()->set('porPagina', 1)->instance()->resultados->perPage());
        $this->assertSame(30, $this->tela()->set('porPagina', 30)->instance()->resultados->perPage());
    }

    /**
     * A paginação tem de sair com a view DO APP.
     *
     * Registrar Paginator::defaultView() não basta: o Livewire sobrescreve o
     * default a cada render pelo tema dele (livewire::tailwind). Sem Tailwind
     * no projeto, aquilo renderiza o SVG da seta em tamanho natural — meia
     * tela de chevron — e mostra a chave `pagination.previous` crua, porque
     * não há tradução pt-BR publicada. Foi exatamente o que apareceu em
     * produção.
     */
    public function test_a_paginacao_usa_a_view_do_app(): void
    {
        $this->estocarPrecosVariados(6);

        $this->tela()->set('porPagina', 5)
            ->assertSee('class="paginacao"', escape: false)
            ->assertSee('bi-chevron-right', escape: false)
            ->assertDontSee('pagination.previous')
            ->assertDontSee('pagination.next');
    }

    /**
     * Ordem vem da URL e vai para um ORDER BY. O mapa é fechado: valor
     * desconhecido cai no padrão em vez de chegar perto do SQL.
     */
    public function test_ordem_desconhecida_nao_quebra_a_tela(): void
    {
        $r = $this->tela()->set('ordem', "nome'; DROP TABLE liv_sales; --")->instance()->resultados;

        $this->assertGreaterThan(0, $r->total());
        $this->assertSame('Camiseta Simpósio', $r->getCollection()->first()->item);
    }

    public function test_filtrar_volta_para_a_primeira_pagina(): void
    {
        $this->estocarPrecosVariados(6);

        $componente = $this->tela()
            ->set('porPagina', 5)
            ->call('gotoPage', 2)
            ->set('busca', 'Camiseta');

        $this->assertSame(1, $componente->instance()->resultados->currentPage());
    }

    /**
     * Adicionar um item zera a busca NO SERVIDOR, o que não dispara o hook
     * updated — sem o resetPage() explícito, a mesa fica presa numa página
     * que a lista inteira nem tem mais.
     */
    public function test_adicionar_item_volta_para_a_primeira_pagina(): void
    {
        $this->estocarPrecosVariados(6);

        $componente = $this->tela()
            ->set('porPagina', 5)
            ->call('gotoPage', 2)
            ->call('adicionar', $this->copies['CM001']->id);

        $this->assertSame(1, $componente->instance()->resultados->currentPage());
    }

    /**
     * A capa vai junto para o carrinho.
     *
     * A mesa monta o pedido olhando a pilha de livros, não o texto — a capa é
     * o que confirma num relance que entrou o item certo, e a última chance de
     * pegar o engano antes de gravar. Guardada como URL no próprio carrinho
     * porque resolver a foto a cada render custaria uma consulta por linha,
     * com fila esperando e em 4G.
     */
    public function test_a_capa_acompanha_o_item_no_carrinho(): void
    {
        $produto = ShipmentItem::find($this->copies['CM001']->shipment_item_id)->product;

        \App\Models\Livraria\ProductPhoto::create([
            'product_id'    => $produto->id,
            'caminho'       => 'livraria/x/capa.jpg',
            'caminho_thumb' => 'livraria/x/capa-thumb.jpg',
            'capa'          => true,
            'ordem'         => 1,
            'created_at'    => now(),
        ]);

        $carrinho = $this->tela()
            ->call('adicionar', $this->copies['CM001']->id)
            ->instance()->carrinho;

        $item = $carrinho[$this->copies['CM001']->id];

        $this->assertNotNull($item['thumb'] ?? null);
        $this->assertStringContainsString('capa-thumb.jpg', $item['thumb']);
    }

    /** Item sem foto entra igual — a venda nunca depende de haver capa. */
    public function test_item_sem_capa_entra_no_carrinho_do_mesmo_jeito(): void
    {
        $carrinho = $this->tela()
            ->call('adicionar', $this->copies['CM001']->id)
            ->instance()->carrinho;

        $this->assertNull($carrinho[$this->copies['CM001']->id]['thumb']);
        $this->assertCount(1, $carrinho);
    }

    // ─────────────────── cardápio para a parede ───────────────────

    public function test_o_cardapio_lista_o_que_esta_em_estoque(): void
    {
        $this->estocarPrecosVariados(3);

        $this->actingAs($this->voluntario)->get(route('livraria.menu'))
            ->assertOk()
            ->assertSee('Livro 01')
            ->assertSee('10,00')
            ->assertSee('Camiseta Simpósio');
    }

    /**
     * Camiseta P, M e G pelo mesmo preço é UMA linha ("P · M · G"), não três
     * iguais: quem lê na parede quer saber o que custa quanto.
     */
    public function test_variacoes_do_mesmo_preco_viram_uma_linha_so(): void
    {
        $html = $this->actingAs($this->voluntario)->get(route('livraria.menu'))
            ->assertOk()->getContent();

        $this->assertSame(1, substr_count($html, 'Camiseta Simpósio'),
            'as duas variações deveriam ocupar uma linha só');

        // e os tamanhos aparecem dentro dela, cada um com o seu saldo
        $this->assertStringContainsString('<b>M</b>', $html);
        $this->assertStringContainsString('<b>G</b>', $html);
        $this->assertLessThan(
            mb_strpos($html, '<b>G</b>'),
            mb_strpos($html, '<b>M</b>'),
            'a ordem é a do catálogo (M antes de G), não a alfabética'
        );
    }

    /**
     * O esgotado é MARCADO, não escondido: saber que o título existe e acabou
     * é diferente de nunca ter ouvido falar dele — e quem monta a parede ainda
     * pode pedir a lista enxuta.
     */
    public function test_o_esgotado_aparece_marcado_e_pode_ser_escondido(): void
    {
        foreach ($this->copies as $copy) {
            $copy->update(['status' => Copy::VENDIDO]);
        }

        $this->actingAs($this->voluntario)->get(route('livraria.menu'))
            ->assertOk()->assertSee('Camiseta Simpósio')->assertSee('esgotado');

        $this->actingAs($this->voluntario)
            ->get(route('livraria.menu', ['itens' => 'so_disponiveis']))
            ->assertOk()->assertDontSee('Camiseta Simpósio');
    }

    /**
     * O evento guarda um nome só; o cartaz quer título e lema com pesos
     * diferentes. A última parte depois de " - " vira o lema.
     */
    public function test_o_nome_do_evento_vira_titulo_e_lema(): void
    {
        $this->evento->update(['nome' => 'Simpósio Doutrina & Vida - Teologia na Prática']);

        $this->actingAs($this->voluntario)->get(route('livraria.menu'))
            ->assertOk()
            ->assertSee('Simpósio Doutrina &amp; Vida', escape: false)
            ->assertSee('Teologia na Prática');
    }

    /** Nome sem hífen fica inteiro no título, sem lema inventado embaixo. */
    public function test_nome_sem_hifen_nao_ganha_lema(): void
    {
        $this->evento->update(['nome' => 'Encontro de Jovens']);

        $html = $this->actingAs($this->voluntario)->get(route('livraria.menu'))
            ->assertOk()->getContent();

        $this->assertStringContainsString('Encontro de Jovens', $html);
        $this->assertStringNotContainsString('class="lema"', $html);
    }

    /**
     * Capas LIGADAS por padrão: quem abre o cardápio identifica o item pela
     * capa, e os controles no alto são do operador que vai imprimir — não do
     * participante. Um padrão que exige mexer em seletor para ficar bom é um
     * padrão errado.
     */
    public function test_as_capas_aparecem_por_padrao_e_podem_ser_desligadas(): void
    {
        $produto = ShipmentItem::find($this->copies['CM001']->shipment_item_id)->product;

        \App\Models\Livraria\ProductPhoto::create([
            'product_id' => $produto->id, 'caminho' => 'livraria/x/capa.jpg',
            'caminho_thumb' => 'livraria/x/capa-thumb.jpg', 'capa' => true,
            'ordem' => 1, 'created_at' => now(),
        ]);

        // sem pedir nada — e também no cardápio público
        $this->actingAs($this->voluntario)->get(route('livraria.menu'))
            ->assertOk()->assertSee('capa-thumb.jpg');

        session()->flush();
        $this->get(route('livraria.cardapio'))->assertOk()->assertSee('capa-thumb.jpg');

        // quem vai imprimir e quer economizar papel desliga
        $this->actingAs($this->voluntario)->get(route('livraria.menu', ['capas' => '0']))
            ->assertOk()->assertDontSee('capa-thumb.jpg');
    }

    // ─────────── destaque e ampliação no cardápio ───────────

    private function comCapa(string $produtoNome = 'Camiseta Simpósio'): \App\Models\Livraria\Product
    {
        $produto = \App\Models\Livraria\Product::where('nome', $produtoNome)->firstOrFail();

        \App\Models\Livraria\ProductPhoto::create([
            'product_id' => $produto->id, 'caminho' => 'livraria/x/arte.jpg',
            'caminho_thumb' => 'livraria/x/arte-thumb.jpg', 'capa' => true,
            'ordem' => 1, 'created_at' => now(),
        ]);

        return $produto;
    }

    /**
     * A capa carregada é sempre a MINIATURA; a grande fica guardada no botão e
     * só é baixada quando alguém amplia. O cardápio abre no 4G do evento, e
     * trinta imagens de 1200px na entrada seriam um cartaz que ninguém espera.
     */
    public function test_a_lista_usa_a_miniatura_e_guarda_a_grande_para_a_ampliacao(): void
    {
        $this->comCapa();

        $html = $this->actingAs($this->voluntario)
            ->get(route('livraria.menu', ['capas' => '1']))->assertOk()->getContent();

        $this->assertStringContainsString('<img class="capa" src="', $html);
        $this->assertStringContainsString('arte-thumb.jpg', $html);
        $this->assertStringContainsString('data-grande="', $html);
        $this->assertStringContainsString('arte.jpg', $html);
    }

    /**
     * O destaque sai de um campo da CATEGORIA (booleano `destaque_cardapio`),
     * não de coluna nova: quem decide é quem cadastra, pela tela de Categorias.
     */
    public function test_item_marcado_ganha_a_capa_dobrada(): void
    {
        $produto = $this->comCapa();

        $html = fn () => $this->actingAs($this->voluntario)
            ->get(route('livraria.menu', ['capas' => '1']))->assertOk()->getContent();

        $this->assertStringNotContainsString('item  destaque', $html());

        $produto->update(['atributos' => array_merge(
            $produto->atributos ?? [], ['destaque_cardapio' => true]
        )]);

        $this->assertMatchesRegularExpression('/class="item\s*\w*\s*destaque"/', $html());
    }

    /**
     * Procura `data-grande=`, e não a classe: "capa-toque" aparece no <style>
     * da própria página, então a asserção pela classe passaria verde com
     * qualquer coisa — o mesmo engano de procurar texto que mora no CSS.
     */
    /**
     * O campo de destaque é um campo como qualquer outro: marcado como
     * "mostrar na lista" no catálogo, seu valor viria junto dos destaques e a
     * camiseta sairia com "— Sim" pendurado no nome. É instrução para o
     * cardápio, não informação sobre o produto.
     */
    public function test_a_marca_de_destaque_nao_vira_texto_no_cardapio(): void
    {
        $produto = $this->comCapa();

        $produto->category->fields()->create([
            'chave' => 'destaque_cardapio', 'rotulo' => 'Destaque cardápio',
            'tipo' => 'booleano', 'mostrar_na_lista' => true, 'ordem' => 9,
        ]);

        $produto->update(['atributos' => array_merge(
            $produto->atributos ?? [], ['destaque_cardapio' => true]
        )]);

        $html = $this->actingAs($this->voluntario)
            ->get(route('livraria.menu', ['capas' => '1']))->assertOk()->getContent();

        // a capa dobra…
        $this->assertMatchesRegularExpression('/class="item\s*\w*\s*destaque"/', $html);
        // …mas o "Sim" não aparece ao lado do nome
        $this->assertStringNotContainsString('class="detalhe">— Sim', $html);
    }

    /**
     * Quase ninguém tenta tocar numa miniatura de 34px. Sem o nome como gatilho
     * e sem a lupa ao lado, o recurso não existe para quem não experimentou —
     * então os dois são parte do recurso, não enfeite.
     */
    public function test_o_nome_tambem_abre_a_imagem_e_a_lupa_avisa(): void
    {
        $this->comCapa();

        $html = $this->actingAs($this->voluntario)
            ->get(route('livraria.menu'))->assertOk()->getContent();

        $this->assertStringContainsString('nome-toque abre-lupa', $html);
        $this->assertStringContainsString('capa-toque abre-lupa', $html);
        $this->assertStringContainsString('lupa-icone', $html);
        $this->assertStringContainsString('Toque na foto ou no nome', $html);
    }

    /**
     * Item sem foto não vira botão: não há o que ampliar.
     *
     * Procura `data-grande=`, e não a classe — "abre-lupa" aparece no
     * JavaScript da própria página, e a asserção passaria verde com qualquer
     * coisa. É o mesmo engano de procurar texto que mora no CSS.
     */
    public function test_item_sem_foto_nao_ganha_gatilho(): void
    {
        $html = $this->actingAs($this->voluntario)
            ->get(route('livraria.menu'))->assertOk()->getContent();

        $this->assertStringNotContainsString('data-grande=', $html);
        $this->assertStringNotContainsString('<svg class="lupa-icone"', $html);
    }

    public function test_sem_capa_nao_ha_o_que_ampliar(): void
    {
        $html = $this->actingAs($this->voluntario)
            ->get(route('livraria.menu', ['capas' => '1']))->assertOk()->getContent();

        $this->assertStringNotContainsString('data-grande=', $html, 'item sem foto não vira botão');

        // e com foto, vira
        $this->comCapa();

        $this->assertStringContainsString('data-grande=', $this->actingAs($this->voluntario)
            ->get(route('livraria.menu', ['capas' => '1']))->getContent());
    }

    public function test_o_cardapio_exige_ver_a_livraria(): void
    {
        $estranho = User::create(['name' => 'X', 'email' => 'm'.uniqid().'@ipccg.org.br',
            'password' => 'segredo123']);
        Membership::create(['user_id' => $estranho->id, 'church_id' => 1,
            'status' => true, 'created_at' => now()]);

        $this->actingAs($estranho)->get(route('livraria.menu'))->assertForbidden();
    }

    // ─────────────── cardápio público (sem login) ───────────────
    //
    // ⚠ session()->flush() em TODOS estes testes, e não é zelo: o setUp grava
    // church_id/event_id na sessão, e sem limpar o ChurchScope continuaria
    // resolvendo tudo direitinho — o teste passaria verde enquanto a página
    // real, aberta por quem nunca logou, viria vazia. Foi assim que a
    // credencial pública passou nos testes e quebrou em produção.

    public function test_o_cardapio_publico_abre_sem_login(): void
    {
        session()->flush();

        $this->get(route('livraria.cardapio'))
            ->assertOk()
            ->assertSee('Camiseta Simpósio')
            ->assertSee('Simpósio');
    }

    public function test_o_cardapio_publico_acha_o_evento_sem_sessao(): void
    {
        session()->flush();

        // o preço vem da remessa: se o escopo tivesse zerado a lista, não
        // haveria preço nenhum para ver
        $this->get(route('livraria.cardapio'))->assertOk()->assertSee('40,00');
    }

    /** O que é público é o que já estaria na parede — nada além disso. */
    public function test_o_cardapio_publico_nao_expoe_custo_nem_fornecedor(): void
    {
        session()->flush();

        $html = $this->get(route('livraria.cardapio'))->assertOk()->getContent();

        $this->assertStringNotContainsString('20,00', $html, 'custo unitário não pode aparecer');
        $this->assertStringNotContainsString('Fornecedor', $html);
    }

    public function test_o_cardapio_publico_nao_mostra_os_controles_do_operador(): void
    {
        session()->flush();

        $this->get(route('livraria.cardapio'))
            ->assertOk()
            ->assertDontSee('Voltar à venda')
            ->assertDontSee('window.print()', escape: false);
    }

    /** Link para mandar no grupo, não página para o Google guardar. */
    public function test_o_cardapio_publico_pede_para_nao_ser_indexado(): void
    {
        session()->flush();

        $this->get(route('livraria.cardapio'))
            ->assertOk()->assertSee('name="robots" content="noindex"', escape: false);
    }

    /**
     * O cardápio precisa existir ANTES do evento abrir — é a semana em que as
     * pessoas querem ver a lista, e até a abertura o evento está em
     * `planejamento`. Olhar só `em_andamento` deixava a página no ar dizendo
     * "nenhum evento", que foi o que apareceu em produção.
     */
    public function test_o_cardapio_publico_funciona_com_evento_em_planejamento(): void
    {
        \App\Models\Event::withoutGlobalScopes()->emAndamento()
            ->update(['status' => 'planejamento']);

        session()->flush();

        $this->get(route('livraria.cardapio'))
            ->assertOk()
            ->assertSee('Camiseta Simpósio')
            ->assertDontSee('Nenhum evento em andamento');
    }

    /**
     * ⚠ Encerra TODOS os eventos em andamento, não só o do cenário: o visitante
     * não tem congregação na sessão, então a busca corre sem o ChurchScope e
     * alcança evento de qualquer igreja — inclusive os que já estão no banco.
     * É a mesma propriedade que faz a página funcionar deslogada.
     */
    /* Não há teste do caso "nenhum evento existe": o aviso só aparece com a
       tabela `events` vazia, e esvaziá-la aqui esbarra na FK RESTRICT de
       liv_sale_items — o banco de teste é o de desenvolvimento e tem vendas
       reais. Isolar isso custaria RefreshDatabase na classe inteira para
       cobrir o estado de uma instalação recém-criada; a view trata o nulo com
       `$evento?->` e um @empty. */

    // ─────────────── saldo por tamanho ───────────────

    /**
     * Cada tamanho diz se DÁ para levar, não quantos restam.
     *
     * O número exato não muda a decisão de quem consulta — e numa página
     * pública ele ainda vira pressão ("só tem 1!") e envelhece a cada venda.
     */
    public function test_cada_tamanho_diz_se_esta_disponivel(): void
    {
        $html = $this->actingAs($this->voluntario)->get(route('livraria.menu'))
            ->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/<b>M<\/b>\s*<span class="estado">\s*Disponível/u', $html);
        $this->assertStringNotContainsString('class="qtd"', $html, 'a quantidade não deve aparecer');
    }

    public function test_tamanho_indisponivel_aparece_marcado_e_nao_sumido(): void
    {
        $this->copies['CG001']->update(['status' => Copy::VENDIDO]);

        $html = $this->actingAs($this->voluntario)->get(route('livraria.menu'))
            ->assertOk()->getContent();

        $this->assertStringContainsString('<b>G</b>', $html, 'o tamanho continua listado');
        $this->assertMatchesRegularExpression('/<b>G<\/b>\s*<span class="estado">\s*Indisponível/u', $html);
    }

    // ─────────────────── busca no cardápio ───────────────────

    public function test_o_cardapio_traz_o_campo_de_busca(): void
    {
        session()->flush();

        $this->get(route('livraria.cardapio'))
            ->assertOk()
            ->assertSee('id="busca"', escape: false)
            ->assertSee('Buscar por título, autor ou tamanho');
    }

    /**
     * O texto pesquisável é normalizado no SERVIDOR (minúsculo, sem acento):
     * assim o JS só compara strings, e buscar "simposio" acha "Simpósio" sem o
     * celular normalizar a lista inteira a cada tecla.
     */
    public function test_cada_item_carrega_o_texto_de_busca_normalizado(): void
    {
        $this->estocarPrecosVariados(1);

        $html = $this->actingAs($this->voluntario)->get(route('livraria.menu'))
            ->assertOk()->getContent();

        $this->assertStringContainsString('data-busca="camiseta simposio', $html);
        $this->assertStringNotContainsString('data-busca="Camiseta', $html, 'deveria estar em minúsculo');
    }

    /** O tamanho entra na busca: procurar "GG" tem de achar a camiseta. */
    public function test_o_texto_de_busca_inclui_os_tamanhos(): void
    {
        $html = $this->actingAs($this->voluntario)->get(route('livraria.menu'))
            ->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/data-busca="[^"]*\bm g\b/', $html);
    }

    /** Dentro da categoria, o que dá para levar vem primeiro. */
    public function test_o_esgotado_desce_para_o_fim_da_lista(): void
    {
        $this->estocarPrecosVariados(2);        // Livro 01 e Livro 02

        $livro01 = \App\Models\Livraria\Product::where('nome', 'Livro 01')->first();
        Copy::whereHas('shipmentItem', fn ($q) => $q->where('product_id', $livro01->id))
            ->update(['status' => Copy::VENDIDO]);

        $html = $this->actingAs($this->voluntario)->get(route('livraria.menu'))
            ->assertOk()->getContent();

        $this->assertGreaterThan(
            mb_strpos($html, 'Livro 02'),
            mb_strpos($html, 'Livro 01'),
            'o esgotado deveria vir depois do que está disponível'
        );
    }

    /** Outro voluntário vendeu entre a busca e o toque: avisa, não falha calado. */
    public function test_exemplar_vendido_por_outro_avisa_em_vez_de_estourar(): void
    {
        $copy = $this->copies['CM001'];
        Copy::where('id', $copy->id)->update(['status' => Copy::VENDIDO]);

        $componente = $this->tela()->call('adicionar', $copy->id);

        $componente->assertDispatched('toast', tipo: 'aviso');
        $this->assertSame([], $componente->get('carrinho'));   // não entrou no carrinho
    }

    // ─────────────── confirmação e avisos ───────────────

    /** Adicionar e remover do carrinho avisam — antes era silencioso. */
    public function test_carrinho_avisa_ao_adicionar_e_ao_remover(): void
    {
        $id = $this->copies['CM001']->id;

        $this->tela()
            ->call('adicionar', $id)
            ->assertDispatched('toast', tipo: 'ok')
            ->call('remover', $id)
            ->assertDispatched('toast', tipo: 'info');
    }

    /** "Concluir" abre a revisão; NADA é gravado antes de confirmar. */
    public function test_concluir_abre_a_revisao_sem_gravar(): void
    {
        $componente = $this->tela()
            ->call('adicionar', $this->copies['CM001']->id)
            ->call('$set', 'payment_method_id', $this->pix->id)
            ->call('revisar');

        $this->assertTrue($componente->get('confirmando'));
        $this->assertSame(0, $this->evento->sales()->count());
        $this->assertSame(Copy::DISPONIVEL, Copy::find($this->copies['CM001']->id)->status);
    }

    /** O resumo mostra itens, taxa em separado e o total com a taxa. */
    public function test_resumo_da_confirmacao_detalha_itens_e_taxa(): void
    {
        $credito = PaymentMethod::where('event_id', $this->evento->id)->where('nome', 'Crédito')->first();

        $resumo = $this->tela()
            ->call('adicionar', $this->copies['CM001']->id)
            ->call('adicionar', $this->copies['CM002']->id)
            ->call('$set', 'payment_method_id', $credito->id)
            ->call('revisar')
            ->instance()->resumo;

        $this->assertCount(2, $resumo['itens']);
        $this->assertSame(80.00, $resumo['subtotal']);
        $this->assertSame(3.60, $resumo['taxa']);          // 4,5% de 80
        $this->assertSame(83.60, $resumo['total']);
        $this->assertSame('Crédito', $resumo['forma']);
    }

    public function test_cancelar_a_revisao_mantem_o_carrinho(): void
    {
        $componente = $this->tela()
            ->call('adicionar', $this->copies['CM001']->id)
            ->call('$set', 'payment_method_id', $this->pix->id)
            ->call('revisar')
            ->call('cancelarRevisao');

        $this->assertFalse($componente->get('confirmando'));
        $this->assertCount(1, $componente->get('carrinho'));
        $this->assertSame(0, $this->evento->sales()->count());
    }

    /** Documento entra junto do nome na identificação do comprador. */
    public function test_documento_do_comprador_e_gravado(): void
    {
        $this->tela()
            ->call('adicionar', $this->copies['CM001']->id)
            ->set('comprador', 'Maria')
            ->set('documento', '123.456.789-00')
            ->call('$set', 'payment_method_id', $this->pix->id)
            ->call('revisar')
            ->call('concluir')
            ->assertDispatched('toast', tipo: 'ok');

        $this->assertSame('Maria · 123.456.789-00', $this->evento->sales()->value('comprador'));
    }

    public function test_sem_permissao_de_vender_recebe_403(): void
    {
        $intruso = User::create([
            'name' => 'Sem acesso', 'email' => 'x'.uniqid().'@ipccg.org.br', 'password' => 'segredo123',
        ]);
        Membership::create(['user_id' => $intruso->id, 'church_id' => 1, 'status' => true, 'created_at' => now()]);

        $this->actingAs($intruso)->get(route('livraria.venda'))->assertForbidden();
    }

    public function test_visitante_e_mandado_para_o_login(): void
    {
        $this->get(route('livraria.venda'))->assertRedirect(route('login'));
    }
}
