# Boas práticas — PHP/Laravel e engenharia em geral

Este documento é um **caderno de campo**: cada prática vem com o princípio portátil
(serve pra qualquer projeto) **e** um caso concreto onde ela mordeu ou salvou a
pele aqui no EscalaMembros, com link pro arquivo. A ideia é servir pra **estudo** e
pra **referência** — leve os princípios pra projetos futuros; os exemplos são só a
prova de que não é teoria.

> Formato de cada item: **o princípio** → *por quê / trade-off* → **No EscalaMembros**
> (o caso real) → **Como aplicar/detectar**.
>
> É um doc vivo — deve crescer conforme aprendermos mais.

Índice:
1. [Banco & performance](#1-banco--performance)
2. [Segurança & autorização](#2-segurança--autorização)
3. [Evolução de schema](#3-evolução-de-schema)
4. [Tempo & fuso horário](#4-tempo--fuso-horário)
5. [Permissões & governança](#5-permissões--governança)
6. [Testes](#6-testes)
7. [UX & acabamento](#7-ux--acabamento)
8. [Processo](#8-processo)
9. [Livewire / PHP específicos](#9-livewire--php-específicos)

---

## 1. Banco & performance

### Nunca faça uma query por item dentro de um laço (N+1)
Cada query é uma ida-e-volta ao banco (~ms). "Busquei N itens e faço +1 consulta
por item" cresce em linha reta com o volume e é a causa de lentidão nº 1. Resolva
**em lote**: uma query sobre o conjunto inteiro (`whereIn`) + cruzamento em memória,
ou eager loading (`with(...)`).

**No EscalaMembros:** o [`ScheduleAllocator::eligible()`](app/Services/ScheduleAllocator.php)
checava choque + indisponibilidade com 2 queries **por candidato** → `1 + 2N` (20
voluntários = 41 idas ao banco só pra abrir o modal "Preencher vaga"). Virou 2
queries em lote (`->pluck('user_id')->unique()->flip()` + `->has()`) → ~3 fixas,
não importa o tamanho do pool. Mesmo padrão na aba "Abertas à candidatura".

**Como aplicar/detectar:** antes de fechar qualquer feature com listagem/loop,
pergunte "isso bate no banco por item?". Se sim, vire lote. Quando o ganho importa,
**trave com um teste que conta queries** (veja seção 6).

### Carregue relações com eager loading, não lazy no meio do Blade
Um `@foreach` que acessa `$x->relacao->campo` sem `with('relacao')` dispara uma
query por linha renderizada.

**Como aplicar:** carregue as relações que a view usa já na query
(`->with(['event', 'volunteerRole.ministry'])`), como em `⚡escalas` e
`⚡minhas-escalas`.

### Um conjunto em memória (`flip()->has()`) em vez de varrer a lista a cada teste
`->contains($id)` numa lista percorre a lista toda a cada checagem (O(n)). Virar a
lista num "dicionário" (`->flip()`) deixa o "esse está aqui?" instantâneo (O(1)).

---

## 2. Segurança & autorização

### Defesa em profundidade: gate na rota **e** na ação
O `can:` da rota protege o acesso à tela, mas não substitui checar a permissão em
cada escrita — se um dia o gate da rota mudar (ou a config de middleware), a
escrita segue protegida.

**No EscalaMembros:** todo componente admin revalida a permissão em cada método de
escrita — `abort_unless(...)` inline (escalas, modelos, voluntarios) ou um helper
`guard()` ([⚡eventos](resources/views/components/admin/⚡eventos.blade.php),
ministerios, perfis).

### Isolamento multi-tenant por escopo, não por confiança no input
Num sistema multi-igreja, **toda** query precisa estar presa ao tenant da sessão.
Confiar que "o id veio da tela certa" é IDOR esperando acontecer.

**No EscalaMembros:** acesso a `Schedule`/`VolunteerRole`/`TemplateRequirement`
passa por `whereHas('event'|'ministry'|'template')` sobre um pai com `ChurchScope`,
ou por `User::inCurrentChurch()`. Nenhuma query lê dados de outra congregação.

### Ações "minhas" filtram por `auth()->id()` (anti-IDOR)
Remover indisponibilidade, aceitar transferência, cancelar candidatura — tudo
filtra pelo usuário logado no servidor, nunca só pelo id que veio do cliente.

### Cuidado: "permissão A não implica permissão B" (footgun de gate)
Se uma permissão "menor" precisa alcançar uma tela cujo gate é **outra** permissão,
ou você força a implicação, ou o gate da rota aceita o **conjunto**.

**No EscalaMembros:** a rota de Escalas era `can:escalas.ver`, mas `escalas.vagas`
não inclui `escalas.ver` — um líder só com `vagas` tomava **403 na própria tela**.
Corrigido com um gate `ver-escalas = ver OU gerir-vagas` em
[`AppServiceProvider`](app/Providers/AppServiceProvider.php). Prefira o gate a
depender de configuração humana correta.

### Super-admin como exceção controlada, não bypass espalhado
Um `Gate::before` central concede tudo pra super-admin **num lugar só**, em vez de
`if (is_super)` espalhado.

---

## 3. Evolução de schema

### Expand → Migrate → Contract (nunca mude tudo de uma vez)
Ao refatorar dados: (1) **adicione** o novo (colunas/tabelas) sem remover o velho;
(2) **migre** dados e código pra ler o novo; (3) só depois, quando nada mais usa o
velho, **remova** (contração). Cada passo é reversível.

**No EscalaMembros:** a separação identidade↔vínculo virou a tabela `memberships`
sem dropar `users.church_id/system_role_id/status` (viraram vestigiais). A
"contração" está **deliberadamente adiada** — big rewrite de teste, zero ganho
funcional. Documentado como dívida.

### Backfill sem regressão
Ao introduzir um novo campo/permissão, garanta que quem já existia **não perca
capacidade** da noite pro dia.

**No EscalaMembros:** ao criar `escalas.vagas`, uma
[migration de backfill](database/migrations) deu essa permissão a **todo perfil que
já tinha `escalas.gerenciar`** (`INSERT ... SELECT` idempotente). Ninguém acordou
com menos poder.

### Ponte de transição em vez de big-bang
Enquanto o velho e o novo coexistem, um hook mantém os dois em sincronia — o código
novo já lê o novo, o velho ainda funciona.

**No EscalaMembros:** o hook `created` no [`User`](app/Models/User.php) espelha um
`membership` a partir das colunas legadas. Some quando a contração acontecer.

### Em produção sem migrations: SQL manual **antes** do merge
Se o prod aplica schema por SQL manual (não `artisan migrate`), o código novo lê
tabelas/colunas que precisam existir **antes**. A ordem é: rodar o SQL no prod →
depois merge/deploy do código. Inverter = erro 500 no ar.

---

## 4. Tempo & fuso horário

### Decida cedo: "hora-de-parede" local vs UTC canônico
Guardar tudo em UTC e converter na borda é o "certo" e robusto pra sempre, mas caro.
Guardar hora local é simples, mas exige que **todo** contexto opere no fuso certo.
Não existe almoço grátis — escolha consciente e **documente**.

**No EscalaMembros:** optamos por hora-de-parede local (um middleware fixa o fuso da
igreja em toda request web). UTC canônico ficou como dívida registrada, só
necessário se surgir relatório/API cross-fuso.

### Bug que só aparece **fora do caminho web** = estado que o middleware injeta e o CLI não tem
Cron, fila, comando de console rodam **sem** a sessão/middleware da request. Se algo
depende de estado injetado por middleware (fuso, tenant, locale), o CLI não tem —
e o bug passa batido nos testes que rodam tudo no mesmo default.

**No EscalaMembros:** o cron `escalas:lembrar` rodava em UTC e comparava horários
gravados em hora-local como se fossem UTC (deslocamento = offset da igreja). Corrigido
iterando **por igreja** com `now()->setTimezone($church->timezone)` — a mesma ideia
do middleware, aplicada no CLI ([SendConfirmReminders](app/Console/Commands/SendConfirmReminders.php)).
O teste que faltava congelava o relógio **num fuso diferente** ([LembretesTest](tests/Feature/Admin/LembretesTest.php)).

**Como detectar:** ao escrever cron/fila/comando, pergunte "que estado a request
web tem que aqui não tem?" e reproduza **nesse** contexto no teste.

---

## 5. Permissões & governança

### Modele o que o dono do produto realmente pensa, não o que parece elegante no papel
A fronteira "certa" entre permissões é a que existe na cabeça de quem usa. Descobre-se
usando, não desenhando.

**No EscalaMembros:** a separação de escala levou **3 tentativas** — "estrutura vs
escalar" parecia elegante, mas a fronteira real era **"meu ministério vs culto
inteiro"**. Só o uso mostrou. Está no [guia-didático](Docs/guia-didatico.md).

### Superset vs subconjunto: dar *menos* sem *tirar* de quem já tinha
Ao criar uma permissão "menor" pra governança, ela é um **subconjunto** de uma maior
— não uma "metade". Assim o grant maior continua fazendo tudo.

**No EscalaMembros:** `escalas.gerenciar` faz tudo (inclusive gerar de modelo);
`escalas.vagas` é o subconjunto (gerencia vagas do ministério, sem gerar o culto
inteiro). Uma tentativa anterior "cortou gerenciar ao meio" e tirou poder de quem já
tinha — regressão pega em uso.

### Não super-restrinja "por segurança"
Restrição a mais que quebra o fluxo real é tão ruim quanto permissão a mais. Recorte
onde faz sentido de negócio (ex.: por ministério), não em tudo.

---

## 6. Testes

### Teste comportamento, não implementação
Asserte o **resultado observável** (o que muda no banco / na resposta), não os passos
internos. Assim o refactor não quebra o teste à toa.

**No EscalaMembros:** o refactor anti-N+1 mudou o *como* (queries em lote) mantendo o
*quê* — e todos os testes de elegibilidade passaram sem tocar.

### Teste de regressão pra invariantes que "voltam sorrateiramente"
Alguns problemas não quebram funcionalidade, só voltam devagar (lentidão, deslocamento
de fuso). Trave com um teste que falha **na hora** se o problema retornar.

**No EscalaMembros:**
- **Contar queries** — `test_eligible_nao_faz_query_por_candidato` em
  [AlocacaoTest](tests/Feature/Admin/AlocacaoTest.php): 8 candidatos ⇒ ≤8 queries. Se
  o N+1 voltar, dispara pra ~17 e quebra.
- **Relógio congelado + fuso diferente** — o teste cross-timezone dos lembretes.

### Reproduza o contexto real, não um conveniente
Um teste que roda tudo em UTC nunca pega um bug de fuso. Um que age como admin-tudo
nunca pega um recorte de permissão. Monte o cenário que **expõe** o risco.

### Ferramentas que usamos
`Notification::fake()` + `assertSentTo` pra notificações; `DatabaseTransactions` com
`$connectionsToTransact` pras 2 conexões cross-db; `Carbon::setTestNow()` (com
`tearDown` resetando) pra tempo; `Livewire::test(...)` pra componentes.

---

## 7. UX & acabamento

### Nada de "ação que falha em silêncio"
Se um método faz `return;` silencioso quando não pode agir, o usuário clica e a tela
fica muda — parece bug. Ou esconda o botão, ou dê feedback.

**No EscalaMembros:** os botões são escondidos por permissão/estado; onde o retorno
silencioso é defesa em profundidade, a UI já não oferece a ação.

### Feedback em toda escrita
Flash de sucesso (`session()->flash('ok', ...)`), erro tratado (`@error`/`field-error`),
`wire:loading.attr="disabled"` pra evitar duplo-clique, e `wire:confirm` em ações
destrutivas. Avisar quem é afetado por uma ação alheia (ex.: `notifyRemoved()` avisa o
voluntário retirado de uma escala).

### Empty states amigáveis em toda listagem
Toda lista vazia tem uma mensagem que diz o que fazer, não um vazio confuso.

### Acessibilidade não é opcional
`<label for>` ligado ao controle, `aria-label` em botão só-ícone, `role` em alertas.

**No EscalaMembros:** o [`x-select`](resources/views/components/select.blade.php) ganhou
prop `id` + `role="combobox"` pra o `<label for>` funcionar com leitor de tela.

### Ícone consistente, não emoji solto
Um `🔒` cru no meio de botões `bi-*` destoa — padronizado pra `<i class="bi bi-lock">`.

### Conteúdo largo rola DENTRO de um contêiner, não estoura a página
Uma tabela/grade larga deve rolar horizontalmente dentro de um `overflow-x:auto`, e
o `<body>` nunca deve rolar na horizontal. **A pegadinha:** um flex item (ex.: a área
de conteúdo ao lado da sidebar) tem `min-width: auto` por padrão, então **se recusa a
encolher abaixo do conteúdo** — a tabela larga empurra o item e estoura a página
inteira, ignorando o `overflow-x:auto` do filho. Conserta com **`min-width: 0` no
flex item**. Regra de bolso: todo ancestral flex/grid de um bloco que pode ter
conteúdo largo precisa de `min-width: 0`.

**No EscalaMembros:** a matriz de Competências (voluntários × funções) estourava a
tela. A causa não era a tabela — era `.content-area` (flex item) sem `min-width: 0`.
Com o fix, a matriz rola dentro do `.table-wrap`; e demos `width:auto; min-width:100%`
à `.matrix-table` pra ela preencher quando cabe e rolar (colunas compactas) quando
cresce.

### Scrollbar overlay some no mouse — em touch, desenhe a sua própria barra
Rolar com o dedo funciona, mas no **tablet com mouse** a *scrollbar overlay* (que só
aparece enquanto se rola) some — o usuário não vê que há mais conteúdo nem tem o que
arrastar. Primeira tentativa: estilizar `::-webkit-scrollbar` (resolve no desktop). Mas
**o Chrome do Android e o Safari do iPadOS IGNORAM `::-webkit-scrollbar` em dispositivos
touch** — a barra continua overlay. CSS sozinho não resolve.
Solução robusta cross-device: **desenhar a própria barra** — um elemento controlado por
JS (aqui, Alpine) que reflete `scrollLeft/scrollWidth`, fica sempre visível quando há
transbordo e é arrastável via *pointer events* (serve mouse E touch). **Esconda a nativa**
(`scrollbar-width:none` + `::-webkit-scrollbar{display:none}`) pra não ter DUAS barras no
desktop; o scroll nativo (roda/dedo) segue funcionando por baixo.

**No EscalaMembros:** a matriz de Competências usa `.hscroll` — viewport com scroll
nativo escondido + uma `.hscroll-bar` própria (Alpine) sempre visível e arrastável. O
`::-webkit-scrollbar` estilizado ficou só pra melhorar as **outras** tabelas no desktop.

### Matriz 2D não cabe no celular — vire master-detail
Uma grade voluntário×função (ou qualquer tabela larga com muitas colunas) é
inutilizável no toque: rolar de lado pra achar a célula longe do rótulo é sofrível,
e nem a barra de rolagem custom salva. A rolagem foi só um paliativo. O certo é
**trocar o layout no mobile**, não espremer a grade: uma **lista mestre-detalhe** —
toca no item (voluntário) e expande as opções (funções por ministério) com controles
grandes de toque. Mesmo backend, layout diferente por viewport (`@media` +
`display`), reusando a **mesma ação** (`toggle(user, role)`).

**No EscalaMembros:** Competências renderiza a matriz (`.cmp-desktop`) e um acordeão
(`.cmp-mobile`), alternados no breakpoint de 768px. Lição: quando uma tabela é
**bidimensional**, não há CSS que a faça caber num celular — repense a
**interação** (mestre-detalhe/acordeão), não só o overflow.

---

## 8. Processo

### Doc-check antes de todo commit
Antes de commitar, revisar/atualizar as docs pertinentes: arquitetura, guia-didático,
changelog in-app e a Ajuda do app. Doc desatualizada é dívida que ninguém vê.

### Changelog in-app + modo didático
O usuário final tem um changelog dentro do app (linguagem de negócio); o dev tem o
guia-didático (decisões no formato "usei X em vez de Y porque Z, trade-off W"). Cada
mudança relevante alimenta os dois.

### Pente-fino antes de um release importante
Antes de "a versão que vou apresentar", uma revisão ampla (aqui: multi-agente por
frentes — fluxos/permissões, UX, testes) pega footguns que o desenvolvimento no calor
não vê. Foi assim que achamos o gate `ver-escalas` e o bug de fuso.

### Commit sob demanda, história limpa
Commitar só quando pedido; mensagens que explicam o **porquê**, não só o o quê. Quando
duas mudanças estão entrelaçadas no mesmo arquivo, um commit único bem descrito é mais
honesto que um split forçado.

---

## 9. Livewire / PHP específicos

### Escopo **local** vs global tem consequência na autenticação
Um Global Scope roda em **toda** query do model — inclusive no
`retrieveById` que o Laravel usa pra reidratar o usuário logado. Um scope global de
tenant pode fazer o super-admin "sumir" no login.

**No EscalaMembros:** viramos o filtro de congregação num **scope local**
(`User::scopeInCurrentChurch`), aplicado explicitamente nas listagens — nunca no
retrieve do auth. Bug real que custou uma sessão de debug.

### `Gate::before` retorna `hasPermission ?: null` — e o `null` importa
Retornar `false` num `Gate::before` **nega** tudo; retornar `null` é "sem opinião" e
deixa o Gate seguir pro `define`. Por isso gates nomeados (`gerir-vagas`,
`ver-escalas`) convivem com permissões-slug sem colidir.

### Componentes single-file com prefixo `⚡`
Convenção do projeto: um arquivo `.blade.php` com a classe + a view juntas, nome em
dot-notation (`admin.escalas`). Menos arquivos, contexto num lugar só.

### `abort_unless` no método, não só `mount()`
`mount()` roda no load inicial; chamadas de método subsequentes (AJAX) não passam por
ele. Pra proteger cada ação, o `abort_unless` vai **no método** (a persistent
middleware do Livewire reaplica o `can:` da rota, mas o guard explícito é a rede).

### Extraia regra de negócio pra um Service
A lógica de "quem pode ser escalado" mora no [`ScheduleAllocator`](app/Services/ScheduleAllocator.php),
não na tela — testável isolado e reusável (alocação do ADM, troca do voluntário).

### Não misture bloco `@php … @endphp` com `@php(...)` inline no mesmo arquivo
O Blade extrai blocos de PHP cru **antes** de compilar o resto, com um regex do tipo
`@php(.*?)@endphp`. Se o arquivo usa `@php(...)` inline (o padrão deste projeto) e você
adiciona um bloco `@php … @endphp` mais abaixo, o `@endphp` casa com o **primeiro**
`@php(` inline lá de cima e engole tudo no meio como "PHP cru" → a view quebra com um
`ParseError` obscuro (`unexpected token "class"`) apontando pra uma linha que estava certa.
Pior: até um `@endphp` **dentro de um comentário** `{{-- … --}}` dispara isso, porque a
extração roda antes de remover comentários. **Regra:** siga o estilo do arquivo — se ele
usa inline, use `@php(...)` inline (um por statement) e **não** escreva a palavra `@endphp`
em lugar nenhum, nem em comentário. (Visto de verdade ao criar a aba Calendário.)

### Calendário/grade que navega no cliente: desenhe no Alpine — **enquanto o conjunto for pequeno**
Se os dados já estão carregados (as escalas/vagas do voluntário são poucas), montar o mês
e trocar de mês no Alpine é **instantâneo e sem query nova**. O detalhe do dia fica em Blade
com `x-show` — assim os botões continuam sendo Livewire de verdade (confirmar/candidatar
funcionam) e o Alpine só alterna visibilidade. Reaproveita o motor do `<x-datepicker>`.

**A premissa expirou — e a regra vale mais que a conclusão.** Os dois calendários só
carregavam eventos **futuros**; foi isso que manteve o conjunto pequeno. Quando o produto
pediu o **histórico** ("evento passado tem que ficar lá pra consulta"), a conta virou: o
detalhe de cada dia é renderizado no servidor e escondido com `x-show`, então "carregar
tudo" faria o **HTML da página crescer sem limite**, piorando a cada mês. Passamos o mês
para o servidor (`calYear`/`calMonth` + `shiftCalendar`), buscando só o intervalo visível —
custo constante, independente do tamanho do histórico. Trade-off aceito: trocar de mês
passou a custar uma ida ao servidor.

**A lição não é "Alpine foi um erro"** — foi a escolha certa para a premissa da época. É que
*"desenhe no cliente"* **depende de "o conjunto cabe"**, e conjunto que cresce com o tempo
não cabe pra sempre. Ao adotar o padrão, escreva **de qual premissa ele depende**; quando um
requisito novo a derrubar, é a premissa que manda revisar, não o costume.

**Três coisas quebram junto quando a paginação entra (procure por elas):**
1. **Estado vazio que esconde a navegação** — o "nenhum evento" ocultava o calendário
   inteiro; num mês vazio o usuário perdia as setas e ficava **preso**. Vazio agora é aviso
   *dentro* do componente; só o que navegar não resolve (não gerencia ministério algum)
   segue substituindo a tela.
2. **Atalho que varria "tudo"** — o "Próxima vaga aberta" procurava nos marcadores em
   memória. Com um mês só carregado, virou query (`jumpToNextGap`) que acha o evento e
   navega até o mês dele.
3. **Ação exposta em dado que só agora aparece** — com o passado visível, os botões
   de preencher/trocar surgiram em cultos encerrados. O servidor já barrava (`isPast`), mas
   botão que não funciona é falha silenciosa: agora leva selo "Encerrado" e não oferece ação.

### Livewire não pode fazer morph em DOM que o Alpine gerou — dê **um dono só** a cada região
`x-for` cria elementos que **não existem no HTML do servidor**. Enquanto nada dispara
re-render, tudo bem. No instante em que aquela região passa a ser re-renderizada, o morph
compara o HTML do servidor (só o `<template>`) com o DOM vivo (o `<template>` **mais** os N
elementos do Alpine) e reconcilia errado.

**No EscalaMembros:** ao mover o mês do calendário pro servidor, a navegação virou
round-trip — e o grid, que era `x-for` no Alpine, embaralhou: marcador no dia errado
(domingos viraram quartas) e até **numa célula vazia**. Nenhum dos 153 testes pegou, porque
as células nasciam no navegador e o PHP não as enxergava. A correção foi renderizar o grid
em **Blade** e deixar pro Alpine só o estado local de verdade (qual dia está aberto).
Ganho de brinde: o grid virou **testável** — o teste agora afirma "01/08/2026 é sábado, logo
6 células vazias e `cells[6] === '2026-08-01'`", e trava a ida-e-volta de mês.

**Regra:** decidiu que o servidor manda no estado? Então o servidor desenha o HTML também.
Alpine para o que é puramente local (aberto/fechado, item selecionado); Blade para o que o
servidor conhece. Dividir a mesma região entre os dois é bug esperando data marcada.
Se precisar mesmo manter DOM gerado no cliente dentro de um componente que re-renderiza,
isole com `wire:ignore`.

**Corolário — valor cru no `x-data` congela.** `x-data` é avaliado **uma vez**; ler
`{{ now()->month }}` ali deixa o valor preso ao primeiro render. Se o dado vem do servidor,
ou renderize em Blade (preferido) ou use `$wire.entangle('prop')` (padrão do
`<x-select>`/`<x-datepicker>`).

**Cuidado ao testar HTML de componente com abas:** o `assertSeeHtml` do grid falhou até eu
setar `tab`/`view` — a região só é renderizada na aba ativa. Teste que só olha `viewData`
passa mesmo com a aba errada e dá falsa sensação de cobertura.
