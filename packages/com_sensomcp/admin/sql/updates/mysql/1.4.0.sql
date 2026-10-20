CREATE TABLE IF NOT EXISTS `#__sensomcp_catalog` (
    `conn_key`    VARCHAR(160)  NOT NULL,
    `user_id`     INT           NOT NULL,
    `client_id`   VARCHAR(100)  NOT NULL DEFAULT '',
    `scope`       VARCHAR(1024) NOT NULL DEFAULT '',
    `fingerprint` CHAR(40)      NOT NULL,
    `tool_count`  INT           NOT NULL DEFAULT 0,
    `listed`      DATETIME      NOT NULL,
    `assumed`     TINYINT(1)    NOT NULL DEFAULT 0,
    `notified`    DATETIME      NULL DEFAULT NULL,
    PRIMARY KEY (`conn_key`),
    KEY `idx_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
