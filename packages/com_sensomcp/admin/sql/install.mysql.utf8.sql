-- com_sensomcp — tables OAuth 2.1 (serveur d'autorisation MCP)

CREATE TABLE IF NOT EXISTS `#__sensomcp_oauth_clients` (
    `id`                         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `client_id`                  VARCHAR(100)  NOT NULL,
    `client_secret`              VARCHAR(255)  NULL DEFAULT NULL,
    `client_name`                VARCHAR(255)  NOT NULL DEFAULT '',
    `redirect_uris`              TEXT          NOT NULL,
    `grant_types`                VARCHAR(255)  NOT NULL DEFAULT 'authorization_code refresh_token',
    `token_endpoint_auth_method` VARCHAR(50)   NOT NULL DEFAULT 'none',
    `created`                    DATETIME      NOT NULL,
    `created_ip`                 VARCHAR(45)   NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `idx_client_id` (`client_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `#__sensomcp_oauth_codes` (
    `id`                    INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `code_hash`             CHAR(64)      NOT NULL,
    `client_id`             VARCHAR(100)  NOT NULL,
    `user_id`               INT           NOT NULL,
    `redirect_uri`          VARCHAR(1024) NOT NULL,
    `scope`                 VARCHAR(1024)  NOT NULL DEFAULT '',
    `resource`              VARCHAR(1024) NOT NULL DEFAULT '',
    `code_challenge`        VARCHAR(255)  NOT NULL DEFAULT '',
    `code_challenge_method` VARCHAR(10)   NOT NULL DEFAULT 'S256',
    `expires`               DATETIME      NOT NULL,
    `created`               DATETIME      NOT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `idx_code_hash` (`code_hash`),
    KEY `idx_expires` (`expires`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `#__sensomcp_oauth_refresh` (
    `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `token_hash` CHAR(64)      NOT NULL,
    `client_id`  VARCHAR(100)  NOT NULL,
    `user_id`    INT           NOT NULL,
    `scope`      VARCHAR(1024)  NOT NULL DEFAULT '',
    `resource`   VARCHAR(1024) NOT NULL DEFAULT '',
    `expires`    DATETIME      NOT NULL,
    `revoked`    TINYINT(1)    NOT NULL DEFAULT 0,
    `created`    DATETIME      NOT NULL,
    `origin`     DATETIME      NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `idx_token_hash` (`token_hash`),
    KEY `idx_user_client` (`user_id`, `client_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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

CREATE TABLE IF NOT EXISTS `#__sensomcp_tool_state` (
    `tool`    VARCHAR(100) NOT NULL,
    `enabled` TINYINT(1)   NOT NULL DEFAULT 1,
    PRIMARY KEY (`tool`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `#__sensomcp_ratelimit` (
    `rl_key`       VARCHAR(191) NOT NULL,
    `window_start` INT          NOT NULL DEFAULT 0,
    `hits`         INT          NOT NULL DEFAULT 0,
    PRIMARY KEY (`rl_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
