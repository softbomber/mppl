-- Email verification support
-- Run once against the mpol database

-- Track verification codes/tokens per dealer
CREATE TABLE IF NOT EXISTS email_verifications (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    dealer_id   INT          NOT NULL,
    email       VARCHAR(255) NOT NULL,
    code        VARCHAR(6)   NOT NULL,
    token       VARCHAR(64)  NOT NULL,
    attempt     TINYINT      NOT NULL DEFAULT 1,   -- 1 = first, 2 = second (last chance)
    created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at  DATETIME     NOT NULL,
    used        TINYINT(1)   NOT NULL DEFAULT 0,
    INDEX idx_dealer (dealer_id),
    INDEX idx_token  (token)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- Flag on dealers table: 0 = not verified, 1 = verified
ALTER TABLE dealers
    ADD COLUMN IF NOT EXISTS email_verified TINYINT(1) NOT NULL DEFAULT 0;
