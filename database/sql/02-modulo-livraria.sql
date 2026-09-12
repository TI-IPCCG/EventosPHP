-- =====================================================================
--  App EVENTOS — IPCCG
--  Arquivo 02 de 05: MÓDULO LIVRARIA  (prefixo liv_)
--  Schema: ipccgorg_Eventos          Rodar DEPOIS do 01-core.sql
-- ---------------------------------------------------------------------
--  Todo o módulo pendura em `events` (núcleo). Os próximos módulos usam
--  o mesmo event_id com o prefixo deles, sem tocar nestas tabelas.
--
--  Referências (RF__/RN__) apontam para o "Escopo Funcional v3.1".
--
--  VALORES MONETÁRIOS: DECIMAL(10,2). Nunca FLOAT.
--
--  SNAPSHOT: custo e preço são COPIADOS para a remessa e de novo para a
--  venda. Preço e desconto mudam entre eventos; o acerto do fornecedor
--  precisa do valor que valia NAQUELE dia.
--
--  ── O ITEM NÃO É "LIVRO" ──────────────────────────────────────────
--  Nada aqui presume livro. Um item pertence a uma CATEGORIA, e a
--  categoria define quais campos ele tem (autor e editora para livro,
--  marca e material para camiseta). Isso está em `liv_categories` +
--  `liv_category_fields` + a coluna JSON `liv_products.atributos`.
--
--  Atributo NÃO é variação. Autor descreve o item; TAMANHO divide o
--  estoque. P, M e G são três saldos independentes, cada um com seus
--  exemplares — por isso existe `liv_variants`, e não um campo "tamanho".
--  Livro não usa variação (variant_id fica NULL).
-- =====================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;


-- =====================================================================
--  CATEGORIAS E CAMPOS  (o que faz o catálogo não ser só de livros)
-- =====================================================================

-- ---------------------------------------------------------------------
-- liv_categories — Livro, Camiseta, Caneca, Devocional…
-- ---------------------------------------------------------------------
CREATE TABLE `liv_categories` (
  `id`         BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `church_id`  BIGINT UNSIGNED  NOT NULL COMMENT 'lógico → ModernApps.churches',
  `nome`       VARCHAR(60)      NOT NULL,
  `slug`       VARCHAR(60)      NOT NULL,
  `usa_variacao` TINYINT(1)     NOT NULL DEFAULT 0
               COMMENT '1 = itens desta categoria têm tamanho/cor com saldo próprio',
  `rotulo_variacao` VARCHAR(30)     NULL COMMENT 'como chamar na tela: Tamanho, Cor',
  `ordem`      TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `ativo`      TINYINT(1)       NOT NULL DEFAULT 1,
  `created_at` TIMESTAMP            NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `liv_categories_church_id_slug_unique` (`church_id`, `slug`),
  KEY `liv_categories_church_id_index` (`church_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ---------------------------------------------------------------------
-- liv_category_fields — quais campos cada categoria pede
-- É este cadastro que permite acrescentar "ISBN" ou "Gramatura" sem
-- desenvolvimento: a tela de item se monta a partir daqui, e os valores
-- caem em liv_products.atributos.
--
-- `chave` é a chave dentro do JSON (sem acento, sem espaço): autor,
-- editora, num_paginas, marca, material.
-- `mostrar_na_lista` marca os 1 ou 2 campos que aparecem embaixo do nome
-- na listagem — autor para livro, marca para camiseta.
-- ---------------------------------------------------------------------
CREATE TABLE `liv_category_fields` (
  `id`               BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `category_id`      BIGINT UNSIGNED  NOT NULL,
  `chave`            VARCHAR(40)      NOT NULL COMMENT 'chave no JSON de atributos',
  `rotulo`           VARCHAR(60)      NOT NULL COMMENT 'o que o usuário lê na tela',
  `tipo`             ENUM('texto','texto_longo','inteiro','decimal','data','selecao','booleano')
                     NOT NULL DEFAULT 'texto',
  `opcoes`           JSON                 NULL COMMENT 'lista de valores quando tipo = selecao',
  `obrigatorio`      TINYINT(1)       NOT NULL DEFAULT 0,
  `mostrar_na_lista` TINYINT(1)       NOT NULL DEFAULT 0,
  `ordem`            TINYINT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `liv_category_fields_category_id_chave_unique` (`category_id`, `chave`),
  CONSTRAINT `liv_category_fields_category_id_foreign` FOREIGN KEY (`category_id`)
    REFERENCES `liv_categories` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- =====================================================================
--  CADASTROS  (reaproveitados entre eventos)
-- =====================================================================

-- ---------------------------------------------------------------------
-- liv_suppliers — fornecedores (RF02)
-- `prefixo` gera o código do exemplar: ECC + 001 = ECC001 (RF08).
-- ---------------------------------------------------------------------
CREATE TABLE `liv_suppliers` (
  `id`               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `church_id`        BIGINT UNSIGNED NOT NULL COMMENT 'lógico → ModernApps.churches',
  `nome`             VARCHAR(150)    NOT NULL,
  `prefixo`          VARCHAR(4)      NOT NULL COMMENT 'ECC, EFL, CAM — prefixo do código do exemplar',
  `condicao_padrao`  ENUM('consignado','firme') NOT NULL DEFAULT 'consignado',
  `desconto_padrao`  DECIMAL(5,2)        NULL COMMENT '% sobre o preço de referência — NULL = varia por item',
  `contato`          VARCHAR(150)        NULL,
  `ativo`            TINYINT(1)      NOT NULL DEFAULT 1,
  `created_at`       TIMESTAMP           NULL,
  `updated_at`       TIMESTAMP           NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `liv_suppliers_church_id_prefixo_unique` (`church_id`, `prefixo`),
  KEY `liv_suppliers_church_id_index` (`church_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ---------------------------------------------------------------------
-- liv_products — catálogo permanente (RF03, RF04)
-- `nome` e não "titulo": camiseta não tem título.
-- `preco_referencia` e não "preco_capa": camiseta não tem capa.
-- `atributos` guarda os campos da categoria. Ex.:
--   livro    → {"autor":"John Piper","editora":"Fiel","num_paginas":224}
--   camiseta → {"marca":"Malwee","material":"Algodão 30.1"}
-- Para filtrar ou ordenar por um atributo específico com índice, crie
-- uma coluna gerada depois — ex.:
--   ALTER TABLE liv_products
--     ADD COLUMN autor VARCHAR(150) AS (atributos->>'$.autor') STORED,
--     ADD INDEX (autor);
-- ---------------------------------------------------------------------
CREATE TABLE `liv_products` (
  `id`               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `church_id`        BIGINT UNSIGNED NOT NULL COMMENT 'lógico → ModernApps.churches',
  `category_id`      BIGINT UNSIGNED NOT NULL,
  `supplier_id`      BIGINT UNSIGNED NOT NULL,
  `nome`             VARCHAR(200)    NOT NULL,
  `preco_referencia` DECIMAL(10,2)       NULL COMMENT 'preço de capa, preço de tabela',
  `desconto`         DECIMAL(5,2)        NULL COMMENT 'exceção ao desconto do fornecedor — NULL usa o dele',
  `atributos`        JSON                NULL COMMENT 'campos definidos pela categoria',
  `observacoes`      TEXT                NULL COMMENT 'campo universal — vale para qualquer categoria',
  `ativo`            TINYINT(1)      NOT NULL DEFAULT 1,
  `created_at`       TIMESTAMP           NULL,
  `updated_at`       TIMESTAMP           NULL,
  PRIMARY KEY (`id`),
  KEY `liv_products_church_id_index` (`church_id`),
  KEY `liv_products_category_id_foreign` (`category_id`),
  KEY `liv_products_supplier_id_foreign` (`supplier_id`),
  KEY `liv_products_nome_index` (`nome`) COMMENT 'busca na mesa (RF12)',
  CONSTRAINT `liv_products_category_id_foreign` FOREIGN KEY (`category_id`)
    REFERENCES `liv_categories` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `liv_products_supplier_id_foreign` FOREIGN KEY (`supplier_id`)
    REFERENCES `liv_suppliers` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ---------------------------------------------------------------------
-- liv_variants — P, M, G / Preta, Branca (RF03)
-- Uma linha por saldo independente. Só existe para categorias com
-- `usa_variacao = 1`; livro não tem nenhuma.
-- É o que permite responder "tem no M?" e "o GG voltou inteiro".
-- ---------------------------------------------------------------------
CREATE TABLE `liv_variants` (
  `id`         BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `product_id` BIGINT UNSIGNED  NOT NULL,
  `nome`       VARCHAR(30)      NOT NULL COMMENT 'P, M, G, GG, Preta',
  `ordem`      TINYINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'P antes de M antes de G',
  `ativo`      TINYINT(1)       NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `liv_variants_product_id_nome_unique` (`product_id`, `nome`),
  CONSTRAINT `liv_variants_product_id_foreign` FOREIGN KEY (`product_id`)
    REFERENCES `liv_products` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ---------------------------------------------------------------------
-- liv_product_photos — fotos do item, com uma CAPA
-- `caminho` é relativo ao disco `public` do Laravel
-- (storage/app/public/livraria/…), servido pelo symlink do storage:link.
-- `caminho_thumb` é a miniatura usada na listagem: a mesa opera em 4G,
-- e mandar a foto original em cada linha derruba a tela de venda.
--
-- ⚠ A coluna gerada `capa_unica` garante NO MÁXIMO UMA capa por item:
-- vale product_id quando capa = 1 e NULL quando não. Como NULL não
-- colide em UNIQUE, o banco impede duas capas sem precisar de trigger.
--
-- ⚠ Ela é VIRTUAL, e não STORED como as outras colunas geradas deste
-- schema. Motivo: o InnoDB recusa ON DELETE CASCADE numa coluna que
-- seja BASE de uma coluna gerada STORED — e `product_id` é a base desta.
-- Como apagar o item deve apagar as fotos, o cascade é que importa.
-- VIRTUAL não tem essa restrição e aceita índice UNIQUE do mesmo jeito;
-- só não ocupa espaço, sendo calculada na leitura.
-- ---------------------------------------------------------------------
CREATE TABLE `liv_product_photos` (
  `id`            BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `product_id`    BIGINT UNSIGNED  NOT NULL,
  `caminho`       VARCHAR(255)     NOT NULL,
  `caminho_thumb` VARCHAR(255)         NULL,
  `capa`          TINYINT(1)       NOT NULL DEFAULT 0,
  `ordem`         TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `bytes`         INT UNSIGNED         NULL,
  `largura`       SMALLINT UNSIGNED    NULL,
  `altura`        SMALLINT UNSIGNED    NULL,
  `created_at`    TIMESTAMP            NULL,
  `capa_unica`    BIGINT UNSIGNED AS (IF(`capa` = 1, `product_id`, NULL)) VIRTUAL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `liv_product_photos_capa_unica_unique` (`capa_unica`),
  KEY `liv_product_photos_product_id_ordem_index` (`product_id`, `ordem`),
  CONSTRAINT `liv_product_photos_product_id_foreign` FOREIGN KEY (`product_id`)
    REFERENCES `liv_products` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ---------------------------------------------------------------------
-- liv_writeoff_reasons — motivos de baixa sem venda (RF30)
-- `gera_custo` é a RN10 virada dado.
-- ---------------------------------------------------------------------
CREATE TABLE `liv_writeoff_reasons` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `church_id`  BIGINT UNSIGNED NOT NULL COMMENT 'lógico → ModernApps.churches',
  `nome`       VARCHAR(60)     NOT NULL,
  `gera_custo` TINYINT(1)      NOT NULL DEFAULT 1 COMMENT 'RN10: 1 = devido ao fornecedor',
  `ativo`      TINYINT(1)      NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `liv_writeoff_reasons_church_id_nome_unique` (`church_id`, `nome`),
  KEY `liv_writeoff_reasons_church_id_index` (`church_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- =====================================================================
--  CONFIGURAÇÃO DO EVENTO
-- =====================================================================

-- ---------------------------------------------------------------------
-- liv_event_settings — o que a livraria acrescenta a um evento (RF01)
-- Fica FORA de `events` de propósito: `events` é do núcleo e não recebe
-- coluna de módulo, senão vira depósito no segundo módulo.
-- ---------------------------------------------------------------------
CREATE TABLE `liv_event_settings` (
  `event_id`   BIGINT UNSIGNED NOT NULL,
  `meta_tipo`  ENUM('zero_a_zero','valor') NOT NULL DEFAULT 'zero_a_zero',
  `meta_valor` DECIMAL(10,2)       NULL COMMENT 'só quando meta_tipo = valor',
  `observacao` VARCHAR(255)        NULL,
  `created_at` TIMESTAMP           NULL,
  `updated_at` TIMESTAMP           NULL,
  PRIMARY KEY (`event_id`),
  CONSTRAINT `liv_event_settings_event_id_foreign` FOREIGN KEY (`event_id`)
    REFERENCES `events` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ---------------------------------------------------------------------
-- liv_payment_methods — formas de pagamento e taxas DO EVENTO (RF05)
-- Por evento: a maquininha muda de contrato entre um ano e outro, e o
-- resultado antigo precisa continuar batendo (RN02).
-- ---------------------------------------------------------------------
CREATE TABLE `liv_payment_methods` (
  `id`               BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `event_id`         BIGINT UNSIGNED  NOT NULL,
  `nome`             VARCHAR(50)      NOT NULL COMMENT 'PIX, Débito, Crédito, Crédito parcelado',
  `taxa_percentual`  DECIMAL(5,2)     NOT NULL DEFAULT 0.00 COMMENT '0 = isento (PIX)',
  `ordem`            TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `ativo`            TINYINT(1)       NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `liv_payment_methods_event_id_nome_unique` (`event_id`, `nome`),
  CONSTRAINT `liv_payment_methods_event_id_foreign` FOREIGN KEY (`event_id`)
    REFERENCES `events` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ---------------------------------------------------------------------
-- liv_event_costs — custos do evento (RF07, RN03)
-- `rateio` NÃO afeta o resultado — este soma o custo uma vez, pelo valor
-- real (RN03/RN04). O rateio serve só à sugestão de preço (RF10/RN07).
-- ---------------------------------------------------------------------
CREATE TABLE `liv_event_costs` (
  `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `event_id`    BIGINT UNSIGNED NOT NULL,
  `supplier_id` BIGINT UNSIGNED     NULL COMMENT 'frete é de um fornecedor — custo geral fica NULL',
  `descricao`   VARCHAR(150)    NOT NULL,
  `valor`       DECIMAL(10,2)   NOT NULL,
  `rateio`      ENUM('direto','por_unidade_enviada','por_unidade_vendida','percentual_venda')
                NOT NULL DEFAULT 'direto',
  `created_at`  TIMESTAMP           NULL,
  PRIMARY KEY (`id`),
  KEY `liv_event_costs_event_id_foreign` (`event_id`),
  KEY `liv_event_costs_supplier_id_foreign` (`supplier_id`),
  CONSTRAINT `liv_event_costs_event_id_foreign` FOREIGN KEY (`event_id`)
    REFERENCES `events` (`id`) ON DELETE CASCADE,
  CONSTRAINT `liv_event_costs_supplier_id_foreign` FOREIGN KEY (`supplier_id`)
    REFERENCES `liv_suppliers` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- =====================================================================
--  REMESSA E ESTOQUE
-- =====================================================================

-- ---------------------------------------------------------------------
-- liv_shipments — remessa de um fornecedor para um evento (RF06)
-- Sem UNIQUE(event_id, supplier_id): segunda entrega no meio do evento
-- é caso real, e cada entrega tem o seu próprio frete.
-- ---------------------------------------------------------------------
CREATE TABLE `liv_shipments` (
  `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `event_id`    BIGINT UNSIGNED NOT NULL,
  `supplier_id` BIGINT UNSIGNED NOT NULL,
  `condicao`    ENUM('consignado','firme') NOT NULL DEFAULT 'consignado'
                COMMENT 'copiado do fornecedor, editável por remessa (RN06)',
  `observacao`  VARCHAR(255)        NULL,
  `created_at`  TIMESTAMP           NULL,
  PRIMARY KEY (`id`),
  KEY `liv_shipments_event_id_foreign` (`event_id`),
  KEY `liv_shipments_supplier_id_foreign` (`supplier_id`),
  CONSTRAINT `liv_shipments_event_id_foreign` FOREIGN KEY (`event_id`)
    REFERENCES `events` (`id`) ON DELETE CASCADE,
  CONSTRAINT `liv_shipments_supplier_id_foreign` FOREIGN KEY (`supplier_id`)
    REFERENCES `liv_suppliers` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ---------------------------------------------------------------------
-- liv_shipment_items — quanto de cada item veio, e a que preço
--
-- É AQUI que a variação entra no estoque: uma linha por (item, variação).
-- Livro         → variant_id NULL, uma linha
-- Camiseta P/M/G → três linhas, três saldos, três preços se preciso
--
-- ⚠ `variant_key` existe por causa do NULL: em UNIQUE, NULL nunca colide
-- com NULL, então UNIQUE(shipment_id, product_id, variant_id) deixaria
-- cadastrar o MESMO livro duas vezes na mesma remessa. A coluna gerada
-- troca NULL por 0 e a unicidade passa a valer para os dois casos.
-- ---------------------------------------------------------------------
CREATE TABLE `liv_shipment_items` (
  `id`             BIGINT UNSIGNED   NOT NULL AUTO_INCREMENT,
  `shipment_id`    BIGINT UNSIGNED   NOT NULL,
  `product_id`     BIGINT UNSIGNED   NOT NULL,
  `variant_id`     BIGINT UNSIGNED       NULL COMMENT 'NULL = item sem variação (livro)',
  `quantidade`     SMALLINT UNSIGNED NOT NULL,
  `custo_unitario` DECIMAL(10,2)     NOT NULL,
  `preco_venda`    DECIMAL(10,2)     NOT NULL,
  `variant_key`    BIGINT UNSIGNED AS (IFNULL(`variant_id`, 0)) STORED,
  PRIMARY KEY (`id`),
  UNIQUE KEY `liv_shipment_items_unique` (`shipment_id`, `product_id`, `variant_key`),
  KEY `liv_shipment_items_product_id_foreign` (`product_id`),
  KEY `liv_shipment_items_variant_id_foreign` (`variant_id`),
  CONSTRAINT `liv_shipment_items_shipment_id_foreign` FOREIGN KEY (`shipment_id`)
    REFERENCES `liv_shipments` (`id`) ON DELETE CASCADE,
  CONSTRAINT `liv_shipment_items_product_id_foreign` FOREIGN KEY (`product_id`)
    REFERENCES `liv_products` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `liv_shipment_items_variant_id_foreign` FOREIGN KEY (`variant_id`)
    REFERENCES `liv_variants` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ---------------------------------------------------------------------
-- liv_copies — UM EXEMPLAR FÍSICO (RF08, RN05)
-- Cada unidade na mesa é uma linha com código único no evento. Como o
-- exemplar aponta para o shipment_item, ele já carrega a variação: a
-- camiseta M e a GG têm exemplares distintos, e o saldo de cada tamanho
-- sai de um COUNT, sem campo de quantidade para desencontrar.
--
-- `event_id` é denormalizado de propósito: o código é único dentro do
-- evento e toda tela ao vivo filtra por evento.
-- ---------------------------------------------------------------------
CREATE TABLE `liv_copies` (
  `id`               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `event_id`         BIGINT UNSIGNED NOT NULL COMMENT 'denormalizado de shipment',
  `shipment_item_id` BIGINT UNSIGNED NOT NULL,
  `codigo`           VARCHAR(20)     NOT NULL COMMENT 'ECC001, CAM042',
  `status`           ENUM('disponivel','reservado','vendido','baixado','devolvido')
                     NOT NULL DEFAULT 'disponivel',
  `created_at`       TIMESTAMP           NULL,
  `updated_at`       TIMESTAMP           NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `liv_copies_event_id_codigo_unique` (`event_id`, `codigo`),
  KEY `liv_copies_shipment_item_id_foreign` (`shipment_item_id`),
  KEY `liv_copies_event_id_status_index` (`event_id`, `status`) COMMENT 'saldo e alertas (RF21)',
  CONSTRAINT `liv_copies_event_id_foreign` FOREIGN KEY (`event_id`)
    REFERENCES `events` (`id`) ON DELETE CASCADE,
  CONSTRAINT `liv_copies_shipment_item_id_foreign` FOREIGN KEY (`shipment_item_id`)
    REFERENCES `liv_shipment_items` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- =====================================================================
--  MOVIMENTO: VENDA
-- =====================================================================

-- ---------------------------------------------------------------------
-- liv_sales — a transação (RF13, RF14)
-- UMA venda com 3 itens = UMA linha aqui. É o que faz a taxa incidir por
-- transação e não por item (RN02).
-- `vendida_em` separado de `created_at` → lançamento retroativo (RF17).
-- `cancelada_em` → estorno (RF16), soft.
-- ---------------------------------------------------------------------
CREATE TABLE `liv_sales` (
  `id`                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `event_id`          BIGINT UNSIGNED NOT NULL,
  `payment_method_id` BIGINT UNSIGNED     NULL,
  `registrado_por`    BIGINT UNSIGNED     NULL COMMENT 'quem operava a mesa',
  `comprador`         VARCHAR(150)        NULL COMMENT 'opcional (RF14)',
  `valor_bruto`       DECIMAL(10,2)   NOT NULL,
  `taxa_percentual`   DECIMAL(5,2)    NOT NULL DEFAULT 0.00,
  `taxa_valor`        DECIMAL(10,2)   NOT NULL DEFAULT 0.00 COMMENT 'RN02',
  `vendida_em`        DATETIME        NOT NULL,
  `cancelada_em`      DATETIME            NULL,
  `cancelada_por`     BIGINT UNSIGNED     NULL,
  `created_at`        TIMESTAMP           NULL,
  PRIMARY KEY (`id`),
  KEY `liv_sales_event_id_vendida_em_index` (`event_id`, `vendida_em`) COMMENT 'ritmo (RF23)',
  KEY `liv_sales_payment_method_id_foreign` (`payment_method_id`),
  KEY `liv_sales_registrado_por_foreign` (`registrado_por`),
  CONSTRAINT `liv_sales_event_id_foreign` FOREIGN KEY (`event_id`)
    REFERENCES `events` (`id`) ON DELETE CASCADE,
  CONSTRAINT `liv_sales_payment_method_id_foreign` FOREIGN KEY (`payment_method_id`)
    REFERENCES `liv_payment_methods` (`id`) ON DELETE SET NULL,
  CONSTRAINT `liv_sales_registrado_por_foreign` FOREIGN KEY (`registrado_por`)
    REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `liv_sales_cancelada_por_foreign` FOREIGN KEY (`cancelada_por`)
    REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ---------------------------------------------------------------------
-- liv_sale_items — os exemplares daquela venda
-- ⚠ `copy_ativo` é a trava da RN09 (ver 04-triggers.sql para o resto).
-- ---------------------------------------------------------------------
CREATE TABLE `liv_sale_items` (
  `id`             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `sale_id`        BIGINT UNSIGNED NOT NULL,
  `copy_id`        BIGINT UNSIGNED NOT NULL,
  `preco`          DECIMAL(10,2)   NOT NULL COMMENT 'snapshot',
  `custo_unitario` DECIMAL(10,2)   NOT NULL COMMENT 'snapshot: base do acerto (RN01)',
  `cancelado_em`   DATETIME            NULL COMMENT 'espelha liv_sales.cancelada_em',
  `copy_ativo`     BIGINT UNSIGNED AS (IF(`cancelado_em` IS NULL, `copy_id`, NULL)) STORED,
  PRIMARY KEY (`id`),
  UNIQUE KEY `liv_sale_items_copy_ativo_unique` (`copy_ativo`),
  KEY `liv_sale_items_sale_id_foreign` (`sale_id`),
  KEY `liv_sale_items_copy_id_foreign` (`copy_id`),
  CONSTRAINT `liv_sale_items_sale_id_foreign` FOREIGN KEY (`sale_id`)
    REFERENCES `liv_sales` (`id`) ON DELETE CASCADE,
  CONSTRAINT `liv_sale_items_copy_id_foreign` FOREIGN KEY (`copy_id`)
    REFERENCES `liv_copies` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- =====================================================================
--  MOVIMENTO: BAIXA SEM VENDA
-- =====================================================================

-- ---------------------------------------------------------------------
-- liv_writeoffs — a baixa (RF15, RF31, RN08)
-- `autorizado_por` é texto: quem autoriza nem sempre tem login.
-- ---------------------------------------------------------------------
CREATE TABLE `liv_writeoffs` (
  `id`             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `event_id`       BIGINT UNSIGNED NOT NULL,
  `reason_id`      BIGINT UNSIGNED NOT NULL,
  `autorizado_por` VARCHAR(150)    NOT NULL COMMENT 'RN08',
  `registrado_por` BIGINT UNSIGNED     NULL,
  `observacao`     VARCHAR(255)        NULL,
  `registrada_em`  DATETIME        NOT NULL,
  `cancelada_em`   DATETIME            NULL COMMENT 'RF32',
  `cancelada_por`  BIGINT UNSIGNED     NULL,
  `created_at`     TIMESTAMP           NULL,
  PRIMARY KEY (`id`),
  KEY `liv_writeoffs_event_id_foreign` (`event_id`),
  KEY `liv_writeoffs_reason_id_foreign` (`reason_id`),
  CONSTRAINT `liv_writeoffs_event_id_foreign` FOREIGN KEY (`event_id`)
    REFERENCES `events` (`id`) ON DELETE CASCADE,
  CONSTRAINT `liv_writeoffs_reason_id_foreign` FOREIGN KEY (`reason_id`)
    REFERENCES `liv_writeoff_reasons` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `liv_writeoffs_registrado_por_foreign` FOREIGN KEY (`registrado_por`)
    REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `liv_writeoffs_cancelada_por_foreign` FOREIGN KEY (`cancelada_por`)
    REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ---------------------------------------------------------------------
-- liv_writeoff_items — os exemplares baixados
-- `gera_custo` é SNAPSHOT do motivo: desmarcar o custo do motivo hoje
-- não pode mudar o acerto de um evento passado.
-- ---------------------------------------------------------------------
CREATE TABLE `liv_writeoff_items` (
  `id`             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `writeoff_id`    BIGINT UNSIGNED NOT NULL,
  `copy_id`        BIGINT UNSIGNED NOT NULL,
  `custo_unitario` DECIMAL(10,2)   NOT NULL COMMENT 'snapshot',
  `gera_custo`     TINYINT(1)      NOT NULL DEFAULT 1 COMMENT 'snapshot do motivo (RN10)',
  `cancelado_em`   DATETIME            NULL COMMENT 'espelha liv_writeoffs.cancelada_em',
  `copy_ativo`     BIGINT UNSIGNED AS (IF(`cancelado_em` IS NULL, `copy_id`, NULL)) STORED,
  PRIMARY KEY (`id`),
  UNIQUE KEY `liv_writeoff_items_copy_ativo_unique` (`copy_ativo`),
  KEY `liv_writeoff_items_writeoff_id_foreign` (`writeoff_id`),
  KEY `liv_writeoff_items_copy_id_foreign` (`copy_id`),
  CONSTRAINT `liv_writeoff_items_writeoff_id_foreign` FOREIGN KEY (`writeoff_id`)
    REFERENCES `liv_writeoffs` (`id`) ON DELETE CASCADE,
  CONSTRAINT `liv_writeoff_items_copy_id_foreign` FOREIGN KEY (`copy_id`)
    REFERENCES `liv_copies` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ---------------------------------------------------------------------
-- liv_cart_holds — exemplar que está no carrinho de alguém AGORA
--
-- Por que uma tabela e não o status 'reservado' do exemplar: o status é o
-- estado FÍSICO (vendido, baixado, devolvido); estar num carrinho é estado de
-- SESSÃO, e some sozinho. Com `expira_em`, carrinho abandonado se cura sem
-- cron — basta filtrar as reservas ainda válidas.
--
-- A reserva NÃO bloqueia: só deixa visível que há outra venda em andamento com
-- aquele item. Quem conclui primeiro leva, como sempre.
-- ---------------------------------------------------------------------
CREATE TABLE `liv_cart_holds` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `event_id`   BIGINT UNSIGNED NOT NULL,
  `copy_id`    BIGINT UNSIGNED NOT NULL,
  `user_id`    BIGINT UNSIGNED NOT NULL,
  `criado_em`  TIMESTAMP       NOT NULL,
  `expira_em`  TIMESTAMP       NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `liv_cart_holds_copy_id_unique` (`copy_id`),
  KEY `liv_cart_holds_expira_em_index` (`expira_em`),
  KEY `liv_cart_holds_event_id_expira_em_index` (`event_id`, `expira_em`),
  KEY `liv_cart_holds_user_id_foreign` (`user_id`),
  CONSTRAINT `liv_cart_holds_event_id_foreign` FOREIGN KEY (`event_id`)
    REFERENCES `events` (`id`) ON DELETE CASCADE,
  CONSTRAINT `liv_cart_holds_copy_id_foreign` FOREIGN KEY (`copy_id`)
    REFERENCES `liv_copies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `liv_cart_holds_user_id_foreign` FOREIGN KEY (`user_id`)
    REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
