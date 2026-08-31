-- ⚠ SET NAMES é a PRIMEIRA linha por um motivo: sem ele o cliente mysql
-- assume latin1, e todo caractere acentuado do arquivo entra no banco em
-- DUPLO-ENCODING. O sintoma aparece só na tela ("IPCCG Â€" Igreja..."), muito
-- depois de o dado já estar corrompido. Todo .sql deste projeto começa assim.
SET NAMES utf8mb4;

-- Espelha a arquitetura de produção: dois schemas no mesmo servidor, geridos
-- por UM usuário MySQL com acesso a ambos. Nomes iguais aos do cPanel, para
-- paridade total entre dev e produção.
CREATE DATABASE IF NOT EXISTS `ipccgorg_Eventos`
    CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE IF NOT EXISTS `ipccgorg_ModernApps`
    CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE USER IF NOT EXISTS 'eventos'@'%' IDENTIFIED BY 'eventos';
GRANT ALL PRIVILEGES ON `ipccgorg_Eventos`.*    TO 'eventos'@'%';
GRANT ALL PRIVILEGES ON `ipccgorg_ModernApps`.* TO 'eventos'@'%';
FLUSH PRIVILEGES;

-- O schema central é do EscalaMembros; aqui só replicamos `churches` para o
-- dev funcionar isolado. Em produção esta tabela JÁ EXISTE — não recrie.
USE `ipccgorg_ModernApps`;
CREATE TABLE IF NOT EXISTS `churches` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name`       VARCHAR(150) NOT NULL,
  `slug`       VARCHAR(50)  NOT NULL,
  `state`      CHAR(2)          NULL,
  `city`       VARCHAR(120)     NULL,
  `status`     ENUM('active','inactive') NOT NULL DEFAULT 'active',
  `timezone`   VARCHAR(64)  NOT NULL DEFAULT 'America/Sao_Paulo',
  `created_at` TIMESTAMP        NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `churches_slug_unique` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `churches` (`id`,`name`,`slug`,`state`,`city`,`status`,`created_at`)
VALUES (1,'IPCCG — Igreja Presbiteriana Central de Campo Grande','ipccg','MS','Campo Grande','active',NOW());
