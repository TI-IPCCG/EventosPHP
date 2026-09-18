{{-- Central de ajuda (página estática, sem Livewire).

     Espelha a de Escala de Membros: `details.help-item` como acordeão, texto
     em português corrido e a ORDEM DE MONTAGEM em destaque no topo — a dúvida
     real de quem abre esta tela não é "o que este botão faz", é "por onde eu
     começo para chegar no resultado do evento".

     Todo link usa route(): em produção o app roda numa SUBPASTA. --}}
<x-layouts.admin :title="'Ajuda — ' . config('app.name')">
    <div class="page-header">
        <div class="page-header-left">
            <h1 class="page-title">Central de Ajuda</h1>
            <p class="page-subtitle">Como montar e operar a livraria de um evento, do zero até o resultado.</p>
        </div>
    </div>

    {{-- ── Por onde começar ───────────────────────────────────── --}}
    <div class="help-intro">
        <strong>🚀 O app tem dois módulos, e eles são independentes</strong>
        <p style="margin:6px 0 0">
            Tudo parte do <strong>Evento</strong>. A partir dele, os dois módulos seguem
            caminhos próprios — dá para usar um sem o outro.
        </p>

        <p style="margin:12px 0 2px"><strong>📚 Livraria</strong> — vender no evento:</p>
        <div class="help-chain">
            <span class="step">Evento</span><span class="arrow">→</span>
            <span class="step">Fornecedores</span><span class="arrow">→</span>
            <span class="step">Categorias</span><span class="arrow">→</span>
            <span class="step">Catálogo</span><span class="arrow">→</span>
            <span class="step">Remessa</span><span class="arrow">→</span>
            <span class="step">Venda</span><span class="arrow">→</span>
            <span class="step">Painel</span>
        </div>

        <p style="margin:12px 0 2px"><strong>🎟️ Participantes</strong> — quem vem ao evento:</p>
        <div class="help-chain">
            <span class="step">Evento</span><span class="arrow">→</span>
            <span class="step">Dias</span><span class="arrow">→</span>
            <span class="step">Inscritos</span><span class="arrow">→</span>
            <span class="step">Credenciais</span><span class="arrow">→</span>
            <span class="step">Check-in</span>
        </div>
        <p style="margin:12px 0 0; font-size:.9rem">
            <strong>Fornecedores, Categorias e Catálogo são permanentes</strong> — cadastra uma vez
            e reaproveita em todos os eventos. Só <strong>Remessa</strong> e <strong>Venda</strong>
            são refeitas a cada evento. No módulo de participantes vale o mesmo: as
            <strong>pessoas</strong> ficam guardadas entre eventos, e o que se refaz é a
            <strong>inscrição</strong>.
        </p>
    </div>

    {{-- ── FAQ em destaque ────────────────────────────────────── --}}
    <details class="help-item" open>
        <summary>❓ Por que um item NÃO aparece na tela de Venda?</summary>
        <div class="help-body">
            <p>Essa é a dúvida mais comum. A Venda não lista o catálogo — ela lista o
            <strong>estoque do evento ativo</strong>. Um item só aparece se as três coisas valerem
            ao mesmo tempo:</p>
            <ul>
                <li><strong>Está na remessa deste evento</strong> — cadastrar no Catálogo não coloca
                    nada em nenhum evento. Quem põe o item no evento é a tela <em>Remessa</em>.</li>
                <li><strong>Sobrou exemplar disponível</strong> — o que já foi vendido ou baixado sai
                    da lista. Se o saldo zerou, o item desaparece (e aparece como
                    <em>esgotado</em> no Painel).</li>
                <li><strong>É o evento certo</strong> — se o evento ativo não for o que você está
                    operando, o estoque mostrado é de outro evento. Confira o nome no alto do menu,
                    em <em>Evento ativo</em>.</li>
            </ul>
            <p>Se o item tem <strong>variações</strong> (P, M, G), cada tamanho tem saldo próprio:
            o M pode esgotar e o P continuar aparecendo. É isso que se espera.</p>
            <div class="tip">💡 Sumiu um item? Confira nesta ordem: evento ativo correto →
            está na remessa → ainda tem exemplar disponível.</div>
        </div>
    </details>

    {{-- ── 1. Evento ──────────────────────────────────────────── --}}
    <details class="help-item">
        <summary>📅 1. Evento — o contexto de tudo</summary>
        <div class="help-body">
            <p>Comece aqui. Em <a href="{{ route('eventos') }}">Eventos</a> você cria o evento e
            escolhe qual está <strong>ativo</strong>. O evento ativo é o contexto de todo o resto:
            Remessa, Venda, Estoque e Painel olham para ele.</p>
            <ul>
                <li><strong>Novo evento</strong> → nome, local, início e término
                    (término vazio = evento de um dia só).</li>
                <li><strong>Situação</strong> → <em>Planejamento</em> (montando a remessa),
                    <em>Em andamento</em> (vendendo) ou <em>Encerrado</em> (fechando as contas).</li>
                <li><strong>Meta financeira</strong> → <em>Zero a zero</em> (só cobrir os custos) ou
                    um <em>valor definido</em>. É o número que o Painel persegue.</li>
                <li><strong>Ativar</strong> → na lista, o botão troca o evento ativo. Muda o contexto
                    de todas as telas de uma vez, por isso pede confirmação.</li>
            </ul>
            <p>O evento ativo fica guardado na sua sessão. Se você nunca escolheu, o sistema assume
            o <strong>evento em andamento mais recente</strong> e, se não houver nenhum, o último
            cadastrado — assim nenhuma tela fica dizendo "nenhum evento" no meio de um evento
            acontecendo.</p>
            <div class="tip">💡 A <strong>situação</strong> serve para você e para o Painel (que
            mostra o selo <em>ao vivo</em> quando está <em>Em andamento</em>). Ela
            <strong>não tranca</strong> a venda: o que a Venda exige é ter um evento ativo com
            estoque. Trocar o evento ativo antes de abrir a mesa é o que evita registrar venda no
            evento errado.</div>

            <details class="help-sub">
                <summary>💳 Formas de pagamento e taxas (nesta mesma tela)</summary>
                <div class="help-sub-body">
                    <p>As formas de pagamento são <strong>do evento</strong>, não globais — a
                    maquininha muda de contrato entre um ano e outro, e um evento fechado não pode
                    ter suas contas remexidas por uma taxa nova.</p>
                    <ul>
                        <li>Cadastre cada forma (Dinheiro, Pix, Débito, Crédito…) com a
                            <strong>% de taxa</strong> que a operadora cobra. Sem taxa, deixe zero.</li>
                        <li>A taxa entra no cálculo do resultado <strong>só onde houve aquela
                            forma</strong>, por transação — nunca sobre o total do evento.</li>
                        <li>Forma <strong>já usada em venda não pode ser removida</strong>, só
                            desativada: apagar levaria o histórico da venda junto.</li>
                    </ul>
                    <div class="tip">⚠️ Cadastre as formas <strong>antes de abrir a mesa</strong>.
                    Sem nenhuma forma cadastrada não há como concluir uma venda.</div>
                </div>
            </details>
        </div>
    </details>

    {{-- ── 2. Fornecedores ────────────────────────────────────── --}}
    <details class="help-item">
        <summary>🏪 2. Fornecedores — editoras e confecções</summary>
        <div class="help-body">
            <p>Em <a href="{{ route('livraria.fornecedores') }}">Fornecedores</a> ficam as editoras,
            confecções e quem mais manda mercadoria. Cadastro permanente: vale para todos os eventos.</p>
            <ul>
                <li><strong>Prefixo do código</strong> → as 3 letras que abrem a etiqueta de cada
                    exemplar (<code>ECC</code> → <code>ECC001</code>, <code>ECC002</code>…).
                    Curto e único.</li>
                <li><strong>Condição comercial</strong> → <em>Consignado</em> (o que não vende volta)
                    ou <em>Compra firme</em> (não devolve). Define o que você deve no acerto.</li>
                <li><strong>Desconto padrão (%)</strong> → o desconto que essa editora dá em tudo.
                    Opcional; deixe vazio se varia item a item.</li>
                <li><strong>Contato</strong> → telefone ou e-mail de quem resolve.</li>
            </ul>
            <div class="tip">⚠️ <strong>Não troque o prefixo</strong> depois que uma remessa já usou
            ele: isso renomearia códigos de exemplares que estão fisicamente na caixa, com etiqueta
            impressa. Fornecedor que saiu se <strong>desativa</strong>, não se apaga.</div>
        </div>
    </details>

    {{-- ── 3. Categorias ──────────────────────────────────────── --}}
    <details class="help-item">
        <summary>🏷️ 3. Categorias — o que o item precisa informar</summary>
        <div class="help-body">
            <p>O catálogo <strong>não é só de livros</strong>. Em
            <a href="{{ route('livraria.categorias') }}">Categorias</a> você diz quais campos cada
            tipo de item pede: livro pede autor e editora, camiseta pede modelo e marca.</p>
            <p>Já vêm categorias prontas na instalação — <strong>mexa aqui só quando precisar de um
            tipo novo</strong> de item.</p>
            <ul>
                <li><strong>Nova categoria</strong> → nome (ex.: "Caneca") e
                    <strong>como chamar a variação</strong> (ex.: "Tamanho", "Cor").</li>
                <li><strong>Novo campo</strong> → nome e tipo: texto, texto longo, número inteiro,
                    decimal, data, lista de opções ou sim/não. Lista de opções aceita as opções
                    separadas por vírgula.</li>
            </ul>
            <p>É isto que faz "Caneca com o campo Capacidade" ser <strong>cadastro, não
            desenvolvimento</strong>: o formulário do item se monta a partir daqui.</p>

            <details class="help-sub">
                <summary>🔎 Campo ou variação? A distinção que evita retrabalho</summary>
                <div class="help-sub-body">
                    <p><strong>CAMPO descreve</strong> o item. <strong>VARIAÇÃO divide o
                    estoque.</strong></p>
                    <ul>
                        <li><strong>Campo</strong> → autor, editora, número de páginas, marca,
                            material. Informação; não muda a contagem.</li>
                        <li><strong>Variação</strong> → tamanho, cor. <strong>Cada uma tem saldo
                            próprio</strong>: P, M e G esgotam separado, e é por isso que tamanho
                            nunca deve ser um campo.</li>
                    </ul>
                    <div class="tip">💡 A pergunta que resolve: <em>"duas unidades disso podem
                    acabar em momentos diferentes?"</em> Se sim, é variação.</div>
                    <p>Remover um campo só faz ele <strong>deixar de ser pedido</strong> — o valor já
                    preenchido nos itens continua guardado.</p>
                </div>
            </details>
        </div>
    </details>

    {{-- ── 4. Catálogo ────────────────────────────────────────── --}}
    <details class="help-item">
        <summary>📚 4. Catálogo — os itens, uma vez só</summary>
        <div class="help-body">
            <p>Em <a href="{{ route('livraria.catalogo') }}">Catálogo</a> entram os itens que você
            vende. É <strong>permanente e reaproveitado entre eventos</strong>: o livro cadastrado
            hoje serve para o evento do ano que vem.</p>
            <ul>
                <li><strong>Categoria</strong> primeiro — ela define os campos que o formulário vai
                    pedir logo abaixo.</li>
                <li><strong>Fornecedor</strong> → de quem vem o item (define prefixo do código e
                    condição comercial).</li>
                <li><strong>Nome</strong> e <strong>preço de referência</strong> → o preço de capa
                    ou sugerido. O preço que vale na mesa é o da <em>Remessa</em>.</li>
                <li><strong>Desconto deste item (%)</strong> → só a <strong>exceção</strong>. Vazio
                    significa "usa o desconto do fornecedor", que é o caso comum.</li>
                <li><strong>Variações</strong> → P, M, G… uma linha para cada, se a categoria pedir.</li>
                <li><strong>Observações</strong> → texto livre ("capa dura", "exemplar de
                    mostruário").</li>
                <li><strong>Fotos</strong> → várias por item, com <strong>uma capa</strong>. A capa
                    é a que aparece nas listagens.</li>
            </ul>
            <div class="tip">⚠️ Cadastrar no Catálogo <strong>não coloca o item em evento
            nenhum</strong> e não cria estoque. Quem faz isso é a Remessa (passo 5).</div>
            <div class="tip">⚠️ <strong>Variação já usada em remessa não pode ser removida</strong> —
            levaria o saldo daquele tamanho junto.</div>

            <details class="help-sub">
                <summary>📷 Adicionar foto — a ordem importa</summary>
                <div class="help-sub-body">
                    <p>O bloco <strong>Fotos</strong> só aparece <strong>depois</strong> de salvar o
                    item: a foto precisa saber a qual item pertence. São três passos, nesta ordem:</p>
                    <ul>
                        <li><strong>Salvar</strong> o item (ou abrir um já salvo em Editar);</li>
                        <li><strong>Escolher arquivo</strong> e esperar o "Enviando…" terminar;</li>
                        <li>tocar em <strong>Adicionar foto</strong>.</li>
                    </ul>
                    <div class="tip">⚠️ O botão <strong>Salvar</strong> é do item, não da foto.
                    Escolher o arquivo e clicar em <strong>Salvar</strong> grava o item e
                    <strong>descarta a foto</strong> — a mensagem verde que aparece é do item. O
                    passo que anexa a imagem é o <strong>Adicionar foto</strong>.</div>
                    <p>A primeira foto vira a <strong>capa</strong> sozinha; a capa é a que aparece
                    nas listagens e na mesa. Para trocar, use a estrela. Limite de
                    <strong>5 MB</strong> por imagem, e o sistema gera a miniatura sozinho.</p>
                    <div class="tip">💡 Se aparecer um aviso vermelho dizendo que
                    <strong>"as fotos não vão aparecer"</strong>, é configuração do servidor, não
                    erro seu — avise o responsável técnico e não perca tempo cadastrando imagens
                    até resolver.</div>
                </div>
            </details>
        </div>
    </details>

    {{-- ── 5. Remessa ─────────────────────────────────────────── --}}
    <details class="help-item">
        <summary>🚚 5. Remessa — o que veio, quanto e a que preço</summary>
        <div class="help-body">
            <p>Em <a href="{{ route('livraria.remessa') }}">Remessa</a> você declara o que cada
            fornecedor mandou para <strong>este</strong> evento. <strong>É aqui que o estoque
            nasce:</strong> salvar uma linha cria os exemplares, um por um, já com código.</p>
            <p>Escolha o <strong>fornecedor</strong> no alto e vá adicionando item por item:</p>
            <ul>
                <li><strong>Item</strong> e, se houver, a <strong>variação</strong> — uma linha por
                    tamanho, porque cada um tem saldo próprio.</li>
                <li><strong>Quantidade</strong> → quantas unidades chegaram.</li>
                <li><strong>Custo unitário</strong> → quanto você paga por unidade ao fornecedor.
                    O sistema sugere a partir do desconto (do item, ou do fornecedor).</li>
                <li><strong>Preço de venda</strong> → por quanto sai na mesa. <strong>Este é o preço
                    que a Venda usa.</strong></li>
            </ul>
            <p>Ao lado de cada item aparece <strong>quanto ele girou no evento anterior</strong>.
            Sob consignação o erro caro não é encalhar — é levar de menos e esgotar antes do fim.</p>
            <p>Embaixo, os totais da remessa: <strong>exemplares</strong>,
            <strong>valor consignado</strong> (o que você deve se vender tudo) e
            <strong>se vender tudo</strong> (a receita no melhor cenário).</p>
            <div class="tip">💡 O <strong>custo unitário fica gravado na linha</strong>, como
            fotografia do momento. Renegociar o desconto com a editora amanhã não mexe em evento já
            fechado — e é isso que faz o acerto ser confiável meses depois.</div>

            <p><strong>Errou o custo ou o preço?</strong> Cada linha da lista tem o botão
            <strong>Editar</strong>: ele traz a linha para o formulário e você ajusta quantidade,
            custo e preço. <strong>Não é preciso remover e lançar de novo.</strong></p>
            <ul>
                <li>O <strong>item e a variação ficam travados</strong> na edição — eles
                    identificam a linha; trocá-los criaria outra em vez de corrigir esta.</li>
                <li>Os <strong>exemplares e os códigos continuam os mesmos</strong> se a
                    quantidade não mudar. Nada de etiqueta para refazer.</li>
                <li>O custo novo vale para as <strong>próximas</strong> vendas. O que já foi
                    vendido guarda o custo do dia — é isso que o acerto do fornecedor usa.</li>
            </ul>

            <div class="tip">⚠️ <strong>Não conseguiu remover uma linha?</strong> Se algum exemplar
            dela já esteve em uma venda — <strong>mesmo que você tenha estornado a venda</strong> —
            ele não pode ser apagado: o histórico da venda depende dele. Estornar devolve o exemplar
            para o estoque, mas não apaga o registro de que ele foi vendido um dia. Nesse caso use
            <strong>Editar</strong>, que resolve sem tocar no histórico.</div>

            <details class="help-sub">
                <summary>🔢 Códigos, etiquetas e mudança de quantidade</summary>
                <div class="help-sub-body">
                    <p>Cada exemplar ganha um <strong>código sequencial por fornecedor</strong>:
                    <code>ECC001</code>, <code>ECC002</code>… Ele aparece em toda a livraria e a mesa
                    pode digitá-lo para adicionar direto ao carrinho.</p>

                    <p>Com itens na remessa aparece <strong>Imprimir etiquetas</strong>, que abre uma
                    folha com uma etiqueta por exemplar — nome, código e preço — para recortar e
                    colar. Dá para filtrar por <strong>fornecedor</strong> (a remessa chega em caixas
                    separadas) e escolher entre <strong>só os disponíveis</strong> (o padrão) ou
                    todos.</p>

                    <div class="tip">💡 <strong>Colar etiqueta é opcional.</strong> Nada no app
                    depende disso: sem etiqueta a mesa vende pelo título normalmente e o sistema
                    escolhe o exemplar sozinho. A etiqueta só torna mais rápido digitar o código na
                    venda e conferir a caixa no fechamento. Se não der tempo de colar, não perdeu
                    nada.</div>

                    <div class="tip">⚠️ <strong>O preço vai impresso.</strong> Se ainda for mexer nos
                    preços da remessa, imprima depois — papel colado não se atualiza sozinho.</div>
                    <p><strong>Mudar a quantidade depois acerta o estoque:</strong></p>
                    <ul>
                        <li><strong>Aumentar</strong> → gera só os exemplares que faltam, seguindo a
                            sequência.</li>
                        <li><strong>Diminuir</strong> → remove <strong>apenas exemplares
                            disponíveis</strong>, e <strong>recusa</strong> se a conta não fechar sem
                            mexer no que já foi vendido. Apagar um exemplar vendido levaria receita e
                            dívida junto.</li>
                    </ul>
                    <div class="tip">⚠️ Reduzir quantidade e remover linha <strong>apagam exemplares
                    e códigos</strong>. As duas ações avisam quantos somem e pedem confirmação —
                    leia a contagem antes de confirmar.</div>
                </div>
            </details>

            <details class="help-sub">
                <summary>💰 Custos do evento (nesta mesma tela)</summary>
                <div class="help-sub-body">
                    <p>Frete, banner, aluguel de maquininha, combustível — tudo que o evento gasta e
                    não é mercadoria. Lance nome, valor e <strong>como ele se distribui</strong>:</p>
                    <ul>
                        <li><strong>Direto</strong> → entra uma vez, pelo valor cheio. É o caso do
                            frete.</li>
                        <li><strong>Por unidade enviada</strong> → multiplica pelo total de
                            exemplares da remessa.</li>
                        <li><strong>Por unidade vendida</strong> → multiplica pelo que efetivamente
                            saiu.</li>
                        <li><strong>% sobre a venda</strong> → percentual da receita.</li>
                    </ul>
                    <div class="tip">💡 Frete é <strong>Direto</strong>. Rateá-lo sobre a venda
                    prevista foi exatamente o erro da planilha antiga, que cobrou R$ 994 de um frete
                    de R$ 700.</div>
                </div>
            </details>
        </div>
    </details>

    {{-- ── 6. Venda ───────────────────────────────────────────── --}}
    <details class="help-item">
        <summary>🛒 6. Venda — a mesa</summary>
        <div class="help-body">
            <p>A tela de <a href="{{ route('livraria.venda') }}">Venda</a> foi feita para uma
            <strong>mão só, em pé, com fila esperando</strong>. Um item se conclui em
            <strong>quatro toques</strong>:</p>
            <ul>
                <li><strong>1. Toque no item</strong> — a tela já abre com o estoque do evento
                    listado. Não precisa digitar nada.</li>
                <li><strong>2. Toque na forma de pagamento</strong> — é botão, não lista suspensa:
                    um toque em vez de três.</li>
                <li><strong>3. Confirmar venda</strong> — abre o resumo.</li>
                <li><strong>4. Confirmar no resumo</strong> — item a item, taxa em separado e total.
                    Só aqui a venda é gravada.</li>
            </ul>
            <p><strong>Buscar</strong> (a partir de 2 letras) filtra por título, autor ou código.
            E <strong>digitar o código da etiqueta adiciona direto</strong> ao carrinho — é o
            caminho mais rápido de todos quando a etiqueta está à mão.</p>
            <p>A busca devolve <strong>linhas de estoque</strong> (item + variação), não exemplares:
            você escolhe "Camiseta · M" e <strong>qual unidade física sai é problema do
            sistema</strong>.</p>
            <p><strong>Identificar o comprador é opcional</strong> — nome e documento, úteis quando
            alguém leva para pagar depois ou pediu reserva.</p>
            <p>A <strong>capa do item aparece no carrinho e na confirmação</strong>: na mesa você
            confere pela imagem, não pelo texto — é a última chance de pegar o item errado antes
            de gravar. Item sem foto cadastrada entra do mesmo jeito.</p>
            <p>Com itens no carrinho aparece o atalho <strong>“Carrinho (n)”</strong> no canto
            superior direito: toque nele e a tela pula direto para o botão de concluir, sem
            rolar a lista inteira. Ele some sozinho quando o botão já está à vista.</p>
            <p>A lista abre com <strong>20 itens por página</strong>. Dá para mudar para 10, 30 ou
            50 e <strong>ordenar</strong> por nome, preço, categoria ou por quem está
            <strong>acabando</strong> — útil para saber o que falta reabastecer na mesa.</p>
            <div class="tip">💡 Errou depois de gravar? Não refaça a conta na mão: veja
            <strong>Vendas registradas</strong>, logo abaixo.</div>

            <details class="help-sub">
                <summary>👥 Vários voluntários na mesma mesa — "Reservados: 2"</summary>
                <div class="help-sub-body">
                    <p>Quando um exemplar está no carrinho de alguém, ele aparece como
                    <strong>"Reservados: x"</strong> na linha do estoque dos outros.</p>
                    <ul>
                        <li><strong>Não bloqueia</strong> — quem quiser pode adicionar mesmo assim, e
                            recebe um aviso quando os reservados igualam ou passam os disponíveis.</li>
                        <li>A combinação é <strong>entre os voluntários, na mesa</strong> — o sistema
                            informa, não decide.</li>
                        <li>Carrinho esquecido <strong>se cura sozinho em 20 minutos</strong>. Não há
                            nada para limpar.</li>
                    </ul>
                </div>
            </details>
        </div>
    </details>

    {{-- ── 7. Vendas registradas ──────────────────────────────── --}}
    <details class="help-item">
        <summary>🧾 7. Vendas registradas — corrigir, trocar e estornar</summary>
        <div class="help-body">
            <p>A tela de <a href="{{ route('livraria.vendas') }}">Vendas</a> lista tudo que foi
            registrado no evento. Busque por <strong>nome do comprador</strong>, pelo
            <strong>código da etiqueta</strong> do item, ou pelo <strong>#número da venda</strong>.
            Os filtros separam válidas, estornadas, corrigidas e as que tiveram troca.</p>

            <p>Toque em <strong>Detalhes</strong> para ver a venda inteira: o que foi pago, os itens
            de hoje, o que já saiu (e por quê) e o histórico de trocas.</p>

            <p><strong>Quem fez o quê aparece na tela.</strong> A lista mostra o voluntário que
            registrou cada venda, e o detalhe mostra também quem <strong>corrigiu</strong>, quem
            <strong>estornou</strong> e quem lançou cada <strong>troca</strong>, com data e hora.
            É a primeira pergunta quando um lançamento parece estranho no fechamento.</p>

            <div class="tip">⚠️ <strong>Corrigir e Trocar não são a mesma coisa</strong>, e escolher
            errado desencontra o relatório do extrato do banco. A pergunta que decide é:
            <strong>alguém trocou alguma coisa de verdade?</strong></div>

            <details class="help-sub">
                <summary>✏️ Corrigir lançamento — “foi digitado errado”</summary>
                <div class="help-sub-body">
                    <p>Use quando <strong>ninguém trocou nada</strong> e o registro é que está
                    errado: tocou no item errado, escolheu a forma de pagamento errada, esqueceu de
                    lançar um livro que a pessoa levou.</p>
                    <p>A correção <strong>reescreve a venda</strong> — itens, forma de pagamento,
                    valor e taxa — porque o número que estava lá <strong>nunca foi verdade</strong>.
                    Fica registrado quem corrigiu e quando.</p>
                    <p>A tela abre já marcada com os itens atuais: <strong>desmarque</strong> o que
                    não deveria estar e <strong>acrescente</strong> o que faltou — pela mesma lista
                    por item, escolhendo o exemplar no pop-up.</p>
                </div>
            </details>

            <details class="help-sub">
                <summary>🔄 Trocar item — “o comprador voltou”</summary>
                <div class="help-sub-body">
                    <p>Use quando a venda <strong>estava certa</strong> e a pessoa voltou para trocar:
                    levou M e queria G, o item veio com defeito, desistiu de um e levou outro.</p>
                    <p>Marque o que ela <strong>devolveu</strong>, escolha o que ela
                    <strong>levou no lugar</strong> — a lista mostra um cartão por item, com a
                    capa e quantos estão disponíveis; tocando nele você escolhe o
                    <strong>código do exemplar</strong>, igual à tela de Baixas — e a tela mostra
                    a conta na hora:</p>
                    <ul>
                        <li><strong>Devolver ao comprador</strong> — o item novo é mais barato;</li>
                        <li><strong>Cobrar do comprador</strong> — o item novo é mais caro. Aí é
                            preciso dizer <strong>por onde a diferença foi cobrada</strong>, porque é
                            uma transação nova, com taxa própria;</li>
                        <li><strong>Sem diferença</strong> — mesma faixa de preço, nada a acertar.</li>
                    </ul>
                    <p>Também serve para <strong>só devolver</strong>: marque o item, não escolha
                    nada no lugar, e a conta mostra quanto devolver.</p>

                    <div class="tip">💡 <strong>Por que a venda continua mostrando o valor antigo?</strong>
                    Porque é o que passou no PIX ou no cartão, e o extrato do banco vai mostrar esse
                    valor para sempre — a operadora já reteve a taxa sobre ele. Trocar não desfaz
                    isso. A tela mostra as duas coisas: o que foi pago, e quanto somam os itens
                    hoje. A diferença é exatamente o que saiu ou entrou no caixa na troca.</div>
                </div>
            </details>

            <details class="help-sub">
                <summary>↩️ Estornar — a venda inteira não deveria existir</summary>
                <div class="help-sub-body">
                    <p>Devolve <strong>todos</strong> os exemplares ao estoque e tira a venda das
                    contas do evento. A venda continua consultável, marcada como estornada — nada é
                    apagado.</p>
                    <p>Se a venda tinha troca, os movimentos dela saem da conta junto: o dinheiro
                    volta todo.</p>
                    <div class="tip">⚠️ Um item que já tinha saído numa troca <strong>não volta</strong>
                    ao estoque no estorno — ele já foi devolvido antes e pode estar com outra
                    pessoa.</div>
                </div>
            </details>

            <details class="help-sub">
                <summary>🔑 Quem pode mexer</summary>
                <div class="help-sub-body">
                    <p><strong>Ver</strong> as vendas é parte de ver a livraria. Mas
                    <strong>corrigir, trocar e estornar</strong> exigem a permissão
                    <code>livraria.corrigir</code> — mexer em dinheiro já registrado é ato
                    diferente de operar a mesa.</p>
                    <p>O voluntário da mesa abre a tela e consulta, mas não vê os botões. Dê esse
                    perfil a quem responde pelo caixa.</p>
                </div>
            </details>
        </div>
    </details>

    {{-- ── 8. Baixas ──────────────────────────────────────────── --}}
    <details class="help-item">
        <summary>📤 8. Baixas — o que saiu sem ser vendido</summary>
        <div class="help-body">
            <p>Sorteio da plenária, cortesia para o preletor, doação, exemplar que caiu e
            estragou. Em <a href="{{ route('livraria.baixas') }}">Baixas</a> você registra o que
            <strong>saiu do estoque sem virar receita</strong>.</p>

            <div class="tip">⚠️ <strong>Não venda por R$ 0,00 para dar baixa.</strong> Isso inventa
            uma venda que nunca existiu e suja a conferência do caixa. E deixar o exemplar como
            "disponível" é pior ainda: o saldo passa a contar um livro que não está mais lá, e o
            acerto com o fornecedor não fecha.</div>

            <p>São três informações e os exemplares:</p>
            <ul>
                <li><strong>Motivo</strong> → é ele que decide se a saída <strong>gera custo</strong>
                    (veja abaixo);</li>
                <li><strong>Autorizado por</strong> → quem decidiu. Texto livre, porque quem
                    autoriza nem sempre tem login: pode ser o preletor ou o pastor. Separado disso,
                    a lista registra sozinha <strong>quem lançou no app</strong> — são duas
                    perguntas diferentes;</li>
                <li><strong>Observação</strong> → opcional ("sorteio de sábado", "capa molhada");</li>
                <li><strong>Exemplares</strong> → toque no item e escolha quais saíram.
                    Uma baixa leva <strong>vários</strong>: sortear três livros informa motivo e
                    autorização <em>uma</em> vez.</li>
            </ul>

            <p>A lista mostra <strong>um cartão por item</strong>, com a capa e quantos estão
            disponíveis — 25 camisetas M aparecem como uma linha só, e não como 25 códigos quase
            iguais. Ao tocar, abre a <strong>escolha do exemplar</strong>: é ali que você seleciona
            o código que corresponde à etiqueta na mão.</p>

            <div class="tip">💡 <strong>Por que escolher o exemplar, e não só a quantidade?</strong>
            Porque é o código que faz a <strong>conferência física do estoque</strong> bater no fim
            do evento: o sistema sabe exatamente qual unidade saiu da caixa.</div>

            <p>O que você já escolheu fica <strong>marcado</strong> na lista do pop-up, com a opção
            de tirar. O filtro que você digitou <strong>não é limpo</strong> a cada escolha — dá
            para sortear três livros do mesmo título sem redigitar.</p>

            <details class="help-sub">
                <summary>💸 Por que a baixa custa dinheiro — e quando não custa</summary>
                <div class="help-sub-body">
                    <p>Sob consignação, o que <strong>não vende volta</strong> para a editora e não
                    se paga. Mas o exemplar <strong>sorteado ou dado</strong> não volta — então é
                    <strong>devido igual ao vendido</strong>, só que sem nenhuma receita em troca.</p>
                    <p>Por isso a tela mostra <strong>quanto a baixa vai custar antes de você
                    confirmar</strong>: sortear cinco livros de R$ 30 são R$ 150 devidos ao
                    fornecedor, e quem autoriza precisa ver isso na hora, não no fechamento.</p>
                    <p>A exceção é <strong>"Doação da editora"</strong>: o exemplar que a própria
                    editora mandou para o sorteio não é devido a ninguém — a tela mostra
                    <em>sem custo</em>.</p>
                    <div class="tip">💡 O custo fica <strong>gravado na baixa</strong>. Se alguém
                    mudar o motivo no futuro, o acerto de um evento já fechado não muda.</div>
                </div>
            </details>

            <details class="help-sub">
                <summary>↩️ Errou? Cancele a baixa</summary>
                <div class="help-sub-body">
                    <p>O botão <strong>Cancelar</strong> devolve os exemplares para o estoque e tira
                    o custo da conta do evento. A baixa continua na lista, marcada como cancelada —
                    nada é apagado.</p>
                    <p>Registrar e cancelar exige a permissão <code>livraria.baixar</code>. Quem só
                    vê a livraria consulta a lista, mas não recebe os botões.</p>
                </div>
            </details>
        </div>
    </details>

    {{-- ── 9. Estoque e Painel ────────────────────────────────── --}}
    <details class="help-item">
        <summary>📦 9. Estoque e Painel — acompanhar durante o evento</summary>
        <div class="help-body">
            <p><a href="{{ route('livraria.estoque') }}">Estoque</a> mostra o saldo do evento por
            linha (item + variação): <strong>enviados, disponível, vendido e baixado</strong>, com o
            fornecedor e o preço. Os números saem da <strong>contagem dos exemplares</strong> — não
            existe campo de quantidade que possa desencontrar do que está na caixa.</p>
            <p><a href="{{ route('painel') }}">Painel</a> é a tela do coordenador durante o evento:</p>
            <ul>
                <li><strong>Meta</strong> → com meta zero a zero, o número do topo não é lucro: é
                    <strong>quanto falta vender para cobrir os custos</strong>. É a única pergunta
                    capaz de mudar uma decisão no meio do dia. Se já passou, mostra a
                    <strong>sobra</strong>.</li>
                <li><strong>Números do evento</strong> → exemplares vendidos, devido aos
                    fornecedores, custos e baixas sem venda.</li>
                <li><strong>Exige atenção</strong> → o que está no fim (2 exemplares ou menos) ou
                    esgotado. Serve para avisar a mesa e <strong>anotar o título para a próxima
                    remessa enquanto a demanda ainda está visível</strong>.</li>
                <li><strong>Pagamento</strong> → quanto entrou por cada forma, com o número de
                    transações. Útil para conferir a maquininha no fim do dia.</li>
            </ul>
        </div>
    </details>

    {{-- ── O resultado ────────────────────────────────────────── --}}
    <details class="help-item">
        <summary>🎯 O resultado do evento — como a conta é feita</summary>
        <div class="help-body">
            <p>É a entrega central do módulo, e a fórmula é uma linha só:</p>
            <p style="text-align:center; font-weight:600; margin:12px 0">
                resultado = receita − devido aos fornecedores − custos − taxas
            </p>
            <ul>
                <li><strong>Receita</strong> → o que foi vendido, pelo preço da mesa, fora as vendas
                    canceladas.</li>
                <li><strong>Devido aos fornecedores</strong> → o custo unitário do que
                    <strong>saiu</strong> — vendido <em>e</em> baixado. Exemplar sorteado ou dado de
                    cortesia <strong>não volta para a editora</strong>: é devido igual ao vendido.</li>
                <li><strong>Custos</strong> → frete, banner e o que mais foi lançado na Remessa, cada
                    um pelo seu tipo de rateio.</li>
                <li><strong>Taxas</strong> → a taxa da maquininha, por transação, só onde houve
                    aquela forma de pagamento.</li>
            </ul>
            <p><strong>E as trocas?</strong> Elas entram sozinhas, sem nada a lançar à mão:</p>
            <ul>
                <li>a <strong>receita</strong> passa a valer o item que a pessoa
                    <em>levou</em>, porque ela sempre somou os itens da venda e não o
                    valor pago;</li>
                <li>a <strong>taxa</strong> de uma cobrança adicional entra na conta —
                    trocar por item mais caro e cobrar a diferença no cartão gera taxa
                    nova, que a operadora retém igual;</li>
                <li>na <strong>conferência por forma de pagamento</strong>, a diferença
                    aparece com sinal: o troco devolvido <strong>abate</strong> o total
                    daquela forma, que é o que aconteceu com o dinheiro. Sem isso o caixa
                    não fecharia.</li>
            </ul>
            <p><strong>Nenhum desses números é armazenado.</strong> Todos são calculados na hora, a
            partir dos exemplares e das vendas — assim não existe total guardado que possa discordar
            dos fatos.</p>
            <div class="tip">💡 Sob consignação, o que <strong>não vendeu volta</strong> e não entra
            no devido. É por isso que o saldo por exemplar precisa estar fiel: ele é a base do acerto
            com cada fornecedor.</div>
            <div class="tip">⚠️ Numa venda com troca, o <strong>valor pago</strong> e a
            <strong>soma dos itens</strong> são números diferentes de propósito — e a
            tela de Vendas mostra os dois. O primeiro é o que o extrato do banco vai
            mostrar; o segundo é a mercadoria que saiu. A diferença entre eles é
            exatamente o que entrou ou saiu do caixa na troca.</div>
        </div>
    </details>

    {{-- ── Acessos ────────────────────────────────────────────── --}}
    <details class="help-item">
        <summary>🎟️ Participantes — quem vem ao evento</summary>
        <div class="help-body">
            <p>O segundo módulo cuida de <strong>quem se inscreve</strong> num evento: a lista,
            a credencial com QR e a entrada na portaria.</p>

            <div class="tip">💡 <strong>Participante não é usuário do app.</strong> Ele não faz
            login, não tem senha nem perfil, e não aparece em <em>Pessoas</em>. Ele é inscrito
            num evento — são coisas diferentes de propósito.</div>

            <p>Na ordem em que se usa:</p>
            <ul>
                <li><strong>Dias do evento</strong> — nascem do período do evento ao tocar em
                    "Gerar do período". Cada dia tem <strong>a sua contagem de presença</strong>,
                    e é por isso que eles existem como cadastro: dá para nomear ("Sexta —
                    Abertura"), acrescentar um dia fora do período (retirada de kit na véspera)
                    e desativar um dia cancelado sem apagar quem já entrou.</li>
                <li><strong>Inscritos</strong> — a lista, com busca e filtros. É por aqui que se
                    cadastra alguém à mão e se envia as credenciais.</li>
                <li><strong>Check-in</strong> — a portaria. Fica fora do grupo no menu, ao lado
                    de Venda, porque é a tela mais usada durante o evento.</li>
                <li><strong>Lista em papel</strong> — imprima na véspera. É o plano B.</li>
            </ul>

            <details class="help-sub">
                <summary>🎫 A credencial com QR — como chega e como funciona</summary>
                <div class="help-sub-body">
                    <p>Em <a href="{{ route('participantes.inscritos') }}">Inscritos</a>, o botão
                    <strong>Credenciais</strong> abre o envio. Cada pessoa recebe um e-mail com
                    <strong>três caminhos para a mesma coisa</strong>, porque no dia qualquer um
                    pode falhar:</p>
                    <ul>
                        <li>o <strong>QR</strong>, embutido na mensagem — aparece sem clicar em
                            "exibir imagens" e funciona <strong>sem internet</strong>, porque já
                            está no e-mail baixado;</li>
                        <li>o <strong>código</strong> em letra grande (INS0042), para ditar na
                            entrada se o QR não ler;</li>
                        <li>o <strong>link</strong> da credencial, que abre em tela cheia e pode
                            ser salvo como PDF ou impresso.</li>
                    </ul>
                    <div class="tip">⚠️ <strong>Envie um teste para você antes do disparo geral</strong>,
                    e <strong>confira o spam</strong>. É assim que se descobre um problema de
                    entrega antes de dezenas de pessoas não receberem.</div>
                    <p>O envio vai <strong>em blocos</strong> e pode ser pausado. Fechar a página
                    não perde nada: quem já recebeu não recebe de novo, e iniciar outra vez
                    continua de onde parou.</p>
                    <p><strong>Para mandar só para uma pessoa</strong>, não é preciso disparar o
                    lote: cada linha da lista tem o botão <strong>Enviar credencial</strong>
                    (ou <strong>Reenviar credencial</strong>, para quem já recebeu). O e-mail sai
                    na hora, só para ela — é o caminho para quem corrigiu o endereço, entrou na
                    lista depois do disparo, ou simplesmente pediu de novo.</p>
                    <div class="tip">💡 <strong>Quem está com "sem e-mail válido"</strong> não vai
                    receber. A lista mostra quem são — avise essas pessoas por outro canal, e na
                    portaria elas entram pelo nome.</div>
                </div>
            </details>

            <details class="help-sub">
                <summary>🚪 A portaria — como o check-in acontece</summary>
                <div class="help-sub-body">
                    <p>Duas formas, e as duas terminam na mesma tela de confirmação:</p>
                    <ul>
                        <li><strong>Escaneando o QR</strong> com a câmera do celular — a câmera
                            normal do aparelho, sem app nenhum. Você precisa estar
                            <strong>logado</strong> no sistema para que o código abra o check-in.</li>
                        <li><strong>Buscando</strong> por nome, código, e-mail, telefone ou CPF.
                            Uma caixa só: ela entende o que você digitou.</li>
                    </ul>
                    <p>A tela de confirmação mostra <strong>quem é</strong> e
                    <strong>para qual dia</strong> a entrada vai — confira o nome antes de tocar
                    em confirmar. É o que impede marcar presença de quem apresentou o crachá de
                    outra pessoa.</p>
                    <div class="tip">💡 <strong>O mesmo QR serve os dois dias.</strong> O que
                    define a qual dia a entrada pertence é o <strong>dia de hoje</strong>, que
                    aparece em destaque na confirmação. Ninguém escolhe o dia na portaria —
                    justamente para não marcar no dia errado no meio da fila.</div>
                    <p><strong>Passou o crachá duas vezes?</strong> A tela avisa
                    <em>"já entrou às 19h12, por Maria"</em> em vez de contar de novo. É assim
                    que se percebe crachá emprestado.</p>
                    <p><strong>Apareceu alguém sem inscrição?</strong> Use
                    <em>Inscrever na hora</em>: dois campos e a pessoa entra já registrada. Sem
                    isso a portaria anota no papel e o dado nunca chega ao sistema.</p>
                </div>
            </details>

            <details class="help-sub">
                <summary>🖨️ Se a internet cair — a lista em papel</summary>
                <div class="help-sub-body">
                    <p>O app precisa de internet para funcionar; não há como contornar isso na
                    portaria. A saída é analógica e tem de estar pronta <strong>antes</strong>:</p>
                    <ul>
                        <li>imprima a <strong>Lista em papel</strong> na véspera — ela sai com o
                            código, o nome e um quadradinho por dia;</li>
                        <li>marque as entradas à caneta enquanto a rede não volta;</li>
                        <li>depois lance no app pela tela de check-in.</li>
                    </ul>
                    <div class="tip">💡 Leve também um segundo celular como reserva de internet,
                    de operadora diferente.</div>
                </div>
            </details>

            <details class="help-sub">
                <summary>👤 A mesma pessoa em vários eventos</summary>
                <div class="help-sub-body">
                    <p>O sistema guarda as pessoas <strong>entre eventos</strong>. Quando alguém
                    se inscreve de novo com o <strong>mesmo e-mail ou o mesmo CPF</strong>, é
                    reconhecida — os dados já vêm preenchidos e o histórico fica ligado.</p>
                    <p>Três detalhes que evitam confusão:</p>
                    <ul>
                        <li><strong>Casal que usa o mesmo e-mail</strong> continua sendo duas
                            pessoas. A credencial de cada um vai para aquele endereço mesmo.</li>
                        <li><strong>Corrigir um telefone hoje não muda o passado.</strong> A
                            inscrição guarda o que foi informado naquele evento — a lista de
                            presença do ano passado continua como era.</li>
                        <li><strong>"Conferir"</strong> numa inscrição significa que o e-mail e o
                            CPF apontaram para pessoas diferentes. O sistema não junta ninguém por
                            conta própria: fica para alguém olhar.</li>
                    </ul>
                </div>
            </details>
        </div>
    </details>

    <details class="help-item">
        <summary>🔑 Quem enxerga o quê</summary>
        <div class="help-body">
            <p>Cada tela exige uma permissão, dada pelo <strong>perfil de acesso</strong> da pessoa.
            Quem não tem a permissão não vê o item no menu.</p>
            <ul>
                <li><code>eventos.ver</code> / <code>eventos.gerenciar</code> — ver os eventos /
                    criar, editar e encerrar</li>
                <li><code>livraria.ver</code> — ver catálogo, saldo e relatórios</li>
                <li><code>livraria.catalogo</code> — fornecedores, categorias e catálogo de itens</li>
                <li><code>livraria.remessa</code> — montar remessas, lançar custos e definir preços</li>
                <li><code>livraria.vender</code> — registrar vendas na mesa</li>
                <li><code>livraria.corrigir</code> — corrigir, trocar itens e estornar venda já
                    registrada</li>
                <li><code>livraria.baixar</code> — dar baixa em exemplar sem venda (sorteio,
                    cortesia, doação, perda)</li>
                <li><code>livraria.fechamento</code> — acerto dos fornecedores, devolução e resultado</li>
                <li><code>participantes.ver</code> — ver os inscritos e a presença</li>
                <li><code>participantes.checkin</code> — registrar entrada na portaria</li>
                <li><code>participantes.gerenciar</code> — inscritos, dias e configurações</li>
                <li><code>participantes.enviar</code> — disparar as credenciais por e-mail</li>
                <li><code>participantes.importar</code> — importar a planilha de inscrições</li>
                <li><code>usuarios.ver</code> / <code>usuarios.gerenciar</code> /
                    <code>perfis.gerenciar</code> — pessoas e perfis de acesso</li>
            </ul>
            <details class="help-sub">
                <summary>🆕 Alguém se cadastrou — como liberar o acesso</summary>
                <div class="help-sub-body">
                    <p>Quem se cadastra pela tela <strong>Criar conta</strong> entra na fila:
                    a conta é criada, mas o vínculo nasce <strong>pendente</strong> e
                    <strong>sem perfil</strong>. Até ser liberada, a pessoa vê no login
                    <em>"Você não tem acesso ativo nesta congregação"</em>.</p>
                    <p>É de propósito: o Painel mostra receita, custos e o quanto se deve aos
                    fornecedores. Cadastro que já entrasse ativo entregaria o financeiro do
                    evento a qualquer um.</p>
                    <p>Para liberar, abra <strong>Pessoas</strong> no menu. Quem está esperando
                    aparece no topo, em <em>Aguardando liberação</em>, com e-mail e telefone:
                    <strong>escolha o perfil e toque em Liberar</strong>. <em>Recusar</em>
                    descarta o pedido — a conta continua existindo e a pessoa pode pedir de
                    novo.</p>
                    <div class="tip">💡 <strong>Liberar sem escolher perfil não adianta:</strong>
                    a pessoa entra, mas não enxerga nada além do Painel e desta Ajuda. É o
                    perfil que decide o que aparece no menu — por isso a tela recusa liberar
                    sem ele.</div>
                </div>
            </details>

            <div class="tip">💡 Existe um perfil <strong>Portaria</strong> pronto:
            <code>eventos.ver</code> + <code>participantes.ver</code> +
            <code>participantes.checkin</code>. É o voluntário que fica na porta marcando
            presença — e que <strong>não deve ver o financeiro</strong>, porque o Painel mostra
            receita, custos e o quanto se deve aos fornecedores.</div>
            <div class="tip">⚠️ <code>participantes.enviar</code> é separada de propósito:
            disparar credenciais gasta a cota de e-mail da hospedagem e
            <strong>não tem desfazer</strong>.</div>
            <div class="tip">💡 Para o voluntário de mesa, <code>livraria.vender</code> costuma
            bastar. Ele registra venda sem poder mexer em preço, custo ou remessa — que é o recorte
            certo para quem está atendendo a fila.</div>
        </div>
    </details>

    {{-- ── Ainda não existe ───────────────────────────────────── --}}
    <details class="help-item">
        <summary>🔐 Entrar, cadastrar-se e recuperar a senha</summary>
        <div class="help-body">
            <p>São três telas públicas, ligadas entre si pelos links do rodapé do card:</p>
            <ul>
                <li><strong>Entrar</strong> → congregação + e-mail + senha. A congregação
                    importa: a mesma pessoa pode servir em mais de uma, e o acesso é
                    <strong>por congregação</strong>.</li>
                <li><strong>Criar conta</strong> → qualquer pessoa se cadastra, mas o acesso
                    fica <strong>pendente</strong> até o Administrador liberar.</li>
                <li><strong>Esqueci minha senha</strong> → chega um link por e-mail, válido
                    por <strong>60 minutos</strong> e de <strong>uso único</strong>. Depois de
                    usado, ele deixa de funcionar — pedir outro é só repetir.</li>
            </ul>
            <div class="tip">💡 <strong>Já tem conta e tentou se cadastrar de novo?</strong> A
            tela responde "aguarde liberação" do mesmo jeito, mas nada muda — sua senha
            continua a mesma, de propósito. Nesse caso o caminho é
            <em>Esqueci minha senha</em>.</div>
            <p>As três respondem igual exista o e-mail ou não. É de propósito: assim o
            sistema não conta a quem está de fora quais e-mails têm cadastro.</p>
        </div>
    </details>

    <details class="help-item">
        <summary>👥 Pessoas — quem entra e com qual perfil</summary>
        <div class="help-body">
            <p>Em <a href="{{ route('usuarios') }}">Pessoas</a> ficam os acessos da
            congregação. Três coisas acontecem aqui:</p>
            <ul>
                <li><strong>Liberar quem se cadastrou</strong> — a fila fica no topo, porque é
                    o que exige ação.</li>
                <li><strong>Cadastrar direto</strong> — para quem não vai se cadastrar sozinho.
                    Você define a senha do primeiro acesso; ela pode trocar depois em
                    <em>Esqueci minha senha</em>.</li>
                <li><strong>Editar e desativar</strong> — trocar perfil, corrigir contato,
                    redefinir senha, ou tirar o acesso de quem saiu.</li>
            </ul>
            <p><strong>Desativar não apaga nada.</strong> A pessoa deixa de entrar, e tudo que
            ela registrou continua no histórico do evento — que é o que você quer no acerto.
            Ela continua na lista, marcada como <span class="pill closed">inativo</span>, e o
            mesmo botão a traz de volta.</p>
            <div class="tip">💡 <strong>Desativar vale na hora.</strong> Se a pessoa estiver
            usando o app naquele momento, ela é levada de volta ao login na ação seguinte —
            não fica com o Painel aberto até a sessão expirar.</div>
            <div class="tip">💡 <strong>Alguém que já usa outro app da igreja?</strong> Cadastre
            com o mesmo e-mail: o sistema reconhece a pessoa e só acrescenta o vínculo com esta
            congregação. <strong>A senha dela não muda</strong> — ela entra com a que já tem.</div>
            <div class="tip">⚠️ Você <strong>não consegue desativar o próprio acesso</strong>
            nem tirar o próprio super. É proposital: sem isso dá para se trancar do lado de
            fora, e aí só o banco resolve.</div>
        </div>
    </details>

    <details class="help-item">
        <summary>🔑 Perfis e acessos — desenhar o que cada função pode</summary>
        <div class="help-body">
            <p>Em <a href="{{ route('perfis') }}">Perfis</a> você cria os perfis da congregação
            e marca, um a um, o que cada um pode fazer. <strong>A matriz salva sozinha</strong>
            a cada toque.</p>
            <p>Vêm três prontos, e eles cobrem o caso comum:</p>
            <ul>
                <li><strong>Administrador</strong> — tudo, inclusive pessoas e perfis.</li>
                <li><strong>Coordenador da Livraria</strong> — monta o evento inteiro e libera
                    quem se cadastra; não mexe em perfis.</li>
                <li><strong>Operador de Mesa</strong> — vende e consulta. Não vê custo,
                    remessa nem cadastro.</li>
            </ul>
            <p>Crie um novo quando precisar de um recorte que esses três não dão — por exemplo
            alguém que confere a remessa sem enxergar o financeiro do Painel.</p>
            <div class="tip">⚠️ <code>perfis.gerenciar</code> é a <strong>chave mestra</strong>:
            quem a tem pode dar a si mesmo qualquer acesso. Reserve para pouquíssima gente.</div>
            <div class="tip">💡 Você não consegue <strong>apagar o seu próprio perfil</strong>
            nem tirar dele o "gerenciar perfis". É proposital: seria fechar a porta por dentro,
            e a única saída seria pelo banco.</div>
            <p><strong>Apagar um perfil não apaga ninguém.</strong> Quem o usava fica
            <em>sem perfil</em> — continua entrando, mas só vê o Painel e esta Ajuda até
            receber outro em <a href="{{ route('usuarios') }}">Pessoas</a>. A tela avisa quantas
            pessoas serão afetadas antes de confirmar.</p>
        </div>
    </details>

    <details class="help-item">
        <summary>🚧 O que ainda não está pronto</summary>
        <div class="help-body">
            <p>Para você não procurar um botão que não existe. Dá para montar e operar um evento
            inteiro pela interface — fornecedores, catálogo, remessa, venda, estoque e painel ao
            vivo. Ainda <strong>não</strong> têm tela própria:</p>
            <ul>
                <li><strong>Fechamento</strong> — acerto dos fornecedores e devolução do que não
                    vendeu. O resultado já é apurado e aparece no Painel; o passo formal de fechar
                    ainda não.</li>
                <li><strong>Importar a planilha de inscrições</strong> — por enquanto os
                    participantes entram um a um pela tela de Inscritos.</li>
                <li><strong>Formulário de inscrição no app</strong> — com perguntas montadas por
                    você, como os campos do catálogo. Hoje a inscrição vem de fora.</li>
            </ul>
        </div>
    </details>
</x-layouts.admin>
