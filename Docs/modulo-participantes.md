# Módulo 2 — Participantes de Evento (check-in + credencial com QR)

> **Status em 12/09/2026 — etapa 1 EM PRODUÇÃO**, testada ponta a ponta.
> No ar: check-in (por nome, código, e-mail, telefone, CPF ou QR), walk-in,
> desfazer, dias do evento, lista de presença imprimível e inscrição à mão.
> Faltam a **importação** (etapa 2) e **QR + credencial + e-mail** (etapa 3).
>
> Documento de decisões, no formato de `arquitetura.md`: cada escolha vem com o
> custo e a alternativa recusada.

## Por que este módulo

O App Eventos tem hoje **um** módulo em produção — a Livraria (vendas), em
`ipccg.org.br/eventos`. O projeto sempre previu **dois**; o segundo é a
**gestão de participantes inscritos no evento**.

O prazo manda no escopo: o evento é **25 e 26 de setembro** e hoje é
**10/09/2026** — faltam **14 dias**. Os cadastros **já foram coletados num
Google Forms**, então não há tempo nem necessidade de construir o formulário de
inscrição antes. A entrega se divide:

- **Agora:** importar a planilha e ter **check-in pelo app**, com **credencial +
  QR por e-mail**.
- **Depois:** o formulário no app, com **perguntas dinâmicas**, para substituir
  o Google Forms.

**O participante não é usuário da plataforma.** Não faz login, não tem perfil,
não aparece em Pessoas. Ele é inscrito num evento — e essa distinção governa a
modelagem inteira.

### Decisões tomadas com o usuário

| Questão | Decisão |
|---|---|
| Volume | até 100 inscritos |
| Check-in na portaria | **QR principal, busca por nome/código sempre ao lado** |
| Credencial | **página HTML imprimível**, não PDF gerado no servidor |
| Biblioteca de QR | `chillerlan/php-qrcode` — validar na etapa 0 |

**Página imprimível, não PDF:** o projeto não tem lib de PDF, e seria a segunda
dependência nova. Uma página com `@media print` o navegador salva como PDF em um
toque, e a credencial fica sempre atual. Leva nome, código, QR e os dados do
evento. Nenhum dos dois apps da família tem `@media print` hoje — a credencial
cria esse padrão em `public/assets/app.css`, com os tokens que já existem.

**`chillerlan` e não `bacon/bacon-qr-code`:** o bacon só renderiza SVG, EPS e
**Imagick**, e `imagick` está ausente nos dois ambientes. O chillerlan tem
`QRGdImage` nativo (usa a `gd` já obrigatória por DEPLOY.md) e `QRMarkupSVG`.
Uma dependência cobre e-mail (PNG) e impressão (SVG).

> **Nomenclatura a confirmar:** proponho a tabela `par_people` em vez de
> `par_master_customers` — "customer" carrega semântica de compra que aqui não
> existe (quem compra é a livraria), e "master" descreve a função, não a
> entidade. O conceito é exatamente o que você chamou de `master_customer`, e
> isso vai no COMMENT da tabela e no dicionário de dados para o vocabulário
> continuar navegável. Trivial de mudar se preferir o nome original.

---

## ⚠ Bug pré-existente que a portaria transforma em falha crítica

> ✅ **Corrigido e publicado em 10/09/2026**, antes do resto do módulo — o bug
> existia por conta própria e derrubava a Venda. O registro do problema fica
> aqui porque é ele que explica por que o middleware tem a forma que tem.

Confirmado no código. **Já afetava a Venda**, e na portaria com fila seria
fatal.

`church_id` é gravado na sessão em **um único lugar**: o login
(`components/auth/⚡login.blade.php:99`). O `SetChurchContext` só lê `church_tz`,
e o `EnsureMembershipAtivo` **passa adiante** quando não há `church_id`.

Com `SESSION_LIFETIME=120` e `Auth::login($user, remember: true)`: a sessão
expira em 2 h → o cookie *remember* re-autentica **numa sessão vazia** → o
`ChurchScope` filtra `WHERE church_id IS NULL` → zero linhas em tudo →
`Event::atual()` devolve `null` e a tela diz "nenhum evento", **sem erro**.

O voluntário que logou às 17h e abre a portaria às 19h30 encontra tela muda, e
não sabe a senha para relogar.

**Correção:** `SetChurchContext` reidrata — autenticado e sem `church_id`, busca
o `Membership` ativo; **um** → grava `church_id`/`church_tz`; **mais de um** →
manda escolher. Nunca tela vazia. Mais `SESSION_LIFETIME` folgado no evento.

---

## Modelagem

Prefixo **`par_`**, migrations na faixa **04xxxx**, e
`database/sql/05-modulo-participantes.sql`. A regra dura vale: **módulo nunca
acrescenta coluna a `events`** — só relações no model, e um método `agora()`.

O eixo é a distinção que o projeto já usa entre `Product` (permanente,
`church_id` + `BelongsToChurch`) e `Copy` (do evento, `event_id`, isolado por
transitividade):

```
events (intocado)
  ├─1:1─ par_event_settings (event_id PK) ──form_id──▶ par_forms ──▶ par_questions
  ├─1:N─ par_event_days
  └─1:N─ par_registrations ──person_id──▶ par_people   ← o "master_customer"
              └─1:N─ par_checkins ──▶ par_event_days
```

`par_people` e `par_forms` usam `BelongsToChurch`; o resto leva `event_id`.
Todo model declara `$connection = 'mysql'`.

### Snapshot **e** referência — com critério explícito

`par_registrations.person_id` é FK real (responde "todos os eventos de fulana"),
**e** a inscrição carrega snapshot de `nome`, `email`, `telefone`, `cpf` e
`respostas`. O critério generaliza o precedente de
`liv_shipment_items.custo_unitario`:

> **Snapshot o que pode mudar E foi usado para agir naquele evento. Referencia o
> que não muda, ou o que preciso alcançar hoje.**

`data_nascimento` fica **só** em `par_people` — não muda; idade no evento é
derivada. E a consequência operacional que resolve três problemas de uma vez:

> **Todo envio usa o snapshot (`par_registrations.email`). `par_people.email`
> existe para identificar e pré-preencher, não para enviar.**

Isso faz funcionar: o **casal que compartilha um e-mail** (o segundo cônjuge fica
com `par_people.email` NULL e ainda recebe o QR), a mudança de e-mail sem
reescrever histórico, e a auditoria de "para onde mandamos".

O fluxo é de mão única: formulário → inscrição (congela) **e** → `par_people`
(atualiza o hoje). O snapshot **nunca** é recarregado.

### Dedup por e-mail OU CPF

Duas UNIQUEs compostas simples em `par_people`:

```sql
UNIQUE KEY par_people_church_id_email_unique (church_id, email)
UNIQUE KEY par_people_church_id_cpf_unique   (church_id, cpf)
```

**Sem** o truque da coluna gerada `IFNULL(...)` que a livraria usa em
`variant_key`. Ali "NULL não colide" era o bug; **aqui é a semântica desejada** —
duas pessoas sem CPF *não são* a mesma pessoa. "Sem CPF" não é valor, é ausência
de chave, e `IFNULL(cpf,'')` impediria a segunda pessoa sem CPF de existir. Vale
escrever isso no COMMENT, porque é a mesma propriedade do MySQL usada com
intenção oposta no mesmo schema.

O índice só serve se o dado for normalizado: `cpf` CHAR(11) só dígitos em
mutator (string vazia → NULL), `email` lower+trim (a collation
`utf8mb4_unicode_ci` já é case-insensitive, então não precisa coluna gerada), e
**`App\Rules\Cpf` com dígito verificador é obrigatório** — CPF errado não gera
erro de validação, gera *pessoa errada*, e pode colidir com o CPF real de outra.

CHECK constraint foi recusada: o `comparar-schema.py` **não compara CHECK**, e
criaria divergência silenciosa entre as duas fontes de verdade.

**`PersonResolver` é o único caminho de escrita em `par_people`.** Ordem: CPF
casa → é a pessoa; senão e-mail casa → confirma se o primeiro nome bate; senão
**busca a chave histórica no snapshot** (`par_registrations.email`) — é isso que
faz o e-mail antigo continuar encontrando a pessoa, **sem tabela de aliases**;
senão cria. Nunca casa por nome (homônimo é regra) nem por telefone (família
compartilha).

**Conflito** (CPF aponta pessoa A, e-mail aponta pessoa B): vincula pelo CPF
(chave forte), grava o e-mail digitado só no snapshot, e marca
`conflito_identidade = 1` para decisão humana. **Nunca funde automaticamente.**

### Dias — tabela própria, semeada do intervalo

`par_event_days` com `UNIQUE(event_id, data)`, `nome` e `ativo`. Quatro razões, a
terceira decisiva: `events` não pode ganhar coluna; dias não consecutivos são
caso real; **o check-in aponta o dia por FK** — e pelo critério do projeto ("se
precisa ser referenciável ou ter contagem própria, é entidade"), o **dia divide a
presença** como a variação divide o estoque; e dia cancelado precisa parar de
aceitar check-in sem apagar a presença registrada.

**Derivar é o default de criação, não o modelo:** ao habilitar o módulo, semeia
uma linha por data de `inicio..fim` (ou uma, se `fim` for NULL), e o coordenador
apaga, acrescenta e nomeia. Ninguém digita dias à mão e o modelo fica honesto.

### Check-in — trava no banco e horário certo

```sql
registration_ativa AS (IF(cancelado_em IS NULL, registration_id, NULL)) VIRTUAL
UNIQUE (event_day_id, registration_ativa)
```

Terceiro uso do idioma `copy_ativo`: um check-in **ativo** por (dia, inscrição),
mesmo com dois leitores no mesmo segundo; o cancelado vira NULL e permite refazer
com rastro do erro.

**VIRTUAL aqui e STORED lá, e isso não é preferência:** a base é
`registration_id`, cuja FK **cascateia**, e o InnoDB recusa STORED sobre base com
CASCADE (ERROR 1215 — o mesmo que aconteceu com `capa_unica`). Em
`par_registrations`, `pessoa_ativa` deriva de `person_id` (FK **RESTRICT**), então
pode ser **STORED**.

**Horário:** `registrado_em` é **DATETIME** (como `liv_sales.vendida_em`), nunca
TIMESTAMP, e **nunca `DEFAULT CURRENT_TIMESTAMP`** — que resolveria no `time_zone`
da sessão MySQL, reintroduzindo o bug de fuso. O valor vem do PHP via um método
novo `Event::agora()` → `Church::agora($church_id)`, espelhando
`CartHold::agoraDo()` (`app/Models/Livraria/CartHold.php:66`) com memo por evento.
`SetChurchContext` só cobre a web; **import por CLI e worker ficam em UTC**, e 3 h
de erro corromperiam toda a presença sem ninguém notar.

### Dois identificadores

| | `codigo` | `token` |
|---|---|---|
| forma | `INS0042` | 32 caracteres aleatórios |
| serve para | ler, ditar, digitar, imprimir | ser a chave do QR |
| é segredo? | **não** | sim |

O docblock do `CodeGenerator` já avisa: sequencial legível é ótimo para humano e
péssimo como segredo — se o QR levasse o `codigo`, qualquer um faria check-in
como outra pessoa digitando `INS0043`. **Um token por inscrição, não por dia**
(quatro QRs por e-mail é hostil e multiplica o erro de apresentar o errado).

**No QR vai só uma URL curta com o token** (`/p/{token}`, ~47 caracteres → QR
versão 2-3, módulos grandes, escaneia em celular ruim). Isso permite usar a
**câmera nativa** no MVP, removendo a maior fonte de risco técnico do prazo. E a
anti-falsificação sai de graça: a rota fica **dentro do `auth`, com
`can:participantes.checkin`** — o token é chave de busca, a autorização vem da
sessão do operador; QR forjado só aponta para inscrição existente e não cria
nada. Nenhum dado pessoal viaja no QR.

> A credencial do participante é **outra rota**, pública: `/credencial/{token}`.
> Misturar as duas deixaria o endpoint de check-in a um `auth()->check()` de
> distância.

### Perguntas dinâmicas (estrutura criada já, telas na fase 2)

`par_forms` + `par_questions` espelham `liv_categories` + `liv_category_fields`,
e `CategoryField::regras()` porta verbatim para `App\Support\CampoDinamico`. Três
diferenças, porque aqui a resposta é **dado histórico de uma pessoa**:

1. **`escopo` ENUM('inscricao','pessoa')** — decide onde o valor mora.
   `inscricao` (qual oficina) fica só no snapshot; `pessoa` (restrição alimentar)
   vai para `par_people.perfil` **e** congelado no snapshot.
   > **O perfil é o valor de hoje. A resposta da inscrição é o valor daquele dia.**
   Não é redundância: a lista da cozinha de 2026 tem que continuar dizendo
   "vegetariana" depois que ela voltar a comer carne em 2027.
2. **`arquivada_em` em vez de DELETE** — na livraria, apagar o campo deixa o
   valor órfão e **invisível** (toda leitura é dirigida pela definição). Para
   catálogo é inofensivo; para alergia de uma pessoa é perda de informação.
3. **`chave` imutável, `rotulo` editável** — fecha a lacuna "não dá para editar
   campo já criado" sem nunca mover a chave do JSON.

**A convenção de `chave` é a ponte entre as fases:** a importação escreve em
`respostas` usando `Str::slug('_')` do cabeçalho do CSV, e a pergunta criada na
fase 2 gera a mesma chave — **encontrando o dado já lá, sem migração**. Se a
fase 1 inventar chaves próprias (`col_3`), a fase 2 precisa de mapeamento manual.

**E um alerta que vale confirmar com você:** *"qual oficina você escolhe"* com
vaga limitada **não é pergunta de `selecao`, é entidade** — precisa de contagem
própria ("a de louvor esgotou?") e de ser referenciada ("quem está na sala 3").
É o mesmo erro de guardar "tamanho M" como texto. As tabelas ficam para a fase 2
**exceto se o evento de 25/26 tiver oficina com vaga limitada**, caso em que
entram agora.

---

## Etapas

### Etapa 0 — Descoberta (antes de qualquer código, ~3h)

Cada item pode invalidar o resto:

1. **Testar o SMTP de verdade** em produção — nunca foi exercitado. Se falhar, a
   etapa 3 muda de forma (código por WhatsApp)
2. Descobrir o **limite horário de e-mail** do cPanel
3. Pegar o **CSV real do Forms** e olhar as colunas verdadeiras
4. Validar `chillerlan/php-qrcode` com GD e **subir o `vendor.zip`** — é o único
   passo de deploy que `git push` não desfaz, então vai primeiro
5. Confirmar se há **oficina com vaga limitada** no evento

### Etapa 1 — Check-in funciona (o irredutível)

Migrations + `05-modulo-participantes.sql` + `comparar-schema.py` verde · models
+ `App\Support\CampoDinamico` + `App\Rules\Cpf` + `Event::agora()` ·
**`PersonResolver` com teste, antes de qualquer tela** — é a única peça que pode
corromper dado silenciosamente · `CheckinService` (lock + UNIQUE) ·
`RegistrationService` · **a correção do `SetChurchContext`** · permissões + gate
composto + perfil "Portaria" · telas de Check-in, Inscritos, Dias e **lista de
presença impressa** · menu · testes.

> **Aceite: o evento está seguro mesmo se nada mais for entregue.** Os inscritos
> entram pela tela ou à mão, e o check-in acontece por busca de nome. Publicar e
> parar de arriscar.

### Etapa 2 — Importação do Google Forms

`par_imports` (com `mapeamento` e `relatorio` JSON) + `ImportService` + tela de
mapeamento em dois passos + textarea para colar o CSV como plano B.

Normalização é onde a qualidade se ganha: delimitador detectado (`,` vs `;`), BOM
e CP1252 tratados, nome em Title Case, e-mail inválido marcado **sem descartar a
pessoa**, CPF validado. Casamento por CPF → e-mail → cria, **preenchendo só
campos vazios** do master.

> **Aceite: os inscritos entram em 10 minutos, e reimportar não duplica** — o
> UNIQUE em `pessoa_ativa` faz o banco recusar a segunda inscrição ativa, e o
> importador conta como "já inscrito" em vez de derrubar o lote. É a propriedade
> mais valiosa aqui: reimportar por engano é o erro mais provável de todos.

### Etapa 3 — Credencial, QR e e-mail (terminar em D-7)

`par_event_settings` + `QrService` + credencial imprimível + `CredencialMail` +
tela de envio com retomada.

**Envio em lote sem fila nem cron** usa o padrão de `liv_cart_holds`: o estado
mora na própria linha e a reserva **expira no filtro**
(`email_reservado_em < agora-5min`), então lote travado por processo morto se cura
sozinho, sem rotina de limpeza. O loop é `wire:poll` — **cada poll é uma
requisição nova, com timeout novo**, e é isso que torna o lote viável sem worker.
Guarda de 20 s por requisição; fechar o navegador e clicar de novo continua de
onde parou.

O e-mail leva **três caminhos redundantes**: o QR inline por CID (não
`<img src=api-externa>`, que vazaria o token e morreria sem rede), o **código em
corpo grande** com "se o QR não ler, mostre este código", e o link da credencial.

> Se atrasar, **corte esta etapa antes de arriscar as anteriores** — o check-in
> por nome já funciona.

### Etapa 4 — Fase 2, pós-evento

Telas de `par_forms`/`par_questions` + inscrição pública. **Zero migração, zero
ALTER**: a estrutura entra na etapa 1 porque custa ~40 linhas de DDL, e forçar a
decisão de `escopo` agora é o que garante que os dados caiam no lugar certo.

---

## Riscos no dia do evento

| risco | mitigação |
|---|---|
| **Sessão expira, tela muda** | a correção do `SetChurchContext` — o maior risco, e é bug existente |
| **Internet cai na portaria** | não engenhar contra: **papel**. Lista impressa na véspera + lançamento retroativo |
| **Dois leitores simultâneos** | UNIQUE em `registration_ativa` + `lockForUpdate`. O segundo operador vê "já entrou às 19h12, por Maria" |
| **QR não escaneia** | escada de fallback: token → código → e-mail → telefone/CPF → nome (LIKE accent-insensitive) |
| **Não recebeu o e-mail** (5-10%) | e-mail inválido marcado **na importação** → você sabe quem é antes do evento |
| **Não inscrito aparece** | walk-in de 3 campos. Sem isso a portaria anota em papel e o dado nunca chega |
| **Dia errado** | dia em destaque + pill "HOJE" **vermelha** quando divergente |
| **Horário errado em 3 h** | `Event::agora()`, zero `now()` no módulo, com teste dedicado |
| **`vendor.zip` corrompido** | etapa 0, não D-12 |
| **Deploy no dia** | **congelamento em 23/09**; ensaio geral em **21/09**, em produção |

A tela de check-in segue as restrições que moldaram a Venda — uma mão, em pé,
fila esperando: caixa única com `#[Url(except:'')]` +
`wire:model.live.debounce.250ms` + `autofocus` + `enterkeyhint="search"` (molde
em `⚡venda.blade.php:34,371`) e **2 toques por pessoa**.

### Três gotchas que o schema não resolve

- **`ChurchScope` sem sessão** atinge o formulário público, o import por CLI e o
  worker: vira `IS NULL` (zero linhas) e o `creating` deixa `church_id` nulo.
  Nesses três caminhos, `withoutGlobalScopes()` + `church_id` explícito.
- **Vazamento cross-church:** nada no banco impede ligar pessoa da igreja A a
  evento da igreja B. Mitigação no `RegistrationService` + teste. (FK composta
  resolveria no banco, mas exigiria índice novo em `events` — alterar o núcleo
  por um módulo, o que a regra proíbe.)
- **Gate composto obrigatório:** o voluntário tem só `participantes.checkin` e
  tomaria **403 na própria tela** se a rota exigisse `participantes.ver`. É
  literalmente o footgun que o `ver-livraria` já documenta.

---

## Verificação

**Schema:** `python3 scripts/comparar-schema.py` sai 0 — critério de aceite de
cada etapa, rodado **antes** de escrever aplicação. A expressão das colunas
geradas precisa ser copiada de `SHOW CREATE TABLE` de volta para o `.sql`, com os
backticks como o MySQL devolveu, senão o comparador acusa divergência de texto.

**Testes** em `tests/Feature/Participantes/`, `DatabaseTransactions` +
`$connectionsToTransact = ['mysql']`, MySQL real, `session(['church_id'=>1])`
antes de qualquer model, `uniqid()` em campo UNIQUE. Cada um trava uma decisão:

- dedup por CPF com e-mail diferente; por e-mail com CPF ausente
- **casal com e-mail compartilhado não funde**; duas pessoas sem CPF coexistem
- conflito e-mail×CPF marca a flag e **não funde**
- segundo check-in no mesmo dia é recusado **pelo banco**; cancelado permite novo
- check-in disparado **por comando de console** com `Carbon::setTestNow()` em
  fuso diferente grava a hora-de-parede certa (um teste todo em UTC nunca pega)
- inscrição cross-church é recusada
- importação: `;`, CP1252, CAIXA ALTA, CPF inválido, linha sem nome, **reimportar
  não duplica**
- envio: `Mail::fake()`, para no limite, retoma sem reenviar
- sessão sem `church_id` é reidratada em vez de tela vazia
- `TelasRenderizamTest::telas()` ganha as rotas novas

**Ponta a ponta, em produção, no ensaio de 21/09:** importar a planilha real →
enviar e-mail para 3 endereços de verdade → escanear com a câmera nativa →
digitar o código → buscar por nome → walk-in → desfazer → **imprimir a lista em
papel**. No wifi/4G do próprio local, se possível.
