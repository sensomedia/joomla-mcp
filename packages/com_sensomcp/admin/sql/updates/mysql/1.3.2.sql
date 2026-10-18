ALTER TABLE `#__sensomcp_oauth_refresh` ADD COLUMN `consumed` DATETIME NULL DEFAULT NULL AFTER `revoked`;
ALTER TABLE `#__sensomcp_oauth_refresh` ADD COLUMN `family` CHAR(32) NULL DEFAULT NULL AFTER `origin`;
UPDATE `#__sensomcp_oauth_refresh` SET `family` = MD5(CONCAT(`user_id`, '|', `client_id`, '|', COALESCE(`origin`, `created`))) WHERE `family` IS NULL;
ALTER TABLE `#__sensomcp_oauth_refresh` ADD KEY `idx_family` (`family`);
