{{-- CARDÁPIO DA LIVRARIA, para colar na parede.

     Sai do estoque real do evento, então não existe a lista de preços que
     envelhece sozinha: reimprimir já traz o que mudou na remessa.

     ── SOBRE A COR ──────────────────────────────────────────────────────
     O verde é a assinatura da casa, não o corpo do texto: ele fica no nome
     do evento e na régua, e nada mais. Preço em âmbar, nome do item em
     quase-preto. Cartaz de parede é lido de longe e na diagonal — o que
     precisa saltar é o TÍTULO e o PREÇO, e é por isso que só esses dois
     têm cor.

     ── AGRUPAMENTO ──────────────────────────────────────────────────────
     Camiseta P, M e G pelo mesmo preço é UMA linha ("P · M · G"), não três
     iguais. Quem lê na parede quer saber o que custa quanto, não quantas
     variações existem no banco. --}}
@php
    use App\Models\Event;
    use App\Models\Livraria\Copy;
    use App\Models\Livraria\ShipmentItem;

    /* Esta página atende DOIS públicos pela mesma view: o operador logado
       (Venda → Imprimir cardápio) e o participante, sem login nenhum.

       ⚠ Event::atual() serve só ao primeiro: ele lê session('event_id') e
       passa pelo ChurchScope, que filtra por session('church_id'). Sem sessão
       isso vira WHERE church_id IS NULL e devolve nada — a armadilha que já
       derrubou a reidratação de contexto e a credencial pública. Para o
       visitante, o evento é resolvido à mão, fora do escopo.

       A escolha do evento ESPELHA Event::atual(): em andamento primeiro,
       senão o mais recente. Olhar só `em_andamento` deixava o cardápio fora
       do ar justamente na semana que antecede o evento — que é quando as
       pessoas querem ver a lista —, porque até a abertura ele está em
       `planejamento`. Foi o que aconteceu.

       Isso pressupõe uma congregação por instalação, que é o caso hoje; com
       duas, a URL vai precisar dizer de quem é o cardápio. */
    $evento = auth()->check()
        ? Event::atual()
        : Event::withoutGlobalScopes()->emAndamento()->orderByDesc('inicio')->first()
            ?? Event::withoutGlobalScopes()->orderByDesc('inicio')->first();

    $publico = ! auth()->check();

    // Para quem consulta no celular a capa é o que identifica o livro; para a
    // parede, economizar papel costuma valer mais.
    $comCapa = $publico ? request('capas') !== '0' : request('capas') === '1';
    $mostrar = request('itens') === 'so_disponiveis' ? 'so_disponiveis' : 'todos';

    $linhas = $evento
        ? ShipmentItem::whereHas('shipment', fn ($q) => $q->where('event_id', $evento->id))
            // ⚠ withoutGlobalScopes no produto e na categoria: os dois usam
            // BelongsToChurch, e sem sessão o escopo zeraria a lista inteira
            // — a página abriria vazia para o visitante, sem erro nenhum.
            ->with([
                'product' => fn ($q) => $q->withoutGlobalScopes()
                    ->with(['category' => fn ($c) => $c->withoutGlobalScopes()->with('fields'), 'coverPhoto']),
                'variant',
            ])
            ->withCount(['copies as disponiveis' => fn ($q) => $q->where('status', Copy::DISPONIVEL)])
            ->get()
            ->filter(fn ($l) => $l->product && $l->product->category)
        : collect();

    if ($mostrar === 'so_disponiveis') {
        $linhas = $linhas->where('disponiveis', '>', 0);
    }

    // Uma entrada por (item, preço): a variação vira detalhe da linha, e preço
    // diferente para o mesmo título continua sendo linha própria — senão o
    // cartaz mentiria sobre quanto custa.
    $itens = $linhas
        ->groupBy(fn ($l) => $l->product_id.'-'.$l->preco_venda)
        ->map(function ($grupo) {
            $primeiro = $grupo->first();

            return (object) [
                'nome'       => $primeiro->product->nome,
                'categoria'  => $primeiro->product->category->nome,
                'detalhe'    => collect($primeiro->product->destaques())->pluck('valor')->join(' · '),
                'preco'      => (float) $primeiro->preco_venda,
                'capa'       => $primeiro->product->coverPhoto,
                'disponiveis' => (int) $grupo->sum('disponiveis'),
                'esgotado'   => $grupo->sum('disponiveis') === 0,
                /* Cada tamanho com o seu saldo, e não uma tira de nomes: quem
                   olha o cardápio para decidir quer saber se AQUELE tamanho
                   existe. "Camiseta P·M·G" não responde isso quando o P
                   acabou.

                   ⚠ Pela ORDEM da variação, nunca alfabética: ordenar P/M/G
                   por nome devolve "G · M · P". `ordem` existe para isto. */
                'tamanhos'   => $grupo->filter(fn ($l) => $l->variant)
                                      ->sortBy(fn ($l) => $l->variant->ordem)
                                      ->map(fn ($l) => (object) [
                                          'nome' => $l->variant->nome,
                                          'qtd'  => (int) $l->disponiveis,
                                      ])->values(),
            ];
        })
        /* O que dá para levar vem primeiro — dentro de cada categoria, o
           esgotado desce para o fim em vez de sumir: saber que o título existe
           (e acabou) é diferente de nunca ter ouvido falar dele. */
        ->sortBy([['categoria', 'asc'], ['esgotado', 'asc'], ['nome', 'asc']])
        ->groupBy('categoria');

    /* O evento guarda UM nome só ("2026 - Simpósio: Doutrina & Vida - Teologia
       na Prática"), mas o cartaz pede título e lema em pesos diferentes. A
       última parte depois de " - " vira o lema, e só quando ela é curta o
       bastante para passar por um — nome sem hífen fica inteiro no título, sem
       nada inventado embaixo. */
    $titulo = trim($evento?->nome ?? 'Livraria');
    $lema   = null;

    if (($corte = mb_strrpos($titulo, ' - ')) !== false) {
        $cauda = trim(mb_substr($titulo, $corte + 3));

        if (mb_strlen($cauda) >= 3 && mb_strlen($cauda) <= 40) {
            $lema   = $cauda;
            $titulo = trim(mb_substr($titulo, 0, $corte));
        }
    }
@endphp
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Cardápio — {{ $evento?->nome }}</title>
    {{-- Link para compartilhar no grupo, não página para o Google guardar
         depois que o evento acabar. Tirar uma linha reverte. --}}
    <meta name="robots" content="noindex">

    {{-- Merriweather é a serif da casa. Sem rede cai em Georgia, que tem o
         mesmo peso visual — o cartaz nunca depende da internet para sair. --}}
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Merriweather:ital,wght@0,400;0,700;0,900;1,400&display=swap" rel="stylesheet">

    <style>
        :root {
            --verde:  #334C3B;
            --ambar:  #BD7522;
            --tinta:  #1D2B23;
            --fraco:  #6B7A70;
            --linha:  #D9DBD6;
        }
        * { box-sizing: border-box; }
        body {
            margin: 0; padding: 26px 22px;
            font-family: 'Merriweather', Georgia, 'Times New Roman', serif;
            color: var(--tinta);
            background: #fff;
        }
        .folha { max-width: 760px; margin: 0 auto; }

        /* ── cabeçalho ── */
        .marca {
            text-align: center;
            font-size: 10px; letter-spacing: .22em; text-transform: uppercase;
            color: var(--verde); font-weight: 700;
        }
        h1 {
            margin: 6px 0 2px;
            text-align: center;
            font-size: 30px; font-weight: 900; letter-spacing: .01em;
            color: var(--verde);
        }
        .lema {
            text-align: center; font-style: italic;
            color: var(--ambar); font-size: 14px; margin-bottom: 14px;
        }
        .regua { height: 2px; background: var(--ambar); opacity: .85; margin-bottom: 20px; }

        /* ── seções ── */
        .secao {
            margin: 22px 0 8px;
            font-size: 11px; letter-spacing: .16em; text-transform: uppercase;
            color: var(--fraco); font-weight: 700;
        }
        .secao:first-of-type { margin-top: 0; }

        /* ── a linha do cardápio ──
           O pontilhado é um filete que cresce entre o nome e o preço: assim o
           olho atravessa a folha sem perder a linha, que é o problema real de
           ler preço a dois metros de distância. */
        .item {
            display: flex; align-items: baseline; gap: 8px;
            padding: 9px 0;
            border-bottom: 1px solid #EDEFEA;
        }
        .item:last-child { border-bottom: 0; }

        .capa {
            width: 34px; height: 46px; flex: 0 0 34px;
            object-fit: contain; background: #F4F5F2;
            border-radius: 2px; align-self: center;
        }
        .nome { font-weight: 700; font-size: 15px; }
        .detalhe { font-style: italic; color: var(--fraco); font-weight: 400; font-size: 13px; }
        /* Um bloco por tamanho, com o saldo. Separados por caixa e não por
           ponto: "P 2 · M 5" lido rápido vira "P 2 M 5", e o olho não sabe
           onde um acaba. A caixa resolve sem precisar de rótulo. */
        .tamanhos { display: flex; flex-wrap: wrap; gap: 5px; margin-top: 5px; }
        .tam {
            display: inline-flex; align-items: baseline; gap: 4px;
            padding: 2px 7px; border: 1px solid var(--linha); border-radius: 3px;
            font-size: 11px; letter-spacing: .04em;
        }
        .tam b { font-weight: 700; font-size: 12px; }
        .tam .estado { color: var(--fraco); font-size: 10px; }
        .tam.zero { border-style: dashed; opacity: .55; }
        .tam.zero .estado { color: #A33; }

        /* Item sem variação: a mesma informação, sem a caixa do tamanho. */
        .saldo {
            font-size: 11px; display: block; margin-top: 4px;
            color: var(--fraco);
        }
        .saldo.fora { color: #A33; }
        .filete {
            flex: 1 1 auto;
            border-bottom: 1px dotted #BFC6BD;
            transform: translateY(-4px);
            min-width: 18px;
        }
        .preco {
            font-weight: 700; font-size: 15px; color: var(--ambar);
            white-space: nowrap;
        }
        .esgotado {
            font-size: 10px; letter-spacing: .1em; text-transform: uppercase;
            color: #A33; font-weight: 700;
        }
        .item.fora .nome, .item.fora .preco { opacity: .45; }

        .rodape {
            margin-top: 26px; padding-top: 10px;
            border-top: 1px solid var(--linha);
            text-align: center; font-size: 11px; color: var(--fraco);
        }

        /* ── controles (só na tela) ── */
        .acoes {
            max-width: 760px; margin: 0 auto 18px;
            display: flex; gap: 6px; flex-wrap: wrap; align-items: center;
            font-family: Arial, Helvetica, sans-serif;
        }
        button, a.voltar, select {
            font: inherit; font-size: 13px; padding: 8px 14px; border-radius: 6px;
            border: 1px solid var(--verde); background: var(--verde); color: #fff;
            cursor: pointer; text-decoration: none;
        }
        a.voltar, select { background: #fff; color: var(--verde); }
        .vazio { text-align: center; color: var(--fraco); padding: 30px 0; }

        /* ── busca ──
           Filtra no próprio navegador: a lista tem dezenas de itens, e uma ida
           ao servidor a cada letra seria lenta justamente no 4G do evento, que
           é onde a página vai ser aberta. */
        .busca { position: relative; margin-bottom: 16px; }
        .busca input {
            width: 100%; padding: 11px 38px 11px 14px;
            font-family: Arial, Helvetica, sans-serif; font-size: 15px;
            border: 1px solid var(--linha); border-radius: 8px;
            color: var(--tinta); background: #fff;
        }
        .busca input:focus { outline: 2px solid var(--verde); outline-offset: -1px; }
        .busca .limpar {
            position: absolute; right: 6px; top: 50%; transform: translateY(-50%);
            border: 0; background: none; color: var(--fraco);
            font-size: 18px; line-height: 1; padding: 6px 8px; cursor: pointer;
        }
        .sem-resultado { display: none; text-align: center; color: var(--fraco); padding: 26px 0; }
        [hidden] { display: none !important; }

        @media print {
            body { padding: 0; }
            .acoes, .busca { display: none; }
            /* o título repete se o cardápio passar de uma folha */
            .item { page-break-inside: avoid; }
            .secao { page-break-after: avoid; }
            @page { margin: 14mm; }
        }
    </style>
</head>
<body>
    {{-- Os controles são do operador. Para quem só consulta no celular, eles
         seriam três decisões antes de ver o que interessa. --}}
    @unless ($publico)
        <form class="acoes" method="get">
            <select name="capas" onchange="this.form.submit()">
                <option value="0" @selected(! $comCapa)>Sem capas</option>
                <option value="1" @selected($comCapa)>Com capas</option>
            </select>
            <select name="itens" onchange="this.form.submit()">
                <option value="todos" @selected($mostrar === 'todos')>Tudo, disponíveis primeiro</option>
                <option value="so_disponiveis" @selected($mostrar === 'so_disponiveis')>Só o que tem em estoque</option>
            </select>
            <button type="button" onclick="window.print()">Imprimir</button>
            <a class="voltar" href="{{ route('livraria.venda') }}">Voltar à venda</a>
        </form>
    @endunless

    <div class="folha">
        <div class="marca">Igreja Presbiteriana Central de Campo Grande</div>
        <h1>{{ $titulo }}</h1>
        @if ($lema)
            <div class="lema">{{ $lema }}</div>
        @endif
        <div class="regua"></div>

        @if ($itens->isNotEmpty())
            <div class="busca">
                <input type="search" id="busca" autocomplete="off"
                       placeholder="Buscar por título, autor ou tamanho"
                       aria-label="Buscar no cardápio">
                <button type="button" class="limpar" id="limpar" hidden aria-label="Limpar busca">✕</button>
            </div>
        @endif

        @forelse ($itens as $categoria => $doGrupo)
            <div class="secao" data-secao="{{ $categoria }}">{{ $categoria }}</div>

            @foreach ($doGrupo as $i)
                {{-- O texto pesquisável é montado e NORMALIZADO no servidor:
                     assim o JS só compara strings, e buscar "simposio" acha
                     "Simpósio" sem o celular ter de normalizar a lista toda a
                     cada tecla. --}}
                <div class="item {{ $i->esgotado ? 'fora' : '' }}"
                     data-secao="{{ $categoria }}"
                     data-busca="{{ Str::lower(Str::ascii(
                         $i->nome.' '.$i->detalhe.' '.$categoria.' '.$i->tamanhos->pluck('nome')->join(' ')
                     )) }}">
                    @if ($comCapa)
                        @if ($i->capa)
                            <img class="capa" src="{{ $i->capa->urlThumb() }}" alt="">
                        @else
                            <span class="capa"></span>
                        @endif
                    @endif

                    <span>
                        <span class="nome">{{ $i->nome }}</span>
                        @if ($i->detalhe)
                            <span class="detalhe">— {{ $i->detalhe }}</span>
                        @endif
                        @if ($i->tamanhos->isNotEmpty())
                            <span class="tamanhos">
                                @foreach ($i->tamanhos as $t)
                                    <span class="tam {{ $t->qtd === 0 ? 'zero' : '' }}">
                                        <b>{{ $t->nome }}</b>
                                        <span class="estado">
                                            {{ $t->qtd === 0 ? 'Indisponível' : 'Disponível' }}
                                        </span>
                                    </span>
                                @endforeach
                            </span>
                        @else
                            <span class="saldo {{ $i->esgotado ? 'fora' : '' }}">
                                {{ $i->esgotado ? 'Indisponível' : 'Disponível' }}
                            </span>
                        @endif
                    </span>

                    <span class="filete"></span>

                    <span class="preco">R$ {{ number_format($i->preco, 2, ',', '.') }}</span>
                </div>
            @endforeach
        @empty
            <p class="vazio">
                @if ($evento)
                    Nenhum item em estoque. Monte a remessa primeiro.
                @else
                    Nenhum evento em andamento.
                @endif
            </p>
        @endforelse

        <p class="sem-resultado" id="sem-resultado">Nada encontrado.</p>

        @if ($itens->isNotEmpty())
            <div class="rodape">
                Preços sujeitos à disponibilidade ·
                {{ $evento?->agora()->format('d/m/Y') }}
            </div>
        @endif
    </div>

    @if ($itens->isNotEmpty())
    <script>
        (function () {
            const campo   = document.getElementById('busca');
            const limpar  = document.getElementById('limpar');
            const nada    = document.getElementById('sem-resultado');
            const itens   = Array.from(document.querySelectorAll('.item'));
            const secoes  = Array.from(document.querySelectorAll('.secao'));

            // mesma normalização do servidor: minúsculo e sem acento
            const limpa = (t) => t.normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase().trim();

            function filtrar() {
                const termo = limpa(campo.value);
                let achou = 0;

                itens.forEach((el) => {
                    const casa = termo === '' || el.dataset.busca.includes(termo);
                    el.hidden = ! casa;
                    if (casa) achou++;
                });

                // seção sem nenhum item visível some junto — senão sobra um
                // título de categoria sobre o vazio
                secoes.forEach((s) => {
                    s.hidden = ! itens.some((el) => el.dataset.secao === s.dataset.secao && ! el.hidden);
                });

                nada.style.display = achou === 0 ? 'block' : 'none';
                limpar.hidden = campo.value === '';
            }

            campo.addEventListener('input', filtrar);
            limpar.addEventListener('click', () => { campo.value = ''; campo.focus(); filtrar(); });

            // Imprimir com a busca ativa sairia com metade do cardápio: o que
            // vai para o papel é sempre a lista inteira.
            window.addEventListener('beforeprint', () => {
                itens.forEach((el) => { el.hidden = false; });
                secoes.forEach((s) => { s.hidden = false; });
                nada.style.display = 'none';
            });
            window.addEventListener('afterprint', filtrar);
        })();
    </script>
    @endif
</body>
</html>
