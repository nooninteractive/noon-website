CREATE TABLE IF NOT EXISTS noon_users (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  display_name VARCHAR(40) NOT NULL,
  email VARCHAR(254) CHARACTER SET ascii COLLATE ascii_general_ci NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY noon_users_email (email),
  UNIQUE KEY noon_users_name (display_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS noon_comments (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  game VARCHAR(20) NOT NULL,
  area VARCHAR(20) NOT NULL,
  body TEXT NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY noon_comments_scope (game, area, id),
  CONSTRAINT noon_comments_user FOREIGN KEY (user_id) REFERENCES noon_users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS noon_rate_limits (
  rate_key CHAR(64) CHARACTER SET ascii NOT NULL,
  bucket BIGINT NOT NULL,
  hits INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (rate_key, bucket),
  KEY noon_rate_limits_cleanup (bucket)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
