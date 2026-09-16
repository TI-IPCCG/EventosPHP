{{-- A CREDENCIAL do participante.

     É a "página que vira PDF": o navegador salva em um toque (Ctrl+P →
     Salvar como PDF), o que entrega o PDF sem o projeto ganhar uma biblioteca
     de PDF só para isso. E, diferente de um arquivo gerado, ela está sempre
     atualizada.

     Rota PÚBLICA, e de propósito: é o ingresso da pessoa, que não tem login. O
     token no endereço já é o segredo. Ela é SEPARADA de /p/{token} (o check-in,
     que exige sessão do operador) — juntar as duas deixaria o endpoint de
     check-in a um auth()->check() de distância. --}}
@php
    // $evento vem da rota, já sem o ChurchScope: quem abre esta página não tem
    // sessão, e carregá-lo daqui devolveria null.
    $qr = app(App\Services\Participantes\QrService::class);
    $dias = App\Models\Participantes\EventDay::where('event_id', $evento->id)->ativos()->get();
@endphp
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Credencial — {{ $inscricao->nome }}</title>
    <style>
        :root {
            --primary: #334C3B; --accent: #BD7522;
            --fg: #212529; --fraco: #5A7161; --linha: #D9DBD6; --fundo: #F7F7F6;
        }
        * { box-sizing: border-box; }
        body {
            margin: 0; padding: 20px; background: var(--fundo); color: var(--fg);
            font-family: Arial, Helvetica, sans-serif;
            display: flex; flex-direction: column; align-items: center;
        }
        .cartao {
            width: 100%; max-width: 380px; background: #fff;
            border: 1px solid var(--linha); border-radius: 14px; overflow: hidden;
        }
        .topo { background: var(--primary); color: #fff; padding: 18px 20px; }
        .marca { font-family: Georgia, serif; font-size: 20px; font-weight: bold; }
        .modulo { color: var(--accent); font-size: 13px; }
        .corpo { padding: 20px; text-align: center; }
        .nome { font-size: 20px; font-weight: bold; margin: 0 0 2px; }
        .evento { color: var(--fraco); font-size: 13px; margin: 0 0 16px; }

        .qr { width: 220px; height: 220px; margin: 0 auto; display: block; }
        .qr rect { fill: #fff; }
        .qr path { fill: var(--primary); }

        .codigo {
            font-family: monospace; font-size: 26px; font-weight: bold;
            letter-spacing: 2px; margin: 14px 0 4px; color: var(--primary);
        }
        .codigo-ajuda { font-size: 12px; color: var(--fraco); margin: 0 0 16px; }
        .quando {
            border-top: 1px solid var(--linha); padding-top: 14px;
            font-size: 13px; color: var(--fraco); text-align: left;
        }
        .quando strong { color: var(--fg); }
        .quando ul { margin: 6px 0 0; padding-left: 18px; }

        .acoes { margin-top: 16px; }
        button {
            font: inherit; padding: 10px 18px; border-radius: 8px; cursor: pointer;
            border: 1px solid var(--primary); background: var(--primary); color: #fff;
        }
        .dica { color: var(--fraco); font-size: 12px; margin-top: 10px; text-align: center; }

        /* Na impressão: só o cartão, sem fundo cinza (gasta tinta) e sem botão.
           A credencial cabe inteira numa folha, e é isso que o "salvar como PDF"
           produz. */
        @media print {
            body { padding: 0; background: #fff; }
            .cartao { border: 0; max-width: none; }
            .acoes, .dica { display: none; }
            @page { margin: 14mm; }
        }
    </style>
</head>
<body>
    <div class="cartao">
        <div class="topo">
            <div class="marca">IPCCG</div>
            <div class="modulo">{{ $evento->nome }}</div>
        </div>

        <div class="corpo">
            <p class="nome">{{ $inscricao->nome }}</p>
            <p class="evento">
                @if ($evento->local) {{ $evento->local }} @endif
            </p>

            {!! $qr->svg($inscricao) !!}

            <div class="codigo">{{ $inscricao->codigo }}</div>
            <p class="codigo-ajuda">Se o QR não ler, informe este código na entrada.</p>

            <div class="quando">
                <strong>Quando</strong>
                @if ($dias->isNotEmpty())
                    <ul>
                        @foreach ($dias as $d)
                            <li>{{ $d->rotulo() }}</li>
                        @endforeach
                    </ul>
                @else
                    <ul><li>{{ $evento->inicio->format('d/m/Y') }}</li></ul>
                @endif
            </div>
        </div>
    </div>

    <div class="acoes">
        <button type="button" onclick="window.print()">Salvar ou imprimir</button>
    </div>
    <p class="dica">
        Apresente esta tela na entrada — não precisa estar impressa.
    </p>
</body>
</html>
