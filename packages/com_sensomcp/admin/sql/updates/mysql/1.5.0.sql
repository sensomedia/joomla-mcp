-- Jetons de confirmation des outils « à confirmer » (1.5.0) : usage unique, 5 min
CREATE TABLE IF NOT EXISTS `#__sensomcp_confirmations` (
    `token_hash` CHAR(64)     NOT NULL,
    `user_id`    INT          NOT NULL,
    `tool`       VARCHAR(100) NOT NULL,
    `args_hash`  CHAR(64)     NOT NULL,
    `expires`    INT          NOT NULL,
    PRIMARY KEY (`token_hash`),
    KEY `idx_expires` (`expires`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
