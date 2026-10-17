-- com_sensomcp 1.3.1 — durée absolue des connexions OAuth : origine de la chaîne de refresh tokens

ALTER TABLE `#__sensomcp_oauth_refresh` ADD COLUMN `origin` DATETIME NULL DEFAULT NULL AFTER `created`;
UPDATE `#__sensomcp_oauth_refresh` SET `origin` = `created` WHERE `origin` IS NULL;

-- M2 : IP d'origine des enregistrements dynamiques (limitation par IP)
ALTER TABLE `#__sensomcp_oauth_clients` ADD COLUMN `created_ip` VARCHAR(45) NULL DEFAULT NULL AFTER `created`;
