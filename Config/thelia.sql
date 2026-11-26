# This is a fix for InnoDB in MySQL >= 4.1.x
# It "suspends judgement" for fkey relationships until are tables are set.
SET FOREIGN_KEY_CHECKS = 0;

-- ---------------------------------------------------------------------
-- referral_code
-- ---------------------------------------------------------------------

DROP TABLE IF EXISTS `referral_code`;

CREATE TABLE `referral_code`
(
    `id` INTEGER NOT NULL AUTO_INCREMENT,
    `customer_id` INTEGER NOT NULL,
    `code` VARCHAR(16) NOT NULL,
    `active` TINYINT DEFAULT 1 NOT NULL,
    `created_at` DATETIME,
    `updated_at` DATETIME,
    PRIMARY KEY (`id`),
    UNIQUE INDEX `referral_code_customer_id_unique` (`customer_id`),
    UNIQUE INDEX `referral_code_code_unique` (`code`),
    CONSTRAINT `fk_referral_code_customer_id`
        FOREIGN KEY (`customer_id`)
        REFERENCES `customer` (`id`)
        ON DELETE CASCADE
) ENGINE=InnoDB CHARACTER SET='utf8mb4';

-- ---------------------------------------------------------------------
-- referral
-- ---------------------------------------------------------------------

DROP TABLE IF EXISTS `referral`;

CREATE TABLE `referral`
(
    `id` INTEGER NOT NULL AUTO_INCREMENT,
    `sponsor_customer_id` INTEGER,
    `referral_code_id` INTEGER,
    `referee_customer_id` INTEGER NOT NULL,
    `status` VARCHAR(16) DEFAULT 'registered' NOT NULL,
    `status_reason` VARCHAR(255),
    `trigger_order_id` INTEGER,
    `rewarded_at` DATETIME,
    `created_at` DATETIME,
    `updated_at` DATETIME,
    PRIMARY KEY (`id`),
    UNIQUE INDEX `referral_referee_customer_id_unique` (`referee_customer_id`),
    INDEX `idx_referral_sponsor_customer_id` (`sponsor_customer_id`),
    INDEX `fi_referral_referral_code_id` (`referral_code_id`),
    INDEX `fi_referral_trigger_order_id` (`trigger_order_id`),
    CONSTRAINT `fk_referral_sponsor_customer_id`
        FOREIGN KEY (`sponsor_customer_id`)
        REFERENCES `customer` (`id`)
        ON DELETE SET NULL,
    CONSTRAINT `fk_referral_referral_code_id`
        FOREIGN KEY (`referral_code_id`)
        REFERENCES `referral_code` (`id`)
        ON DELETE SET NULL,
    CONSTRAINT `fk_referral_referee_customer_id`
        FOREIGN KEY (`referee_customer_id`)
        REFERENCES `customer` (`id`)
        ON DELETE CASCADE,
    CONSTRAINT `fk_referral_trigger_order_id`
        FOREIGN KEY (`trigger_order_id`)
        REFERENCES `order` (`id`)
        ON DELETE SET NULL
) ENGINE=InnoDB CHARACTER SET='utf8mb4';

-- ---------------------------------------------------------------------
-- referral_reward
-- ---------------------------------------------------------------------

DROP TABLE IF EXISTS `referral_reward`;

CREATE TABLE `referral_reward`
(
    `id` INTEGER NOT NULL AUTO_INCREMENT,
    `referral_id` INTEGER NOT NULL,
    `beneficiary` VARCHAR(8) NOT NULL,
    `customer_id` INTEGER,
    `reward_type` VARCHAR(16) DEFAULT 'coupon' NOT NULL,
    `value_type` VARCHAR(8) NOT NULL,
    `value` DECIMAL(16,6) NOT NULL,
    `minimum_amount` DECIMAL(16,6) DEFAULT 0 NOT NULL,
    `coupon_id` INTEGER,
    `coupon_code` VARCHAR(45),
    `status` VARCHAR(16) DEFAULT 'issued' NOT NULL,
    `cancel_reason` VARCHAR(255),
    `cancelled_at` DATETIME,
    `created_at` DATETIME,
    `updated_at` DATETIME,
    PRIMARY KEY (`id`),
    UNIQUE INDEX `referral_reward_referral_beneficiary_unique` (`referral_id`, `beneficiary`),
    INDEX `idx_referral_reward_customer_beneficiary_created` (`customer_id`, `beneficiary`, `created_at`),
    INDEX `fi_referral_reward_coupon_id` (`coupon_id`),
    CONSTRAINT `fk_referral_reward_referral_id`
        FOREIGN KEY (`referral_id`)
        REFERENCES `referral` (`id`)
        ON DELETE CASCADE,
    CONSTRAINT `fk_referral_reward_customer_id`
        FOREIGN KEY (`customer_id`)
        REFERENCES `customer` (`id`)
        ON DELETE SET NULL,
    CONSTRAINT `fk_referral_reward_coupon_id`
        FOREIGN KEY (`coupon_id`)
        REFERENCES `coupon` (`id`)
        ON DELETE SET NULL
) ENGINE=InnoDB CHARACTER SET='utf8mb4';

# This restores the fkey checks, after having unset them earlier
SET FOREIGN_KEY_CHECKS = 1;
