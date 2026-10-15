CREATE TABLE IF NOT EXISTS pulse_users (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  display_name VARCHAR(40) NOT NULL,
  email VARCHAR(254) CHARACTER SET ascii COLLATE ascii_general_ci NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY pulse_users_email (email),
  UNIQUE KEY pulse_users_name (display_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS pulse_rate_limits (
  rate_key CHAR(64) CHARACTER SET ascii NOT NULL,
  bucket BIGINT NOT NULL,
  hits INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (rate_key, bucket),
  KEY pulse_rate_limits_cleanup (bucket)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS pulse_content (
id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
user_id BIGINT UNSIGNED NOT NULL,
title VARCHAR(120) NOT NULL,
description TEXT NOT NULL,
genre VARCHAR(20) NOT NULL,
bpm SMALLINT UNSIGNED NOT NULL,
audio_file VARCHAR(80) NOT NULL,
audio_mime VARCHAR(50) NOT NULL,
cover_file VARCHAR(80) NULL,
cover_mime VARCHAR(50) NULL,
bytes BIGINT UNSIGNED NOT NULL,
created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
KEY pulse_content_user(user_id),
CONSTRAINT pulse_content_owner FOREIGN KEY(user_id) REFERENCES pulse_users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
