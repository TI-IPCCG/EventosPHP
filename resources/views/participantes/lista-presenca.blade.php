{{-- Lista de presença em PAPEL.

     Não é relatório bonito: é o plano B para a internet cair na portaria. O app
     é renderizado no servidor, então sem rede nada funciona — e não vale tentar
     consertar isso a 13 dias do evento. Imprima na véspera.

     Os quadradinhos por dia são para marcar à caneta; o que for anotado entra
     depois por lançamento retroativo. --}}
@php
    $evento = App\Models\Event::atual();
    $dias = $evento
        ? App\Models\Participantes\EventDay::where('event_id', $evento->id)->ativos()->get()
        : collect();
    $inscritos = $evento
        ? App\Models\Participantes\Registration::where('event_id', $evento->id)
            ->ativas()->orderBy('nome')->get()
        : collect();
@endphp
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Lista de presença — {{ $evento?->nome }}</title>
    <style>
        :root { --linha: #D9DBD6; --fraco: #5A7161; }
        * { box-sizing: border-box; }
        body {
            margin: 0; padding: 18px;
            font-family: Arial, Helvetica, sans-serif; font-size: 12px; color: #212529;
        }
        header { border-bottom: 2px solid #334C3B; padding-bottom: 8px; margin-bottom: 12px; }
        h1 { margin: 0; font-size: 17px; }
        .sub { color: var(--fraco); font-size: 12px; margin-top: 2px; }
        .acoes { margin-bottom: 14px; }
        button, a.voltar {
            font: inherit; padding: 8px 14px; border-radius: 6px; cursor: pointer;
            border: 1px solid #334C3B; background: #334C3B; color: #fff; text-decoration: none;
        }
        a.voltar { background: none; color: #334C3B; margin-left: 6px; }
        table { width: 100%; border-collapse: collapse; }
        th, td { border-bottom: 1px solid var(--linha); padding: 6px 4px; text-align: left; }
        th { font-size: 11px; text-transform: uppercase; color: var(--fraco); }
        .cod { width: 70px; font-family: monospace; }
        .marca { width: 46px; text-align: center; }
        .quadro {
            display: inline-block; width: 15px; height: 15px;
            border: 1px solid #767B73; border-radius: 3px;
        }
        tbody tr:nth-child(even) { background: #F7F7F6; }
        .rodape { margin-top: 14px; color: var(--fraco); font-size: 11px; }

        /* Na folha: sem botão, sem zebra (gasta tinta), e o cabeçalho repete em
           toda página — uma lista de 300 nomes tem várias. */
        @media print {
            body { padding: 0; font-size: 11px; }
            .acoes { display: none; }
            tbody tr:nth-child(even) { background: none; }
            thead { display: table-header-group; }
            tr { page-break-inside: avoid; }
            @page { margin: 12mm; }
        }
    </style>
</head>
<body>
    <header>
        <h1>Lista de presença — {{ $evento?->nome ?? 'sem evento' }}</h1>
        <div class="sub">
            {{ $inscritos->count() }} {{ $inscritos->count() == 1 ? 'inscrito' : 'inscritos' }}
            @if ($evento) · impresso em {{ $evento->agora()->format('d/m/Y H:i') }} @endif
        </div>
    </header>

    <div class="acoes">
        <button type="button" onclick="window.print()">Imprimir</button>
        <a class="voltar" href="{{ route('participantes.checkin') }}">Voltar ao check-in</a>
    </div>

    @if ($inscritos->isEmpty())
        <p>Ninguém inscrito ainda.</p>
    @else
        <table>
            <thead>
                <tr>
                    <th class="cod">Código</th>
                    <th>Nome</th>
                    @foreach ($dias as $d)
                        <th class="marca">{{ $d->data->format('d/m') }}</th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
                @foreach ($inscritos as $i)
                    <tr>
                        <td class="cod">{{ $i->codigo }}</td>
                        <td>{{ $i->nome }}</td>
                        @foreach ($dias as $d)
                            <td class="marca"><span class="quadro"></span></td>
                        @endforeach
                    </tr>
                @endforeach
            </tbody>
        </table>

        <p class="rodape">
            Marque a entrada à caneta. Depois lance no app pela tela de check-in.
        </p>
    @endif
</body>
</html>
