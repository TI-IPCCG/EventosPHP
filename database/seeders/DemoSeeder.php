<?php

namespace Database\Seeders;

use App\Models\Event;
use App\Models\Livraria\Category;
use App\Models\Livraria\Copy;
use App\Models\Livraria\EventCost;
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
use App\Services\Livraria\SaleService;
use Illuminate\Database\Seeder;

/**
 * Evento de demonstração com dados plausíveis, para desenvolver e revisar as
 * telas sem depender de digitação manual.
 *
 * ⚠ NÃO rodar em produção. Cria usuários com senha conhecida.
 */
class DemoSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->environment('production')) {
            $this->command?->error('DemoSeeder não roda em produção.');

            return;
        }

        session(['church_id' => 1]);
        $this->call(PermissionSeeder::class);
        $this->call(CatalogoSeeder::class);

        // ── pessoas ──
        $coordenador = SystemRole::firstOrCreate(['church_id' => 1, 'name' => 'Coordenador da Livraria']);
        $coordenador->permissions()->sync(Permission::pluck('id'));

        $operador = SystemRole::firstOrCreate(['church_id' => 1, 'name' => 'Operador de Mesa']);
        $operador->permissions()->sync(
            Permission::whereIn('slug', ['eventos.ver', 'livraria.ver', 'livraria.vender', 'livraria.baixar'])->pluck('id')
        );

        foreach ([['Coordenador Demo', 'coordenador@ipccg.org.br', $coordenador],
                  ['Voluntário Demo',  'voluntario@ipccg.org.br',  $operador]] as [$nome, $email, $perfil]) {
            $user = User::updateOrCreate(['email' => $email], ['name' => $nome, 'password' => 'demo1234']);
            Membership::updateOrCreate(
                ['user_id' => $user->id, 'church_id' => 1],
                ['system_role_id' => $perfil->id, 'status' => true, 'created_at' => now()],
            );
        }

        // ── catálogo ──
        $catLivro = Category::withoutGlobalScopes()->where('slug', 'livro')->first();
        $catCam   = Category::withoutGlobalScopes()->where('slug', 'camiseta')->first();

        $fiel = Supplier::updateOrCreate(['church_id' => 1, 'prefixo' => 'EFL'],
            ['nome' => 'Editora Fiel', 'condicao_padrao' => 'consignado', 'desconto_padrao' => 40, 'ativo' => true]);
        $ecc  = Supplier::updateOrCreate(['church_id' => 1, 'prefixo' => 'ECC'],
            ['nome' => 'Editora Cultura Cristã', 'condicao_padrao' => 'consignado', 'desconto_padrao' => 40, 'ativo' => true]);
        $conf = Supplier::updateOrCreate(['church_id' => 1, 'prefixo' => 'CAM'],
            ['nome' => 'Confecção Local', 'condicao_padrao' => 'firme', 'ativo' => true]);

        $livros = [
            ['Aquietai-vos e Sabei',            'John Piper',      $fiel, 49.90, 29.94, 42.00, 10],
            ['Masculinidade em Crise',          'Owen Strachan',   $fiel, 54.90, 32.94, 46.00, 10],
            ['Nossa Suficiência em Cristo',     'John MacArthur',  $ecc,  59.90, 35.94, 50.00,  8],
            ['O Sofrimento Nunca é em Vão',     'Elisabeth Elliot',$ecc,  44.90, 26.94, 38.00,  6],
            ['Quando eu Não Desejo Deus',       'John Piper',      $fiel, 52.90, 31.74, 44.00,  4],
        ];

        $evento = Event::updateOrCreate(
            ['church_id' => 1, 'nome' => 'Simpósio UMP 2026'],
            ['local' => 'Templo Central', 'inicio' => today(), 'fim' => today()->addDay(), 'status' => 'em_andamento'],
        );
        $evento->livrariaSettings()->updateOrCreate([], ['meta_tipo' => 'zero_a_zero']);

        $pix = PaymentMethod::updateOrCreate(['event_id' => $evento->id, 'nome' => 'PIX'],
            ['taxa_percentual' => 0, 'ordem' => 1, 'ativo' => true]);
        PaymentMethod::updateOrCreate(['event_id' => $evento->id, 'nome' => 'Débito'],
            ['taxa_percentual' => 1.99, 'ordem' => 2, 'ativo' => true]);
        $credito = PaymentMethod::updateOrCreate(['event_id' => $evento->id, 'nome' => 'Crédito'],
            ['taxa_percentual' => 4.5, 'ordem' => 3, 'ativo' => true]);

        EventCost::firstOrCreate(
            ['event_id' => $evento->id, 'descricao' => 'Frete ida e volta — Fiel'],
            ['supplier_id' => $fiel->id, 'valor' => 400, 'rateio' => 'direto', 'created_at' => now()],
        );
        EventCost::firstOrCreate(
            ['event_id' => $evento->id, 'descricao' => 'Frete ida e volta — Cultura Cristã'],
            ['supplier_id' => $ecc->id, 'valor' => 300, 'rateio' => 'direto', 'created_at' => now()],
        );

        $remessas = [];
        $seq = [];
        $exemplares = [];

        foreach ($livros as [$nome, $autor, $forn, $capa, $custo, $venda, $qtd]) {
            $produto = Product::updateOrCreate(
                ['church_id' => 1, 'nome' => $nome],
                ['category_id' => $catLivro->id, 'supplier_id' => $forn->id,
                 'preco_referencia' => $capa, 'atributos' => ['autor' => $autor, 'editora' => $forn->nome], 'ativo' => true],
            );

            $remessas[$forn->id] ??= Shipment::firstOrCreate(
                ['event_id' => $evento->id, 'supplier_id' => $forn->id],
                ['condicao' => $forn->condicao_padrao, 'created_at' => now()],
            );

            $item = ShipmentItem::updateOrCreate(
                ['shipment_id' => $remessas[$forn->id]->id, 'product_id' => $produto->id, 'variant_id' => null],
                ['quantidade' => $qtd, 'custo_unitario' => $custo, 'preco_venda' => $venda],
            );

            $exemplares = array_merge($exemplares, $this->gerarCopias($evento, $item, $forn->prefixo, $qtd, $seq));
        }

        // Camiseta com tamanhos: cada um é um saldo próprio.
        $camisa = Product::updateOrCreate(
            ['church_id' => 1, 'nome' => 'Camiseta Simpósio UMP 2026'],
            ['category_id' => $catCam->id, 'supplier_id' => $conf->id, 'preco_referencia' => 45,
             'atributos' => ['modelo' => 'Básica', 'marca' => 'Malwee', 'material' => 'Algodão 30.1', 'cor' => 'Preta'],
             'ativo' => true],
        );

        $remessas[$conf->id] = Shipment::firstOrCreate(
            ['event_id' => $evento->id, 'supplier_id' => $conf->id],
            ['condicao' => 'firme', 'created_at' => now()],
        );

        foreach (['P' => 4, 'M' => 8, 'G' => 6, 'GG' => 3] as $i => $tamanho) {
            $ordem = array_search($i, array_keys(['P' => 4, 'M' => 8, 'G' => 6, 'GG' => 3])) + 1;
        }
        $ordem = 0;
        foreach (['P' => 4, 'M' => 8, 'G' => 6, 'GG' => 3] as $tam => $qtd) {
            $variacao = Variant::updateOrCreate(
                ['product_id' => $camisa->id, 'nome' => $tam],
                ['ordem' => ++$ordem, 'ativo' => true],
            );
            $item = ShipmentItem::updateOrCreate(
                ['shipment_id' => $remessas[$conf->id]->id, 'product_id' => $camisa->id, 'variant_id' => $variacao->id],
                ['quantidade' => $qtd, 'custo_unitario' => 22, 'preco_venda' => 40],
            );
            $exemplares = array_merge($exemplares, $this->gerarCopias($evento, $item, 'CAM', $qtd, $seq));
        }

        // ── algumas vendas, para o painel não nascer vazio ──
        session(['event_id' => $evento->id]);
        $vendas   = app(SaleService::class);
        $livres   = collect($exemplares)->shuffle()->values();
        $formas   = [$pix->id, $pix->id, $credito->id];   // PIX predomina, como no evento real

        for ($i = 0; $i < 9 && $livres->count() >= 2; $i++) {
            $qtd  = random_int(1, 2);
            $lote = $livres->splice(0, $qtd)->pluck('id')->all();

            $vendas->registrar(
                eventId: $evento->id,
                copyIds: $lote,
                paymentMethodId: $formas[$i % 3],
                comprador: null,
                registradoPor: User::where('email', 'voluntario@ipccg.org.br')->value('id'),
            );
        }

        $this->command?->info('Demo pronta — login: coordenador@ipccg.org.br / demo1234');
    }

    /** @return array<Copy> */
    private function gerarCopias(Event $evento, ShipmentItem $item, string $prefixo, int $qtd, array &$seq): array
    {
        $criadas = [];
        $seq[$prefixo] ??= 0;

        for ($i = 0; $i < $qtd; $i++) {
            $codigo = sprintf('%s%03d', $prefixo, ++$seq[$prefixo]);
            $criadas[] = Copy::firstOrCreate(
                ['event_id' => $evento->id, 'codigo' => $codigo],
                ['shipment_item_id' => $item->id, 'status' => Copy::DISPONIVEL],
            );
        }

        return $criadas;
    }
}
