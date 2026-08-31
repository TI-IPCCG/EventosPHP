<?php

namespace Tests\Feature\Livraria;

use App\Models\Event;
use App\Models\Livraria\CartHold;
use App\Models\Livraria\Category;
use App\Models\Livraria\Copy;
use App\Models\Livraria\PaymentMethod;
use App\Models\Livraria\Product;
use App\Models\Livraria\Shipment;
use App\Models\Livraria\ShipmentItem;
use App\Models\Livraria\Supplier;
use App\Models\Membership;
use App\Models\Permission;
use App\Models\SystemRole;
use App\Models\User;
use App\Services\Livraria\SaleService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;

/**
 * DOIS VOLUNTÁRIOS NA MESMA MESA, no mesmo exemplar.
 *
 * Documenta o que o sistema garante hoje e o que NÃO garante — o carrinho de
 * um não reserva nada para o outro.
 */
class ConcorrenciaTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = ['mysql'];

    private User $ana;
    private User $bruno;
    private Event $evento;
    private Copy $exemplar;
    private PaymentMethod $pix;

    protected function setUp(): void
    {
        parent::setUp();
        session(['church_id' => 1]);

        foreach (['livraria.ver', 'livraria.vender'] as $slug) {
            Permission::firstOrCreate(['slug' => $slug], ['description' => $slug]);
        }
        $perfil = SystemRole::create(['church_id' => 1, 'name' => 'Op '.uniqid()]);
        $perfil->permissions()->sync(Permission::whereIn('slug', ['livraria.ver', 'livraria.vender'])->pluck('id'));

        foreach (['ana', 'bruno'] as $nome) {
            $u = User::create(['name' => ucfirst($nome), 'email' => $nome.uniqid().'@ipccg.org.br',
                'password' => 'segredo123']);
            Membership::create(['user_id' => $u->id, 'church_id' => 1,
                'system_role_id' => $perfil->id, 'status' => true, 'created_at' => now()]);
            $this->{$nome} = $u;
        }

        $sufixo = uniqid();
        $cat  = Category::create(['church_id' => 1, 'nome' => 'Livro', 'slug' => "l-$sufixo"]);
        $forn = Supplier::create(['church_id' => 1, 'nome' => 'F', 'prefixo' => strtoupper(substr($sufixo, 0, 3))]);
        $prod = Product::create(['church_id' => 1, 'category_id' => $cat->id, 'supplier_id' => $forn->id,
            'nome' => 'Livro Único', 'ativo' => true]);

        $this->evento = Event::create(['church_id' => 1, 'nome' => 'Evento concorrência',
            'inicio' => today(), 'status' => 'em_andamento']);
        session(['event_id' => $this->evento->id]);

        $this->pix = PaymentMethod::create(['event_id' => $this->evento->id, 'nome' => 'PIX',
            'taxa_percentual' => 0, 'ordem' => 1]);

        $remessa = Shipment::create(['event_id' => $this->evento->id, 'supplier_id' => $forn->id,
            'condicao' => 'consignado']);
        $item = ShipmentItem::create(['shipment_id' => $remessa->id, 'product_id' => $prod->id,
            'quantidade' => 1, 'custo_unitario' => 20, 'preco_venda' => 40]);

        // UM exemplar só: é o cenário que expõe a disputa.
        $this->exemplar = Copy::create(['event_id' => $this->evento->id,
            'shipment_item_id' => $item->id, 'codigo' => 'UNI001']);
    }

    private function mesaDe(User $u)
    {
        return Livewire::actingAs($u)->test('livraria.venda');
    }

    /**
     * O carrinho da Ana RESERVA o exemplar — de forma visível, não bloqueante.
     * O Bruno continua enxergando a linha, agora com "0 restantes · 1 reservado".
     */
    public function test_carrinho_reserva_e_a_reserva_fica_visivel_para_o_outro(): void
    {
        $this->mesaDe($this->ana)->call('adicionar', $this->exemplar->id);

        $linha = $this->mesaDe($this->bruno)->instance()->resultados->first();

        $this->assertNotNull($linha, 'a linha continua na lista do Bruno');
        $this->assertSame(0, (int) $linha->disponiveis);
        $this->assertSame(1, (int) $linha->reservados);
    }

    /** Com reservados >= disponíveis, adicionar avisa para combinar na mesa. */
    public function test_item_disputado_avisa_para_conferir_com_o_outro_vendedor(): void
    {
        $this->mesaDe($this->ana)->call('adicionar', $this->exemplar->id);

        $bruno = $this->mesaDe($this->bruno)->call('adicionar', $this->exemplar->id);

        $bruno->assertDispatched('toast', tipo: 'aviso');
        $this->assertCount(1, $bruno->get('carrinho'), 'não bloqueia: entra no carrinho mesmo assim');
    }

    /** A reserva de quem chegou primeiro NÃO é roubada pelo segundo. */
    public function test_segundo_a_adicionar_nao_rouba_a_reserva_do_primeiro(): void
    {
        $this->mesaDe($this->ana)->call('adicionar', $this->exemplar->id);
        $this->mesaDe($this->bruno)->call('adicionar', $this->exemplar->id);

        $this->assertSame($this->ana->id,
            CartHold::where('copy_id', $this->exemplar->id)->value('user_id'));
    }

    /** Esvaziar o próprio carrinho não apaga a reserva alheia. */
    public function test_limpar_o_carrinho_nao_solta_a_reserva_do_outro(): void
    {
        $this->mesaDe($this->ana)->call('adicionar', $this->exemplar->id);

        $this->mesaDe($this->bruno)
            ->call('adicionar', $this->exemplar->id)
            ->call('limpar');

        $this->assertSame($this->ana->id,
            CartHold::where('copy_id', $this->exemplar->id)->value('user_id'));
    }

    /** Reserva expirada não segura mais nada — carrinho abandonado se cura. */
    public function test_reserva_expirada_libera_o_exemplar(): void
    {
        $this->mesaDe($this->ana)->call('adicionar', $this->exemplar->id);

        // usa o fuso da CONGREGAÇÃO, não o now() cru do ambiente — é essa a
        // regra que o app segue para não expirar reserva na hora errada
        CartHold::where('copy_id', $this->exemplar->id)
            ->update(['expira_em' => CartHold::agoraDo($this->evento->id)->subMinute()]);

        $linha = $this->mesaDe($this->bruno)->instance()->resultados->first();

        $this->assertSame(1, (int) $linha->disponiveis);
        $this->assertSame(0, (int) $linha->reservados);
    }

    /**
     * A reserva é gravada e comparada no fuso da CONGREGAÇÃO.
     *
     * O middleware SetChurchContext aplica o fuso da igreja na web, mas
     * comando de terminal fica em UTC — 3 horas de diferença. Sem ancorar na
     * congregação, uma reserva criada pela tela pareceria expirada em qualquer
     * rotina de terminal, e o exemplar seria liberado cedo demais.
     */
    public function test_reserva_usa_o_fuso_da_congregacao_e_nao_o_do_ambiente(): void
    {
        $this->mesaDe($this->ana)->call('adicionar', $this->exemplar->id);

        $agora = CartHold::agoraDo($this->evento->id);

        // Compara HORA-DE-PAREDE, que é como o banco guarda e compara. Ler o
        // atributo como instante não vale: o cast rotula a string com o fuso
        // do ambiente, não com o da congregação.
        $gravado = CartHold::where('copy_id', $this->exemplar->id)
            ->value('expira_em');

        $this->assertSame(
            $agora->copy()->addMinutes(CartHold::MINUTOS)->format('Y-m-d H:i'),
            substr((string) $gravado, 0, 16),
        );

        // O ambiente está mesmo num fuso diferente — é o cenário que o código
        // precisa tratar, e não um detalhe do teste.
        $this->assertNotSame($agora->format('H'), now()->format('H'));

        $this->assertSame(1, CartHold::where("event_id", $this->evento->id)->vigentes($this->evento->id)->count());
    }

    /** Concluir a venda solta a reserva junto. */
    public function test_concluir_solta_a_reserva(): void
    {
        $this->mesaDe($this->ana)
            ->call('adicionar', $this->exemplar->id)
            ->call('$set', 'payment_method_id', $this->pix->id)
            ->call('revisar')
            ->call('concluir');

        $this->assertSame(0, CartHold::where('copy_id', $this->exemplar->id)->count());
    }

    /**
     * O QUE PROTEGE: quem concluir primeiro leva; o segundo é BLOQUEADO na
     * gravação e recebe um aviso claro, em vez de gravar venda duplicada.
     */
    public function test_o_segundo_a_concluir_e_bloqueado_com_aviso(): void
    {
        $ana   = $this->mesaDe($this->ana)->call('adicionar', $this->exemplar->id)
            ->call('$set', 'payment_method_id', $this->pix->id);
        $bruno = $this->mesaDe($this->bruno)->call('adicionar', $this->exemplar->id)
            ->call('$set', 'payment_method_id', $this->pix->id);

        $ana->call('revisar')->call('concluir')->assertDispatched('toast', tipo: 'ok');

        $bruno->call('revisar')->call('concluir')->assertDispatched('toast', tipo: 'erro');

        // Uma venda só, e o exemplar saiu uma vez.
        $this->assertSame(1, $this->evento->sales()->count());
        $this->assertSame(Copy::VENDIDO, $this->exemplar->fresh()->status);
    }

    /** A trava vale também no Service, sem passar pela tela. */
    public function test_service_recusa_o_exemplar_ja_vendido(): void
    {
        $vendas = app(SaleService::class);
        $vendas->registrar($this->evento->id, [$this->exemplar->id], $this->pix->id);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/indispon/i');

        $vendas->registrar($this->evento->id, [$this->exemplar->id], $this->pix->id);
    }

    /** Ao tocar num exemplar que acabou de sair, a mesa avisa e não trava. */
    public function test_mesa_avisa_quando_o_exemplar_sai_entre_a_lista_e_o_toque(): void
    {
        $bruno = $this->mesaDe($this->bruno);          // lista carregada com o item

        app(SaleService::class)->registrar($this->evento->id, [$this->exemplar->id], $this->pix->id);

        $bruno->call('adicionar', $this->exemplar->id)
            ->assertDispatched('toast', tipo: 'aviso');

        $this->assertSame([], $bruno->get('carrinho'));
    }
}
