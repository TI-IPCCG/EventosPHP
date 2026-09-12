<?php

namespace Database\Seeders;

use App\Models\Permission;
use Illuminate\Database\Seeder;

/**
 * Catálogo estático de permissões — dado de referência, roda em TODO ambiente.
 *
 * Convenção de slug: "area.acao". O prefixo agrupa a permissão na checklist da
 * tela de perfis, então o próximo módulo entra como um prefixo novo, sem
 * alterar schema nem tela.
 */
class PermissionSeeder extends Seeder
{
    public const CATALOG = [
        // núcleo
        'usuarios.ver'        => 'Ver a lista de pessoas da congregação',
        'usuarios.gerenciar'  => 'Cadastrar, ativar, editar e remover pessoas',
        'perfis.gerenciar'    => 'Gerenciar perfis de acesso e suas permissões',
        'eventos.ver'         => 'Ver os eventos da congregação',
        'eventos.gerenciar'   => 'Criar, editar e encerrar eventos',
        // módulo livraria
        'livraria.ver'        => 'Ver a livraria do evento: catálogo, saldo e relatórios',
        'livraria.catalogo'   => 'Gerenciar fornecedores e o catálogo de itens',
        'livraria.remessa'    => 'Montar remessas, lançar custos, definir preços e gerar etiquetas',
        'livraria.vender'     => 'Registrar vendas na mesa',
        'livraria.baixar'     => 'Dar baixa em exemplar sem venda (sorteio, cortesia, doação, perda)',
        'livraria.fechamento' => 'Fechar o evento: acerto dos fornecedores, devolução e resultado',
        // módulo participantes
        'participantes.ver'        => 'Ver os inscritos do evento e a presença',
        'participantes.checkin'    => 'Registrar entrada na portaria',
        'participantes.gerenciar'  => 'Cadastrar e editar inscritos, dias e configurações',
        'participantes.importar'   => 'Importar a planilha de inscrições',
        'participantes.enviar'     => 'Enviar as credenciais com QR por e-mail',
    ];

    public function run(): void
    {
        foreach (self::CATALOG as $slug => $description) {
            Permission::updateOrCreate(['slug' => $slug], ['description' => $description]);
        }
    }
}
