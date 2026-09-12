-- =====================================================================
--  App EVENTOS — IPCCG
--  Arquivo 05 de 05: MÓDULO PARTICIPANTES  (prefixo par_)
--  Schema: ipccgorg_Eventos          Rodar DEPOIS do 01-core.sql
-- ---------------------------------------------------------------------
--  Gestão de quem se inscreve num evento: cadastro, credencial com QR e
--  CHECK-IN por dia. Pendura em `events`, como a livraria — e, como ela,
--  não acrescenta coluna nenhuma ao núcleo.
--
--  ── IDENTIDADE DURADOURA × INSCRIÇÃO PONTUAL ──────────────────────
--  `par_people` é a pessoa ENTRE eventos (o "master_customer" do escopo);
--  `par_registrations` é a inscrição NAQUELE evento. A separação é o eixo
--  do módulo inteiro: é ela que faz o dado voltar preenchido no ano
--  seguinte sem que corrigir um telefone hoje reescreva a lista de
--  presença de um evento encerrado.
--
--  TEMPO: timestamp de negócio é DATETIME, nunca TIMESTAMP, e nunca com
--  DEFAULT CURRENT_TIMESTAMP. O valor vem do PHP, no fuso da CONGREGAÇÃO.
-- =====================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;


-- ---------------------------------------------------------------------
-- par_people — a pessoa ENTRE eventos (o "master_customer" do escopo)
--
-- Cadastro PERMANENTE da congregação, como liv_suppliers: é o que faz o
-- dado voltar preenchido quando a mesma pessoa se inscreve no ano que vem.
-- O participante NÃO é `users`: não faz login, não tem senha nem perfil.
--
-- `email` e `cpf` são CHAVES DE IDENTIDADE, não canais de contato — quem
-- recebe o QR é o e-mail gravado na INSCRIÇÃO. É essa separação que deixa
-- um casal usar um e-mail só: o segundo cônjuge fica sem e-mail aqui e
-- ainda recebe a credencial dele.
--
-- ⚠ NULL EM UNIQUE, AQUI DE PROPÓSITO. Em liv_shipment_items o NULL era o
-- problema, resolvido com a coluna gerada variant_key = IFNULL(...,0).
-- Aqui é a semântica desejada: duas pessoas sem CPF NÃO são a mesma
-- pessoa — "não informou" é ausência de chave, não um valor. IFNULL aqui
-- impediria a segunda pessoa sem CPF de existir.
--
-- O índice só vale se o dado for normalizado: `cpf` guarda 11 dígitos e
-- nada mais, `email` vai em minúsculas. Quem normaliza é o model, e o CPF
-- ainda passa por dígito verificador — CPF errado não gera erro de
-- validação, gera PESSOA errada, e pode colidir com o CPF real de outra.
-- ---------------------------------------------------------------------
CREATE TABLE `par_people` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `church_id` BIGINT UNSIGNED NOT NULL,
  `nome` VARCHAR(150) NOT NULL,
  `email` VARCHAR(191) DEFAULT NULL,
  `cpf` CHAR(11) DEFAULT NULL,
  `telefone` VARCHAR(20) DEFAULT NULL,
  `data_nascimento` DATE DEFAULT NULL,
  `perfil` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`perfil`)),
  `observacao` VARCHAR(255) DEFAULT NULL,
  `ativo` tinyint(1) NOT NULL DEFAULT 1,
  `fundida_em_id` BIGINT UNSIGNED DEFAULT NULL,
  `fundida_em` DATETIME DEFAULT NULL,
  `created_at` TIMESTAMP NULL DEFAULT NULL,
  `updated_at` TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `par_people_church_id_email_unique` (`church_id`,`email`),
  UNIQUE KEY `par_people_church_id_cpf_unique` (`church_id`,`cpf`),
  KEY `par_people_church_id_nome_index` (`church_id`,`nome`),
  KEY `par_people_fundida_em_id_foreign` (`fundida_em_id`),
  KEY `par_people_church_id_index` (`church_id`),
  CONSTRAINT `par_people_fundida_em_id_foreign` FOREIGN KEY (`fundida_em_id`) REFERENCES `par_people` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ---------------------------------------------------------------------
-- par_forms — o formulário de inscrição (FASE 2; estrutura criada já)
-- Permanente e reusável entre eventos: "Formulário do Retiro" é montado
-- uma vez e reaproveitado todo ano. É essa a dor real do Google Forms.
-- Espelha liv_categories: o "tipo" é quem possui a definição dos campos.
-- ---------------------------------------------------------------------
CREATE TABLE `par_forms` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `church_id` BIGINT UNSIGNED NOT NULL,
  `nome` VARCHAR(120) NOT NULL,
  `descricao` VARCHAR(255) DEFAULT NULL,
  `ativo` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` TIMESTAMP NULL DEFAULT NULL,
  `updated_at` TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `par_forms_church_id_nome_unique` (`church_id`,`nome`),
  KEY `par_forms_church_id_index` (`church_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ---------------------------------------------------------------------
-- par_questions — as perguntas do formulário (FASE 2; estrutura já)
--
-- Espelha liv_category_fields, com quatro diferenças que existem porque
-- aqui a resposta é DADO HISTÓRICO DE UMA PESSOA, não descrição de item:
--
--  1. `escopo` decide ONDE o valor mora. inscricao (qual oficina) fica só
--     no snapshot; pessoa (restrição alimentar) vai também para
--     par_people.perfil e pré-preenche o próximo evento.
--       → O perfil é o valor de HOJE. A resposta da inscrição é o valor
--         DAQUELE DIA: a lista da cozinha de 2026 tem de continuar dizendo
--         "vegetariana" depois que ela voltar a comer carne em 2027.
--  2. `arquivada_em` em vez de DELETE. Na livraria, apagar o campo deixa o
--     valor órfão no JSON — guardado, mas INVISÍVEL, porque toda leitura é
--     dirigida pela definição. Para catálogo é inofensivo; para a alergia
--     de uma pessoa é perda de informação.
--  3. `chave` é IMUTÁVEL; `rotulo` é editável. Corrigir o texto da pergunta
--     nunca move a chave do JSON — a lacuna que o padrão da livraria tem.
--  4. `ordem` SMALLINT com passo de 10: reordenar insere entre dois sem
--     reescrever a lista. TINYINT esgotaria em 25 perguntas.
--
-- ⚠ O ENUM mantém os 7 tipos de liv_category_fields NA MESMA ORDEM (as
-- regras de validação portam verbatim) e acrescenta 4 no FIM. Tipo novo
-- entra sempre no fim: no meio, o ALTER reescreve a tabela inteira.
--
-- ⚠ `unica` é validado no Service, NÃO no banco: unicidade sobre chave
-- JSON arbitrária não cabe num índice.
-- ---------------------------------------------------------------------
CREATE TABLE `par_questions` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `form_id` BIGINT UNSIGNED NOT NULL,
  `chave` VARCHAR(40) NOT NULL,
  `rotulo` VARCHAR(120) NOT NULL,
  `ajuda` VARCHAR(255) DEFAULT NULL,
  `tipo` ENUM('texto','texto_longo','inteiro','decimal','data','selecao','booleano','multipla_escolha','email','telefone','cpf') NOT NULL DEFAULT 'texto',
  `opcoes` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`opcoes`)),
  `obrigatorio` tinyint(1) NOT NULL DEFAULT 0,
  `escopo` ENUM('inscricao','pessoa') NOT NULL DEFAULT 'inscricao',
  `mostrar_na_lista` tinyint(1) NOT NULL DEFAULT 0,
  `unica` tinyint(1) NOT NULL DEFAULT 0,
  `ordem` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  `arquivada_em` DATETIME DEFAULT NULL,
  `created_at` TIMESTAMP NULL DEFAULT NULL,
  `updated_at` TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `par_questions_form_id_chave_unique` (`form_id`,`chave`),
  KEY `par_questions_form_id_ordem_index` (`form_id`,`ordem`),
  CONSTRAINT `par_questions_form_id_foreign` FOREIGN KEY (`form_id`) REFERENCES `par_forms` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ---------------------------------------------------------------------
-- par_event_settings — o que o módulo acrescenta a um evento
-- event_id como PK (1:1), espelho de liv_event_settings. Fica FORA de
-- `events` de propósito: núcleo não recebe coluna de módulo — se cada um
-- pudesse pôr a sua, `events` viraria depósito no segundo módulo.
-- `slug_publico` é a URL do formulário aberto (fase 2): UNIQUE global
-- porque a URL tem de ser inequívoca, e NULL não colide, então evento sem
-- formulário público simplesmente não tem slug.
-- ---------------------------------------------------------------------
CREATE TABLE `par_event_settings` (
  `event_id` BIGINT UNSIGNED NOT NULL,
  `form_id` BIGINT UNSIGNED DEFAULT NULL,
  `slug_publico` VARCHAR(60) DEFAULT NULL,
  `prefixo_codigo` VARCHAR(4) NOT NULL DEFAULT 'INS',
  `vagas` SMALLINT UNSIGNED DEFAULT NULL,
  `inscricoes_de` DATETIME DEFAULT NULL,
  `inscricoes_ate` DATETIME DEFAULT NULL,
  `texto_confirmacao` TEXT DEFAULT NULL,
  `observacao` VARCHAR(255) DEFAULT NULL,
  `created_at` TIMESTAMP NULL DEFAULT NULL,
  `updated_at` TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (`event_id`),
  UNIQUE KEY `par_event_settings_slug_publico_unique` (`slug_publico`),
  KEY `par_event_settings_form_id_foreign` (`form_id`),
  CONSTRAINT `par_event_settings_event_id_foreign` FOREIGN KEY (`event_id`) REFERENCES `events` (`id`) ON DELETE CASCADE,
  CONSTRAINT `par_event_settings_form_id_foreign` FOREIGN KEY (`form_id`) REFERENCES `par_forms` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ---------------------------------------------------------------------
-- par_event_days — os DIAS do evento (o núcleo só sabe de inicio e fim)
--
-- Tabela própria, e não intervalo derivado, por quatro razões — a terceira
-- decide sozinha:
--  1. `events` não recebe coluna de módulo, e nomear o dia é requisito.
--  2. Dias NÃO CONSECUTIVOS são caso real (os dois sábados do mês).
--  3. O check-in aponta o dia por FK. Pelo critério do projeto — "se
--     precisa ser referenciável ou ter contagem própria, é entidade" — o
--     dia é entidade. É o análogo de "variação divide estoque":
--     O DIA DIVIDE A PRESENÇA, e cada dia esgota sozinho.
--  4. Dia cancelado precisa parar de aceitar check-in sem apagar a
--     presença já registrada — daí `ativo`.
--
-- Derivar é o DEFAULT DE CRIAÇÃO, não o modelo: ao preparar o evento
-- semeia-se uma linha por data de inicio..fim, e o coordenador então
-- apaga, acrescenta e nomeia.
--
-- Duas sessões no mesmo dia (manhã/noite) não cabem hoje, de propósito: a
-- expansão é indolor — ADD COLUMN periodo e trocar a UNIQUE.
-- ---------------------------------------------------------------------
CREATE TABLE `par_event_days` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `event_id` BIGINT UNSIGNED NOT NULL,
  `data` DATE NOT NULL,
  `nome` VARCHAR(60) DEFAULT NULL,
  `ativo` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `par_event_days_event_id_data_unique` (`event_id`,`data`),
  CONSTRAINT `par_event_days_event_id_foreign` FOREIGN KEY (`event_id`) REFERENCES `events` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ---------------------------------------------------------------------
-- par_registrations — a INSCRIÇÃO NO EVENTO
-- O participante não é `users`. Ele é esta linha.
--
-- SNAPSHOT: nome, email, telefone e cpf são COPIADOS no ato da inscrição e
-- NUNCA recarregados de par_people. Mesma razão de
-- liv_shipment_items.custo_unitario: corrigir o telefone hoje não pode
-- mexer na lista de presença de um evento encerrado.
--   Regra: snapshot o que pode MUDAR e foi usado para AGIR naquele evento;
--   referencia o que não muda (data_nascimento) ou o que preciso HOJE.
--
-- O snapshot ganha um segundo salário: é a CHAVE HISTÓRICA da dedup.
-- E-mail antigo continua encontrando a pessoa porque toda inscrição guarda
-- o e-mail usado naquele dia — sem tabela de aliases. Daí os índices em
-- `email` e `cpf`.
--
-- ⚠ TODO ENVIO usa este `email`, não o de par_people. É o que deixa o
-- casal com um e-mail só funcionar, e o que responde "mandamos para onde?"
--
-- `codigo` × `token`: codigo é humano e SEQUENCIAL (INS0042), para ditar e
-- digitar; token é aleatório e é o que vai no QR. Se o QR levasse o
-- código, qualquer um faria check-in como outra pessoa digitando INS0043.
-- UM token por inscrição, não por dia: quatro QRs por e-mail é hostil.
--
-- ⚠ `pessoa_ativa` é STORED, e PODE ser: as bases são `status` (sem FK) e
-- `person_id` (FK RESTRICT) — o InnoDB só recusa STORED sobre base com
-- CASCADE. `event_id` cascateia mas NÃO entra na expressão; se um dia
-- entrar, a coluna tem de virar VIRTUAL.
-- Efeito: no máximo UMA inscrição ativa por pessoa por evento — é o que
-- torna reimportar a planilha idempotente, e reimportar por engano é o
-- erro operacional mais provável de todos.
--
-- `respostas` nasce vazia e é da fase 2, mas a IMPORTAÇÃO já escreve nela
-- com a MESMA convenção de chave (slug com _) que par_questions vai gerar:
-- uma pergunta "Restrição alimentar" criada depois vira
-- restricao_alimentar e ENCONTRA o dado importado hoje. É a ponte que
-- dispensa migração de dados entre as fases.
-- ---------------------------------------------------------------------
CREATE TABLE `par_registrations` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `event_id` BIGINT UNSIGNED NOT NULL,
  `person_id` BIGINT UNSIGNED NOT NULL,
  `codigo` VARCHAR(20) NOT NULL,
  `token` CHAR(32) NOT NULL,
  `status` ENUM('confirmada','lista_espera','cancelada') NOT NULL DEFAULT 'confirmada',
  `origem` ENUM('formulario','manual','importacao') NOT NULL DEFAULT 'formulario',
  `nome` VARCHAR(150) NOT NULL,
  `email` VARCHAR(191) DEFAULT NULL,
  `telefone` VARCHAR(20) DEFAULT NULL,
  `cpf` CHAR(11) DEFAULT NULL,
  `respostas` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`respostas`)),
  `conflito_identidade` tinyint(1) NOT NULL DEFAULT 0,
  `email_valido` tinyint(1) NOT NULL DEFAULT 1,
  `inscrita_em` DATETIME NOT NULL,
  `qr_enviado_em` DATETIME DEFAULT NULL,
  `qr_envios` TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `qr_reservado_em` DATETIME DEFAULT NULL,
  `qr_erro` VARCHAR(255) DEFAULT NULL,
  `registrada_por` BIGINT UNSIGNED DEFAULT NULL,
  `cancelada_em` DATETIME DEFAULT NULL,
  `cancelada_por` BIGINT UNSIGNED DEFAULT NULL,
  `observacao` VARCHAR(255) DEFAULT NULL,
  `created_at` TIMESTAMP NULL DEFAULT NULL,
  `updated_at` TIMESTAMP NULL DEFAULT NULL,
  `pessoa_ativa` BIGINT UNSIGNED GENERATED ALWAYS AS (IF(`status` <> 'cancelada',`person_id`,NULL)) STORED,
  PRIMARY KEY (`id`),
  UNIQUE KEY `par_registrations_event_id_codigo_unique` (`event_id`,`codigo`),
  UNIQUE KEY `par_registrations_token_unique` (`token`),
  UNIQUE KEY `par_registrations_event_id_pessoa_ativa_unique` (`event_id`,`pessoa_ativa`),
  KEY `par_registrations_person_id_foreign` (`person_id`),
  KEY `par_registrations_registrada_por_foreign` (`registrada_por`),
  KEY `par_registrations_cancelada_por_foreign` (`cancelada_por`),
  KEY `par_registrations_email_index` (`email`),
  KEY `par_registrations_cpf_index` (`cpf`),
  KEY `par_registrations_event_id_nome_index` (`event_id`,`nome`),
  KEY `par_registrations_event_id_qr_enviado_em_qr_envios_index` (`event_id`,`qr_enviado_em`,`qr_envios`),
  CONSTRAINT `par_registrations_cancelada_por_foreign` FOREIGN KEY (`cancelada_por`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `par_registrations_event_id_foreign` FOREIGN KEY (`event_id`) REFERENCES `events` (`id`) ON DELETE CASCADE,
  CONSTRAINT `par_registrations_person_id_foreign` FOREIGN KEY (`person_id`) REFERENCES `par_people` (`id`),
  CONSTRAINT `par_registrations_registrada_por_foreign` FOREIGN KEY (`registrada_por`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ---------------------------------------------------------------------
-- par_checkins — a entrada, POR DIA
--
-- ⚠ `registration_ativa` é VIRTUAL, e isso NÃO é preferência: a base é
-- `registration_id`, cuja FK CASCATEIA, e o InnoDB recusa CASCADE em
-- coluna-base de coluna gerada STORED (ERROR 1215 — foi o que aconteceu
-- com liv_product_photos.capa_unica). VIRTUAL aceita UNIQUE igual.
--
-- Efeito: UM check-in ATIVO por (dia, inscrição), garantido pelo BANCO,
-- mesmo com dois leitores de QR no mesmo segundo. O cancelado vira NULL,
-- não colide, e a pessoa pode ser marcada de novo — com rastro do erro.
-- Ler duplicado é rotina na portaria e aqui vira informação: o segundo
-- operador vê "já entrou às 19h12, por Maria" em vez de duplicar.
--
-- ⚠ `registrado_em` é DATETIME (como liv_sales.vendida_em) e NÃO tem
-- DEFAULT CURRENT_TIMESTAMP: CURRENT_TIMESTAMP resolveria no time_zone da
-- SESSÃO MySQL, que não é o fuso da congregação — seria reintroduzir o bug
-- de fuso por baixo. O valor vem do PHP, por Event::agora(). now() puro
-- erra 3 horas em comando de terminal, que não tem sessão web.
--
-- event_day_id é RESTRICT: apagar dia com presença é apagar história. Para
-- suspender o dia existe par_event_days.ativo.
-- ---------------------------------------------------------------------
CREATE TABLE `par_checkins` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `registration_id` BIGINT UNSIGNED NOT NULL,
  `event_day_id` BIGINT UNSIGNED NOT NULL,
  `canal` ENUM('qr','codigo','busca','manual','retroativo') NOT NULL DEFAULT 'qr',
  `registrado_em` DATETIME NOT NULL,
  `registrado_por` BIGINT UNSIGNED DEFAULT NULL,
  `cancelado_em` DATETIME DEFAULT NULL,
  `cancelado_por` BIGINT UNSIGNED DEFAULT NULL,
  `observacao` VARCHAR(255) DEFAULT NULL,
  `created_at` TIMESTAMP NULL DEFAULT NULL,
  `registration_ativa` BIGINT UNSIGNED GENERATED ALWAYS AS (IF(`cancelado_em` IS NULL,`registration_id`,NULL)) VIRTUAL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `par_checkins_event_day_id_registration_ativa_unique` (`event_day_id`,`registration_ativa`),
  KEY `par_checkins_registration_id_foreign` (`registration_id`),
  KEY `par_checkins_registrado_por_foreign` (`registrado_por`),
  KEY `par_checkins_cancelado_por_foreign` (`cancelado_por`),
  KEY `par_checkins_event_day_id_registrado_em_index` (`event_day_id`,`registrado_em`),
  CONSTRAINT `par_checkins_cancelado_por_foreign` FOREIGN KEY (`cancelado_por`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `par_checkins_event_day_id_foreign` FOREIGN KEY (`event_day_id`) REFERENCES `par_event_days` (`id`),
  CONSTRAINT `par_checkins_registrado_por_foreign` FOREIGN KEY (`registrado_por`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `par_checkins_registration_id_foreign` FOREIGN KEY (`registration_id`) REFERENCES `par_registrations` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
