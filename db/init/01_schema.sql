SET NAMES utf8mb4;

CREATE TABLE `movie` (
  `id` integer PRIMARY KEY AUTO_INCREMENT,
  `title` varchar(200) NOT NULL,
  `duration_min` smallint NOT NULL COMMENT 'CHECK duration_min > 0',
  `age_rating` varchar(5) COMMENT '0+, 6+, 12+, 16+, 18+',
  `description` text,
  `created_at` datetime NOT NULL DEFAULT (now())
);

CREATE TABLE `genre` (
  `id` integer PRIMARY KEY AUTO_INCREMENT,
  `name` varchar(50) UNIQUE NOT NULL
);

CREATE TABLE `movie_genre` (
  `movie_id` integer NOT NULL,
  `genre_id` integer NOT NULL,
  PRIMARY KEY (`movie_id`, `genre_id`)
);

CREATE TABLE `hall` (
  `id` integer PRIMARY KEY AUTO_INCREMENT,
  `name` varchar(50) UNIQUE NOT NULL
);

CREATE TABLE `seat_type` (
  `id` integer PRIMARY KEY AUTO_INCREMENT,
  `name` varchar(50) UNIQUE NOT NULL COMMENT 'обычное, VIP, диван',
  `description` varchar(255)
);

CREATE TABLE `seat` (
  `id` integer PRIMARY KEY AUTO_INCREMENT,
  `hall_id` integer NOT NULL,
  `row_no` smallint NOT NULL COMMENT 'CHECK row_no > 0',
  `seat_no` smallint NOT NULL COMMENT 'CHECK seat_no > 0',
  `seat_type_id` integer NOT NULL
);

CREATE TABLE `screening` (
  `id` integer PRIMARY KEY AUTO_INCREMENT,
  `movie_id` integer NOT NULL,
  `hall_id` integer NOT NULL,
  `starts_at` datetime NOT NULL,
  `created_at` datetime NOT NULL DEFAULT (now())
);

CREATE TABLE `screening_price` (
  `screening_id` integer NOT NULL,
  `seat_type_id` integer NOT NULL,
  `price` decimal(10,2) NOT NULL COMMENT 'CHECK price >= 0',
  PRIMARY KEY (`screening_id`, `seat_type_id`)
);

CREATE TABLE `customer` (
  `id` integer PRIMARY KEY AUTO_INCREMENT,
  `full_name` varchar(150) NOT NULL,
  `email` varchar(150) UNIQUE NOT NULL,
  `phone` varchar(20),
  `created_at` datetime NOT NULL DEFAULT (now())
);

CREATE TABLE `ticket` (
  `id` integer PRIMARY KEY AUTO_INCREMENT,
  `screening_id` integer NOT NULL,
  `seat_id` integer NOT NULL,
  `hall_id` integer NOT NULL COMMENT 'дублирует зал сеанса и места, нужен для составных FK',
  `customer_id` integer NOT NULL,
  `price_paid` decimal(10,2) NOT NULL COMMENT 'цена на момент покупки, CHECK price_paid >= 0',
  `purchased_at` datetime NOT NULL DEFAULT (now())
);

CREATE INDEX `movie_index_0` ON `movie` (`title`);

CREATE INDEX `movie_genre_index_1` ON `movie_genre` (`genre_id`);

CREATE UNIQUE INDEX `seat_index_2` ON `seat` (`hall_id`, `row_no`, `seat_no`);

CREATE UNIQUE INDEX `seat_index_3` ON `seat` (`id`, `hall_id`);

CREATE INDEX `seat_index_4` ON `seat` (`seat_type_id`);

CREATE UNIQUE INDEX `screening_index_5` ON `screening` (`id`, `hall_id`);

CREATE UNIQUE INDEX `screening_index_6` ON `screening` (`hall_id`, `starts_at`);

CREATE INDEX `screening_index_7` ON `screening` (`movie_id`);

CREATE INDEX `screening_price_index_8` ON `screening_price` (`seat_type_id`);

CREATE UNIQUE INDEX `ticket_index_9` ON `ticket` (`screening_id`, `seat_id`);

CREATE INDEX `ticket_index_10` ON `ticket` (`seat_id`, `hall_id`);

CREATE INDEX `ticket_index_11` ON `ticket` (`customer_id`);

ALTER TABLE `movie_genre` ADD FOREIGN KEY (`movie_id`) REFERENCES `movie` (`id`) ON DELETE CASCADE;

ALTER TABLE `movie_genre` ADD FOREIGN KEY (`genre_id`) REFERENCES `genre` (`id`) ON DELETE CASCADE;

ALTER TABLE `seat` ADD FOREIGN KEY (`hall_id`) REFERENCES `hall` (`id`) ON DELETE CASCADE;

ALTER TABLE `seat` ADD FOREIGN KEY (`seat_type_id`) REFERENCES `seat_type` (`id`);

ALTER TABLE `screening` ADD FOREIGN KEY (`movie_id`) REFERENCES `movie` (`id`);

ALTER TABLE `screening` ADD FOREIGN KEY (`hall_id`) REFERENCES `hall` (`id`);

ALTER TABLE `screening_price` ADD FOREIGN KEY (`screening_id`) REFERENCES `screening` (`id`) ON DELETE CASCADE;

ALTER TABLE `screening_price` ADD FOREIGN KEY (`seat_type_id`) REFERENCES `seat_type` (`id`);

ALTER TABLE `ticket` ADD FOREIGN KEY (`screening_id`, `hall_id`) REFERENCES `screening` (`id`, `hall_id`);

ALTER TABLE `ticket` ADD FOREIGN KEY (`seat_id`, `hall_id`) REFERENCES `seat` (`id`, `hall_id`);

ALTER TABLE `ticket` ADD FOREIGN KEY (`customer_id`) REFERENCES `customer` (`id`);

ALTER TABLE `movie`           ADD CONSTRAINT `chk_movie_duration` CHECK (`duration_min` > 0);

ALTER TABLE `seat`            ADD CONSTRAINT `chk_seat_position` CHECK (`row_no` > 0 AND `seat_no` > 0);

ALTER TABLE `screening_price` ADD CONSTRAINT `chk_screening_price` CHECK (`price` >= 0);

ALTER TABLE `ticket`          ADD CONSTRAINT `chk_ticket_price` CHECK (`price_paid` >= 0);
