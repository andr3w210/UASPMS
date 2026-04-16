CREATE DATABASE IF NOT EXISTS `uaspms_tripdb`
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE `uaspms_tripdb`;

CREATE TABLE IF NOT EXISTS `trip_vehicles` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `plate_no` varchar(50) NOT NULL,
  `vehicle_name` varchar(150) NOT NULL,
  `vehicle_type` varchar(100) DEFAULT NULL,
  `fuel_type` varchar(30) NOT NULL DEFAULT 'Diesel',
  `capacity_liters` decimal(10,2) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_by` int(10) unsigned DEFAULT NULL,
  `updated_by` int(10) unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_trip_vehicles_plate_no` (`plate_no`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `trip_tickets` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `trip_ticket_no` varchar(30) NOT NULL,
  `ris_no` varchar(40) DEFAULT NULL,
  `departure_date` date NOT NULL,
  `return_date` date DEFAULT NULL,
  `departure_time` time NOT NULL,
  `vehicle_id` int(10) unsigned NOT NULL,
  `vehicle_plate_no` varchar(50) NOT NULL,
  `vehicle_name` varchar(150) NOT NULL,
  `vehicle_type` varchar(100) DEFAULT NULL,
  `fuel_type` varchar(30) NOT NULL DEFAULT 'Diesel',
  `driver_employee_id` int(10) unsigned NOT NULL,
  `driver_name` varchar(200) NOT NULL,
  `driver_position_title` varchar(200) DEFAULT NULL,
  `office_id` int(10) unsigned DEFAULT NULL,
  `office_name` varchar(200) DEFAULT NULL,
  `responsibility_code_id` int(10) unsigned DEFAULT NULL,
  `responsibility_code` varchar(100) DEFAULT NULL,
  `destination` text NOT NULL,
  `purpose` text NOT NULL,
  `liters_requested` decimal(10,2) NOT NULL DEFAULT 0.00,
  `approved_by_name` varchar(200) DEFAULT NULL,
  `approved_by_title` varchar(200) DEFAULT NULL,
  `issued_by_name` varchar(200) DEFAULT NULL,
  `issued_by_title` varchar(200) DEFAULT NULL,
  `requested_by_name` varchar(200) DEFAULT NULL,
  `requested_by_title` varchar(200) DEFAULT NULL,
  `received_by_name` varchar(200) DEFAULT NULL,
  `received_by_title` varchar(200) DEFAULT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'scheduled',
  `arrival_time` time DEFAULT NULL,
  `return_departure_time` time DEFAULT NULL,
  `return_arrival_time` time DEFAULT NULL,
  `odometer_start` decimal(10,2) DEFAULT NULL,
  `odometer_end` decimal(10,2) DEFAULT NULL,
  `distance_traveled` decimal(10,2) DEFAULT NULL,
  `fuel_purchased` decimal(10,2) NOT NULL DEFAULT 0.00,
  `fuel_consumed` decimal(10,2) DEFAULT NULL,
  `oil_used` decimal(10,2) NOT NULL DEFAULT 0.00,
  `grease_used` decimal(10,2) NOT NULL DEFAULT 0.00,
  `completion_remarks` text DEFAULT NULL,
  `completed_at` datetime DEFAULT NULL,
  `completed_by` int(10) unsigned DEFAULT NULL,
  `remarks` text DEFAULT NULL,
  `created_by` int(10) unsigned DEFAULT NULL,
  `updated_by` int(10) unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_trip_tickets_trip_ticket_no` (`trip_ticket_no`),
  UNIQUE KEY `uk_trip_tickets_ris_no` (`ris_no`),
  KEY `idx_trip_tickets_departure_date` (`departure_date`),
  KEY `idx_trip_tickets_vehicle_id` (`vehicle_id`),
  CONSTRAINT `fk_trip_tickets_vehicle_id`
    FOREIGN KEY (`vehicle_id`) REFERENCES `trip_vehicles` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `trip_ticket_passengers` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `trip_ticket_id` int(10) unsigned NOT NULL,
  `passenger_name` varchar(200) NOT NULL,
  `sort_order` int(10) unsigned NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  KEY `idx_trip_ticket_passengers_ticket_id` (`trip_ticket_id`),
  CONSTRAINT `fk_trip_ticket_passengers_ticket_id`
    FOREIGN KEY (`trip_ticket_id`) REFERENCES `trip_tickets` (`id`)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
