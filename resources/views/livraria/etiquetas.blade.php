{{-- ETIQUETAS DOS EXEMPLARES, em papel.

     É OPCIONAL, e de propósito: nada no app exige que o exemplar esteja
     etiquetado. Quem quiser colar cola; quem não colar continua vendendo pelo
     título, com o sistema escolhendo o exemplar sozinho. Nenhum fluxo mudou
     por causa desta folha.

     O que ela resolve para quem cola: digitar o código na mesa é o caminho
     mais rápido de todos, e no fechamento dá para conferir a caixa exemplar
     por exemplar em vez de contar por título.

     Filtros pela URL (?fornecedor=, ?status=), porque quem imprime costuma
     querer um fornecedor de cada vez — a remessa chega em caixas separadas. --}}
@php
    use App\Models\Livraria\Copy;
    use App\Models\Livraria\Supplier;

    $evento = App\Models\Event::atual();

    $fornecedorId = (int) request('fornecedor') ?: null;
    // Só os dois valores que fazem sentido; qualquer outra coisa cai no padrão.
    $status = request('status') === 'todos' ? 'todos' : 'disponiveis';

    $fornecedores = $evento
        ? Supplier::whereIn('id', function ($q) use ($evento) {
            $q->select('supplier_id')->from('liv_shipments')->where('event_id', $evento->id);
        })->orderBy('nome')->get()
        : collect();

    $etiquetas = $evento
        ? Copy::where('event_id', $evento->id)
            ->when($status === 'disponiveis', fn ($q) => $q->where('status', Copy::DISPONIVEL))
            ->when($fornecedorId, fn ($q) => $q->whereHas(
                'shipmentItem.shipment',
                fn ($s) => $s->where('supplier_id', $fornecedorId)
            ))
            ->with('shipmentItem.product', 'shipmentItem.variant')
            ->orderBy('codigo')
            ->get()
        : collect();
@endphp
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Etiquetas — {{ $evento?->nome }}</title>
    <style>
        :root { --linha: #D9DBD6; --fraco: #5A7161; --verde: #334C3B; }
        * { box-sizing: border-box; }
        body {
            margin: 0; padding: 18px;
            font-family: Arial, Helvetica, sans-serif; font-size: 12px; color: #212529;
        }
        header { border-bottom: 2px solid var(--verde); padding-bottom: 8px; margin-bottom: 12px; }
        h1 { margin: 0; font-size: 17px; }
        .sub { color: var(--fraco); font-size: 12px; margin-top: 2px; }
        .acoes { margin-bottom: 14px; display: flex; gap: 6px; flex-wrap: wrap; align-items: center; }
        button, a.voltar {
            font: inherit; padding: 8px 14px; border-radius: 6px; cursor: pointer;
            border: 1px solid var(--verde); background: var(--verde); color: #fff; text-decoration: none;
        }
        a.voltar { background: none; color: var(--verde); }
        select { font: inherit; padding: 7px 10px; border-radius: 6px; border: 1px solid var(--linha); }
        .aviso {
            border: 1px solid #BD7522; background: rgba(189,117,34,.10);
            border-radius: 6px; padding: 10px 12px; margin-bottom: 14px; font-size: 12px;
        }

        /* A grade cai para 3 colunas em A4 com margem de 10mm. Etiqueta em
           tracejado porque é linha de corte, não moldura decorativa. */
        .folha { display: grid; grid-template-columns: repeat(3, 1fr); gap: 6px; }
        .etiqueta {
            border: 1px dashed #767B73; border-radius: 4px;
            padding: 8px 10px; min-height: 76px;
            display: flex; flex-direction: column; justify-content: space-between;
        }
        .et-nome {
            font-size: 11px; line-height: 1.25; font-weight: bold;
            /* duas linhas no máximo: título longo não pode empurrar o código
               para fora da etiqueta, que é a única coisa insubstituível aqui */
            display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical;
            overflow: hidden;
        }
        .et-var { font-weight: normal; color: var(--fraco); }
        .et-rodape { display: flex; justify-content: space-between; align-items: flex-end; gap: 6px; }
        .et-codigo { font-family: monospace; font-size: 15px; font-weight: bold; letter-spacing: .5px; }
        .et-preco { font-size: 12px; white-space: nowrap; }

        @media print {
            body { padding: 0; }
            .acoes, .aviso, header { display: none; }
            .folha { gap: 4px; }
            .etiqueta { page-break-inside: avoid; }
            @page { margin: 10mm; }
        }
    </style>
</head>
<body>
    <header>
        <h1>Etiquetas — {{ $evento?->nome ?? 'sem evento' }}</h1>
        <div class="sub">
            {{ $etiquetas->count() }}
            {{ $etiquetas->count() == 1 ? 'etiqueta' : 'etiquetas' }}
            @if ($evento) · gerado em {{ $evento->agora()->format('d/m/Y H:i') }} @endif
        </div>
    </header>

    <form class="acoes" method="get">
        <select name="fornecedor" onchange="this.form.submit()">
            <option value="">Todos os fornecedores</option>
            @foreach ($fornecedores as $f)
                <option value="{{ $f->id }}" @selected($fornecedorId === $f->id)>{{ $f->nome }}</option>
            @endforeach
        </select>

        <select name="status" onchange="this.form.submit()">
            <option value="disponiveis" @selected($status === 'disponiveis')>Só os disponíveis</option>
            <option value="todos" @selected($status === 'todos')>Todos os exemplares</option>
        </select>

        <button type="button" onclick="window.print()">Imprimir</button>
        <a class="voltar" href="{{ route('livraria.remessa') }}">Voltar à remessa</a>
    </form>

    <div class="aviso">
        <strong>Colar etiqueta é opcional.</strong> Nada no app depende disso — sem etiqueta a mesa
        vende pelo título normalmente. Ela só torna mais rápido digitar o código na venda e conferir
        a caixa no fechamento.
        <br>
        ⚠️ <strong>O preço impresso é o de hoje.</strong> Se ainda for mexer nos preços da remessa,
        imprima depois — papel colado não se atualiza sozinho.
    </div>

    @if ($etiquetas->isEmpty())
        <p>Nenhum exemplar para esses filtros.</p>
    @else
        <div class="folha">
            @foreach ($etiquetas as $c)
                <div class="etiqueta">
                    <div class="et-nome">
                        {{ $c->shipmentItem->product->nome }}
                        @if ($c->shipmentItem->variant)
                            <span class="et-var">· {{ $c->shipmentItem->variant->nome }}</span>
                        @endif
                    </div>
                    <div class="et-rodape">
                        <span class="et-codigo">{{ $c->codigo }}</span>
                        <span class="et-preco">R$ {{ number_format($c->shipmentItem->preco_venda, 2, ',', '.') }}</span>
                    </div>
                </div>
            @endforeach
        </div>
    @endif
</body>
</html>
