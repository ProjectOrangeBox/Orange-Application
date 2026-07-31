-- Orders example: a parent row with a variable number of child rows.
--
-- This is the shape the records table cannot show. RecordDto is flat, so it
-- exercises field validation and nothing else; an order arrives as one payload
-- carrying its own fields plus a list of line items, each of which has to be
-- validated in its own right and reported against its own row.
--
-- Imported on first run alongside webapp_sample.sql - see initdb/README.md.

SET NAMES utf8mb4;

DROP TABLE IF EXISTS `order_lines`;
DROP TABLE IF EXISTS `orders`;
DROP TABLE IF EXISTS `customers`;

CREATE TABLE `customers` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `name` VARCHAR(64) NOT NULL,
    `email` VARCHAR(128) NOT NULL,
    `phone` VARCHAR(64) NOT NULL DEFAULT '',
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_customers_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `orders` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `customer_id` INT UNSIGNED NOT NULL,
    `ordered_on` DATE NOT NULL,
    `notes` TEXT NOT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_orders_ordered_on` (`ordered_on`),
    CONSTRAINT `fk_orders_customer` FOREIGN KEY (`customer_id`)
        REFERENCES `customers` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ON DELETE CASCADE here but RESTRICT above, deliberately: a line item has no
-- meaning without its order, while a customer with orders on file should not
-- disappear silently underneath them.
CREATE TABLE `order_lines` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `order_id` INT UNSIGNED NOT NULL,
    `sku` VARCHAR(32) NOT NULL,
    `description` VARCHAR(128) NOT NULL DEFAULT '',
    `qty` INT UNSIGNED NOT NULL,
    -- money as DECIMAL, never a float: 0.1 + 0.2 is not 0.3 in binary floating
    -- point, and a line total that is a cent out is worse than useless.
    `unit_price` DECIMAL(10,2) NOT NULL,
    `line_total` DECIMAL(10,2) NOT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_order_lines_order` (`order_id`),
    CONSTRAINT `fk_order_lines_order` FOREIGN KEY (`order_id`)
        REFERENCES `orders` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `customers` (`id`, `name`, `email`, `phone`) VALUES
    (1, 'Johnny Appleseed', 'johnny@example.com', '5551231234'),
    (2, 'Jenny Appleseed', 'jenny@example.com', '5554846768');

INSERT INTO `orders` (`id`, `customer_id`, `ordered_on`, `notes`) VALUES
    (1, 1, '2026-07-28', 'Leave at the side door.'),
    (2, 2, '2026-07-30', '');

INSERT INTO `order_lines` (`order_id`, `sku`, `description`, `qty`, `unit_price`, `line_total`) VALUES
    (1, 'APL-001', 'Apple seeds, 1lb bag', 3, 4.50, 13.50),
    (1, 'SPD-014', 'Garden spade', 1, 22.00, 22.00),
    (2, 'APL-001', 'Apple seeds, 1lb bag', 10, 4.50, 45.00);
