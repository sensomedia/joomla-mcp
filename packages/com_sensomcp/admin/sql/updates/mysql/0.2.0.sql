-- com_sensomcp 0.2.0 — tables OAuth 2.1

CREATE TABLE IF NOT EXISTS `#__sensomcp_oauth_clients` (
    `id`                         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `client_id`                  VARCHAR(100)  NOT NULL,
    `client_secret`              VARCHAR(255)  NULL DEFAULT NULL,
    `client_name`                VARCHAR(255)  NOT NULL DEFAULT '',
    `redirect_uris`              TEXT          NOT NULL,
    `grant_types`                VARCHAR(255)  NOT NULL DEFAULT 'authorization_code refresh_token',
    `token_endpoint_auth_method` VARCHAR(50)   NOT NULL DEFAULT 'none',
    `created`                    DATETIME      NOT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `idx_client_id` (`client_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `#__sensomcp_oauth_codes` (
    `id`                    INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `code_hash`             CHAR(64)      NOT NULL,
    `client_id`             VARCHAR(100)  NOT NULL,
    `user_id`               INT           NOT NULL,
    `redirect_uri`          VARCHAR(1024) NOT NULL,
    `scope`                 VARCHAR(255)  NOT NULL DEFAULT '',
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
    `scope`      VARCHAR(255)  NOT NULL DEFAULT '',
    `resource`   VARCHAR(1024) NOT NULL DEFAULT '',
    `expires`    DATETIME      NOT NULL,
    `revoked`    TINYINT(1)    NOT NULL DEFAULT 0,
    `created`    DATETIME      NOT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `idx_token_hash` (`token_hash`),
    KEY `idx_user_client` (`user_id`, `client_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
