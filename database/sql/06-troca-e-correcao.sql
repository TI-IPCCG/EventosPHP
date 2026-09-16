-- =====================================================================
--  EVENTOS IPCCG — Arquivo 06 de 06: TROCA E CORREÇÃO DE VENDA
--
--  Espelho de:
--    2026_09_16_050001_create_liv_exchanges_table
--    2026_09_16_050002_add_troca_e_correcao_to_liv_sales
--
--  Para quem já tem o banco em produção: rode este arquivo inteiro, UMA
--  VEZ. Ele só ACRESCENTA — nenhuma linha existente é tocada.
--
--  ⚠ Rodar de novo para nas ALTERs com "Duplicate column name" — que é o
--    jeito do MySQL de dizer que já foi aplicado. Nada é corrompido; pare
--    e confira com:  SHOW COLUMNS FROM liv_sales LIKE 'corrigida_em';
--
--  ── POR QUE A TROCA É TABELA, E NÃO UM UPDATE NA VENDA ──────────────
--  Quem pagou R$ 50 no PIX aparece no extrato como R$ 50, e a operadora
--  já reteve a taxa sobre 50 — mesmo que depois troque por um item de
--  R$ 40 e receba R$ 10 de volta em dinheiro. Reescrever
--  `liv_sales.valor_bruto` faria o relatório divergir do extrato e
--  subestimar a taxa devida.
--
--  Então a venda original fica intacta e a diferença é movimento próprio,
--  com forma de pagamento e taxa próprias. A receita se corrige sozinha,
--  porque ela soma `liv_sale_items` e não `valor_bruto`.
--
--  Correção é o caminho OPOSTO, para "foi digitado errado, ninguém trocou
--  nada": aí sim reescreve, porque o número nunca foi verdade. As colunas
--  `corrigida_em`/`corrigida_por` guardam quem mexeu.
-- =====================================================================

-- ── A troca ──────────────────────────────────────────────────────────
-- `diferenca` tem SINAL: positivo = cobrado a mais, negativo = devolvido.
-- Uma coluna em vez de duas porque nenhuma troca tem as duas coisas, e
-- duas nullable convidariam a preencher as duas por engano.
CREATE TABLE IF NOT EXISTS `liv_exchanges` (
  `id`                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `sale_id`           BIGINT UNSIGNED NOT NULL,
  `event_id`          BIGINT UNSIGNED NOT NULL,
  `payment_method_id` BIGINT UNSIGNED     NULL COMMENT 'como o dinheiro da diferenca se moveu',
  `diferenca`         DECIMAL(10,2)   NOT NULL COMMENT 'com sinal: positivo = cobrado a mais, negativo = devolvido',
  `taxa_percentual`   DECIMAL(5,2)    NOT NULL DEFAULT 0.00,
  `taxa_valor`        DECIMAL(10,2)   NOT NULL DEFAULT 0.00 COMMENT 'so sobre cobranca adicional',
  `motivo`            VARCHAR(200)        NULL,
  `registrado_por`    BIGINT UNSIGNED     NULL,
  `realizada_em`      DATETIME        NOT NULL COMMENT 'hora de parede da congregacao, vinda do PHP',
  `created_at`        TIMESTAMP           NULL,
  PRIMARY KEY (`id`),
  KEY `liv_exchanges_sale_id_foreign` (`sale_id`),
  KEY `liv_exchanges_payment_method_id_foreign` (`payment_method_id`),
  KEY `liv_exchanges_registrado_por_foreign` (`registrado_por`),
  KEY `liv_exchanges_event_id_realizada_em_index` (`event_id`, `realizada_em`),
  CONSTRAINT `liv_exchanges_event_id_foreign` FOREIGN KEY (`event_id`)
    REFERENCES `events` (`id`) ON DELETE CASCADE,
  CONSTRAINT `liv_exchanges_payment_method_id_foreign` FOREIGN KEY (`payment_method_id`)
    REFERENCES `liv_payment_methods` (`id`) ON DELETE SET NULL,
  CONSTRAINT `liv_exchanges_registrado_por_foreign` FOREIGN KEY (`registrado_por`)
    REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `liv_exchanges_sale_id_foreign` FOREIGN KEY (`sale_id`)
    REFERENCES `liv_sales` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Rastro da correção ───────────────────────────────────────────────
-- Correção reescreve valor_bruto e taxa. Sem registrar quem mexeu e
-- quando, a venda passa a valer outro número e ninguém explica a
-- diferença no fechamento.
ALTER TABLE `liv_sales`
  ADD COLUMN `corrigida_em`  DATETIME        NULL AFTER `cancelada_por`,
  ADD COLUMN `corrigida_por` BIGINT UNSIGNED NULL AFTER `corrigida_em`,
  ADD KEY `liv_sales_corrigida_por_foreign` (`corrigida_por`),
  ADD CONSTRAINT `liv_sales_corrigida_por_foreign` FOREIGN KEY (`corrigida_por`)
    REFERENCES `users` (`id`) ON DELETE SET NULL;

-- ── De qual troca veio, para qual troca foi ──────────────────────────
-- Duas colunas resolvem a leitura inteira, sem coluna de "motivo":
--   · exchange_in_id  preenchido → o item ENTROU nesta troca
--   · exchange_out_id preenchido → o item SAIU nesta troca
-- E o resto se lê por AUSÊNCIA: item com `cancelado_em` e sem
-- exchange_out_id saiu por estorno (se a venda tem cancelada_em) ou por
-- correção (se não tem). Três motivos distinguidos sem um ENUM a manter.
ALTER TABLE `liv_sale_items`
  ADD COLUMN `exchange_in_id`  BIGINT UNSIGNED NULL AFTER `cancelado_em`,
  ADD COLUMN `exchange_out_id` BIGINT UNSIGNED NULL AFTER `exchange_in_id`,
  ADD KEY `liv_sale_items_exchange_in_id_foreign` (`exchange_in_id`),
  ADD KEY `liv_sale_items_exchange_out_id_foreign` (`exchange_out_id`),
  ADD CONSTRAINT `liv_sale_items_exchange_in_id_foreign` FOREIGN KEY (`exchange_in_id`)
    REFERENCES `liv_exchanges` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `liv_sale_items_exchange_out_id_foreign` FOREIGN KEY (`exchange_out_id`)
    REFERENCES `liv_exchanges` (`id`) ON DELETE SET NULL;

-- ── A permissão ──────────────────────────────────────────────────────
-- Mexer em venda já registrada é ato financeiro distinto de operar a
-- mesa: o voluntário vende, mas quem corrige é um perfil menor.
-- `permissions` não tem timestamps (ver 01-core.sql) — INSERT IGNORE basta,
-- e a UNIQUE do slug torna rodar duas vezes inofensivo.
INSERT IGNORE INTO `permissions` (`slug`, `description`) VALUES
  ('livraria.corrigir', 'Corrigir, trocar itens e estornar venda já registrada');
