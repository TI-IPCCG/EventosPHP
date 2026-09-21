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
    use App\Models\Livraria\Copy;
    use App\Models\Livraria\ShipmentItem;

    $evento = App\Models\Event::atual();
    $comCapa = request('capas') === '1';
    $mostrar = request('itens') === 'todos' ? 'todos' : 'disponiveis';

    $linhas = $evento
        ? ShipmentItem::whereHas('shipment', fn ($q) => $q->where('event_id', $evento->id))
            ->with(['product.category.fields', 'product.coverPhoto', 'variant'])
            ->withCount(['copies as disponiveis' => fn ($q) => $q->where('status', Copy::DISPONIVEL)])
            ->get()
        : collect();

    if ($mostrar === 'disponiveis') {
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
                'esgotado'   => $grupo->sum('disponiveis') === 0,
                // ⚠ Pela ORDEM da variação, nunca alfabética: ordenar P/M/G
                // por nome devolve "G · M · P", que é exatamente o contrário
                // do que a pessoa espera ler. `ordem` existe para isto.
                'variacoes'  => $grupo->sortBy(fn ($l) => $l->variant?->ordem ?? 0)
                                      ->map(fn ($l) => $l->variant?->nome)
                                      ->filter()->unique()->join(' · '),
            ];
        })
        ->sortBy([['categoria', 'asc'], ['nome', 'asc']])
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
        .variacoes {
            font-size: 11px; color: var(--fraco);
            letter-spacing: .05em; display: block; margin-top: 2px;
        }
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

        @media print {
            body { padding: 0; }
            .acoes { display: none; }
            /* o título repete se o cardápio passar de uma folha */
            .item { page-break-inside: avoid; }
            .secao { page-break-after: avoid; }
            @page { margin: 14mm; }
        }
    </style>
</head>
<body>
    <form class="acoes" method="get">
        <select name="capas" onchange="this.form.submit()">
            <option value="0" @selected(! $comCapa)>Sem capas</option>
            <option value="1" @selected($comCapa)>Com capas</option>
        </select>
        <select name="itens" onchange="this.form.submit()">
            <option value="disponiveis" @selected($mostrar === 'disponiveis')>Só o que tem em estoque</option>
            <option value="todos" @selected($mostrar === 'todos')>Todos os itens da remessa</option>
        </select>
        <button type="button" onclick="window.print()">Imprimir</button>
        <a class="voltar" href="{{ route('livraria.venda') }}">Voltar à venda</a>
    </form>

    <div class="folha">
        <div class="marca">Igreja Presbiteriana Central de Campo Grande</div>
        <h1>{{ $titulo }}</h1>
        @if ($lema)
            <div class="lema">{{ $lema }}</div>
        @endif
        <div class="regua"></div>

        @forelse ($itens as $categoria => $doGrupo)
            <div class="secao">{{ $categoria }}</div>

            @foreach ($doGrupo as $i)
                <div class="item {{ $i->esgotado ? 'fora' : '' }}">
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
                        @if ($i->variacoes)
                            <span class="variacoes">{{ $i->variacoes }}</span>
                        @endif
                    </span>

                    <span class="filete"></span>

                    @if ($i->esgotado)
                        <span class="esgotado">esgotado</span>
                    @endif
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

        @if ($itens->isNotEmpty())
            <div class="rodape">
                Preços sujeitos à disponibilidade ·
                {{ $evento?->agora()->format('d/m/Y') }}
            </div>
        @endif
    </div>
</body>
</html>
