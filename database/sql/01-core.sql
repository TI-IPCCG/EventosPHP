-- =====================================================================
--  App EVENTOS — IPCCG
--  Arquivo 01 de 05: NÚCLEO (identidade, ACL e eventos)
--  Schema: ipccgorg_Eventos          Conexão Laravel: mysql (default)
-- ---------------------------------------------------------------------
--  Como rodar no cPanel:
--    1. MySQL Databases  → crie o banco  ipccgorg_Eventos
--    2. MySQL Databases  → crie/anexe o usuário com ALL PRIVILEGES
--       (o MESMO usuário que já acessa ipccgorg_ModernApps, para a
--        conexão cross-schema das congregações funcionar)
--    3. phpMyAdmin → selecione ipccgorg_Eventos → aba Importar/SQL
--       → rode 01, depois 02, depois 03, nesta ordem.
--
--  NÃO existe FK física para `churches`: essa tabela vive no schema
--  central ipccgorg_ModernApps e o MySQL não cruza schemas com FK.
--  Toda coluna church_id é vínculo LÓGICO, resolvido pelo Eloquent —
--  mesmo padrão já adotado no EscalaMembros.
-- =====================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;


-- ---------------------------------------------------------------------
-- system_roles — perfis de acesso, POR congregação
-- ---------------------------------------------------------------------
CREATE TABLE `system_roles` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `church_id`  BIGINT UNSIGNED NOT NULL COMMENT 'lógico → ModernApps.churches',
  `name`       VARCHAR(50)     NOT NULL,
  PRIMARY KEY (`id`),
  KEY `system_roles_church_id_index` (`church_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ---------------------------------------------------------------------
-- permissions — catálogo global de ações do código (não é por igreja)
-- Convenção de slug: "area.acao" — o prefixo agrupa na tela de perfis.
-- ---------------------------------------------------------------------
CREATE TABLE `permissions` (
  `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `slug`        VARCHAR(100)    NOT NULL,
  `description` VARCHAR(255)    NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `permissions_slug_unique` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ---------------------------------------------------------------------
-- permission_system_role — quais permissões cada perfil concede
-- ---------------------------------------------------------------------
CREATE TABLE `permission_system_role` (
  `system_role_id` BIGINT UNSIGNED NOT NULL,
  `permission_id`  BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (`system_role_id`, `permission_id`),
  KEY `permission_system_role_permission_id_foreign` (`permission_id`),
  CONSTRAINT `permission_system_role_system_role_id_foreign` FOREIGN KEY (`system_role_id`)
    REFERENCES `system_roles` (`id`) ON DELETE CASCADE,
  CONSTRAINT `permission_system_role_permission_id_foreign`  FOREIGN KEY (`permission_id`)
    REFERENCES `permissions` (`id`)  ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ---------------------------------------------------------------------
-- users — IDENTIDADE. Própria deste app (decisão de 29/08/2026).
-- Sem church_id: a pessoa pode servir em mais de uma congregação; o
-- vínculo (perfil + ativo) mora em `memberships`.
-- email em 191 chars por causa do limite de chave utf8mb4 no cPanel.
-- ---------------------------------------------------------------------
CREATE TABLE `users` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name`       VARCHAR(150)    NOT NULL,
  `email`      VARCHAR(191)    NOT NULL,
  `password`   VARCHAR(255)    NOT NULL,
  `telefone`   VARCHAR(20)         NULL,
  `is_super`   TINYINT(1)      NOT NULL DEFAULT 0 COMMENT 'super-admin: entra em qualquer congregação',
  `remember_token` VARCHAR(100)    NULL COMMENT '"continuar conectado" — padrão do Laravel',
  `created_at` TIMESTAMP           NULL,
  `updated_at` TIMESTAMP           NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `users_email_unique` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ---------------------------------------------------------------------
-- memberships — vínculo pessoa ↔ congregação. Perfil e status ATIVO são
-- por igreja: a mesma identidade pode ser coordenadora aqui e operadora ali.
-- Cadastro público entra com status = 0 (aguardando ativação).
-- ---------------------------------------------------------------------
CREATE TABLE `memberships` (
  `id`             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`        BIGINT UNSIGNED NOT NULL,
  `church_id`      BIGINT UNSIGNED NOT NULL COMMENT 'lógico → ModernApps.churches',
  `system_role_id` BIGINT UNSIGNED     NULL,
  `status`         TINYINT(1)      NOT NULL DEFAULT 1,
  `created_at`     TIMESTAMP           NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `memberships_user_id_church_id_unique` (`user_id`, `church_id`),
  KEY `memberships_church_id_index` (`church_id`),
  KEY `memberships_system_role_id_foreign` (`system_role_id`),
  CONSTRAINT `memberships_user_id_foreign` FOREIGN KEY (`user_id`)
    REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `memberships_system_role_id_foreign` FOREIGN KEY (`system_role_id`)
    REFERENCES `system_roles` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ---------------------------------------------------------------------
-- password_reset_tokens — recuperação de senha (padrão do Laravel)
-- ---------------------------------------------------------------------
CREATE TABLE `password_reset_tokens` (
  `email`      VARCHAR(191) NOT NULL,
  `token`      VARCHAR(255) NOT NULL,
  `created_at` TIMESTAMP        NULL,
  PRIMARY KEY (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ---------------------------------------------------------------------
-- notifications — central in-app (padrão do Laravel, canal database)
-- ---------------------------------------------------------------------
CREATE TABLE `notifications` (
  `id`              CHAR(36)        NOT NULL,
  `type`            VARCHAR(255)    NOT NULL,
  `notifiable_type` VARCHAR(255)    NOT NULL,
  `notifiable_id`   BIGINT UNSIGNED NOT NULL,
  `data`            TEXT            NOT NULL,
  `read_at`         TIMESTAMP           NULL,
  `created_at`      TIMESTAMP           NULL,
  `updated_at`      TIMESTAMP           NULL,
  PRIMARY KEY (`id`),
  KEY `notifications_notifiable_type_notifiable_id_index` (`notifiable_type`, `notifiable_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ---------------------------------------------------------------------
-- events — NÚCLEO DO APP, compartilhado por todos os módulos.
-- Um evento é o Simpósio, o retiro, o congresso. A livraria é UM módulo
-- que acontece dentro dele; os próximos módulos (inscrições, finanças,
-- programação) penduram no MESMO event_id.
-- Nada específico de livraria entra aqui — meta financeira, por exemplo,
-- fica em liv_event_settings (arquivo 02).
-- ---------------------------------------------------------------------
CREATE TABLE `events` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `church_id`  BIGINT UNSIGNED NOT NULL COMMENT 'lógico → ModernApps.churches',
  `nome`       VARCHAR(150)    NOT NULL,
  `local`      VARCHAR(150)        NULL,
  `inicio`     DATE            NOT NULL,
  `fim`        DATE                NULL COMMENT 'NULL = evento de um dia só',
  `status`     ENUM('planejamento','em_andamento','encerrado') NOT NULL DEFAULT 'planejamento',
  `created_at` TIMESTAMP           NULL,
  `updated_at` TIMESTAMP           NULL,
  PRIMARY KEY (`id`),
  KEY `events_church_id_index` (`church_id`),
  KEY `events_inicio_index` (`inicio`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


SET FOREIGN_KEY_CHECKS = 1;
