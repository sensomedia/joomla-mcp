-- com_sensomcp 0.3.0 — journal des requêtes MCP (dashboard)

CREATE TABLE IF NOT EXISTS `#__sensomcp_log` (
    `id`        INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `created`   DATETIME     NOT NULL,
    `user_id`   INT          NOT NULL DEFAULT 0,
    `ip`        VARCHAR(45)  NOT NULL DEFAULT '',
    `method`    VARCHAR(100) NOT NULL DEFAULT '',
    `tool`      VARCHAR(100) NULL DEFAULT NULL,
    `success`   TINYINT(1)   NOT NULL DEFAULT 1,
    `error`     VARCHAR(255) NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_created` (`created`),
    KEY `idx_tool` (`tool`),
    KEY `idx_method` (`method`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
