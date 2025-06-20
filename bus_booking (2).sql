-- phpMyAdmin SQL Dump - CORRECTED VERSION
-- Fixed AUTO_INCREMENT issues and optimized schema
-- Database: `bus_booking`

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

-- Create database if not exists
CREATE DATABASE IF NOT EXISTS `bus_booking` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
USE `bus_booking`;

DELIMITER $$

-- ============================================================================
-- STORED PROCEDURES
-- ============================================================================

CREATE DEFINER=`root`@`localhost` PROCEDURE `CreateGroupBooking` (
    IN `p_user_id` INT, 
    IN `p_bus_id` INT, 
    IN `p_booking_date` DATE, 
    IN `p_payment_method` VARCHAR(50), 
    IN `p_discount_type` VARCHAR(20), 
    IN `p_seat_numbers` TEXT, 
    IN `p_passenger_names` TEXT, 
    IN `p_passenger_contacts` TEXT, 
    OUT `p_group_booking_id` VARCHAR(100), 
    OUT `p_total_amount` DECIMAL(10,2)
) 
BEGIN
    DECLARE v_fare DECIMAL(10,2);
    DECLARE v_discount_rate DECIMAL(3,2) DEFAULT 0;
    DECLARE v_base_fare DECIMAL(10,2);
    DECLARE v_discount_amount DECIMAL(10,2);
    DECLARE v_final_fare DECIMAL(10,2);
    DECLARE v_seat_count INT;
    DECLARE v_counter INT DEFAULT 1;
    DECLARE v_seat_number INT;
    DECLARE v_passenger_name VARCHAR(255);
    DECLARE v_passenger_contact VARCHAR(20);
    DECLARE v_booking_ref VARCHAR(100);
    
    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        ROLLBACK;
        RESIGNAL;
    END;
    
    START TRANSACTION;
    
    -- Generate group booking ID
    SET p_group_booking_id = CONCAT('GRP-', DATE_FORMAT(NOW(), '%Y%m%d'), '-', UNIX_TIMESTAMP(), '-', p_user_id);
    
    -- Get fare from routes
    SELECT r.fare INTO v_fare
    FROM buses b
    JOIN routes r ON b.route_name LIKE CONCAT(r.origin, ' → ', r.destination)
    WHERE b.id = p_bus_id
    LIMIT 1;
    
    -- Calculate discount rate
    IF p_discount_type IN ('student', 'senior', 'pwd') THEN
        SET v_discount_rate = 0.20; -- 20% discount
    END IF;
    
    -- Calculate fare amounts
    SET v_base_fare = v_fare;
    SET v_discount_amount = v_base_fare * v_discount_rate;
    SET v_final_fare = v_base_fare - v_discount_amount;
    
    -- Count seats
    SET v_seat_count = (LENGTH(p_seat_numbers) - LENGTH(REPLACE(p_seat_numbers, ',', '')) + 1);
    
    -- Insert bookings for each seat
    WHILE v_counter <= v_seat_count DO
        -- Extract seat number
        SET v_seat_number = CAST(SUBSTRING_INDEX(SUBSTRING_INDEX(p_seat_numbers, ',', v_counter), ',', -1) AS UNSIGNED);
        
        -- Extract passenger name
        SET v_passenger_name = TRIM(SUBSTRING_INDEX(SUBSTRING_INDEX(p_passenger_names, '|', v_counter), '|', -1));
        
        -- Extract passenger contact
        SET v_passenger_contact = TRIM(SUBSTRING_INDEX(SUBSTRING_INDEX(p_passenger_contacts, '|', v_counter), '|', -1));
        
        -- Generate individual booking reference
        SET v_booking_ref = CONCAT('BK-', DATE_FORMAT(NOW(), '%Y%m%d'), '-', UNIX_TIMESTAMP(), '-', v_seat_number);
        
        -- Insert booking
        INSERT INTO bookings (
            bus_id, user_id, seat_number, booking_date, booking_status,
            booking_reference, group_booking_id, passenger_name, passenger_contact,
            payment_method, payment_status, discount_type,
            base_fare, discount_amount, final_fare, created_at
        ) VALUES (
            p_bus_id, p_user_id, v_seat_number, p_booking_date, 'confirmed',
            v_booking_ref, p_group_booking_id, v_passenger_name, v_passenger_contact,
            p_payment_method, 
            CASE WHEN p_payment_method = 'counter' THEN 'pending' ELSE 'awaiting_verification' END,
            p_discount_type, v_base_fare, v_discount_amount, v_final_fare, NOW()
        );
        
        SET v_counter = v_counter + 1;
    END WHILE;
    
    -- Calculate total amount
    SET p_total_amount = v_seat_count * v_final_fare;
    
    COMMIT;
END$$

CREATE DEFINER=`root`@`localhost` PROCEDURE `GetBusAvailability` (
    IN `p_bus_id` INT, 
    IN `p_booking_date` DATE
)   
BEGIN
    SELECT 
        b.id,
        b.seat_capacity,
        COUNT(bk.id) as booked_seats,
        (b.seat_capacity - COUNT(bk.id)) as available_seats,
        ROUND((COUNT(bk.id) / b.seat_capacity) * 100, 2) as occupancy_rate
    FROM buses b
    LEFT JOIN bookings bk ON b.id = bk.bus_id 
        AND bk.booking_date = p_booking_date 
        AND bk.booking_status = 'confirmed'
    WHERE b.id = p_bus_id
    GROUP BY b.id;
END$$

CREATE DEFINER=`root`@`localhost` PROCEDURE `ProcessGroupBooking` (
    IN `p_group_id` VARCHAR(50), 
    IN `p_user_id` INT, 
    IN `p_bus_id` INT, 
    IN `p_booking_date` DATE, 
    IN `p_payment_method` VARCHAR(50), 
    IN `p_tickets` JSON
)   
BEGIN
    DECLARE v_ticket_count INT;
    DECLARE v_total_amount DECIMAL(10,2) DEFAULT 0;
    DECLARE v_base_fare DECIMAL(10,2);
    DECLARE v_exit_handler_called BOOLEAN DEFAULT FALSE;
    
    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        SET v_exit_handler_called = TRUE;
        ROLLBACK;
        RESIGNAL;
    END;
    
    START TRANSACTION;
    
    -- Get base fare for the route
    SELECT r.fare INTO v_base_fare
    FROM buses b
    JOIN routes r ON b.route_name LIKE CONCAT(r.origin, ' → ', r.destination)
    WHERE b.id = p_bus_id
    LIMIT 1;
    
    -- Get ticket count
    SET v_ticket_count = JSON_LENGTH(p_tickets);
    
    -- Insert group booking record
    INSERT INTO booking_groups (
        group_id, user_id, bus_id, booking_date, 
        total_tickets, payment_method, group_status
    ) VALUES (
        p_group_id, p_user_id, p_bus_id, p_booking_date,
        v_ticket_count, p_payment_method, 'pending'
    );
    
    -- Process individual bookings
    SET @i = 0;
    WHILE @i < v_ticket_count DO
        SET @ticket = JSON_EXTRACT(p_tickets, CONCAT('$[', @i, ']'));
        SET @seat_number = JSON_UNQUOTE(JSON_EXTRACT(@ticket, '$.seat_number'));
        SET @passenger_name = JSON_UNQUOTE(JSON_EXTRACT(@ticket, '$.passenger_name'));
        SET @discount_type = JSON_UNQUOTE(JSON_EXTRACT(@ticket, '$.discount_type'));
        
        -- Calculate fare
        SET @final_fare = v_base_fare;
        SET @discount_amount = 0;
        
        IF @discount_type IN ('student', 'senior', 'pwd') THEN
            SET @discount_amount = v_base_fare * 0.2;
            SET @final_fare = v_base_fare * 0.8;
        END IF;
        
        -- Generate booking reference
        SET @booking_ref = CONCAT('BK-', DATE_FORMAT(NOW(), '%Y%m%d'), '-', LPAD(CONNECTION_ID(), 6, '0'), '-', @i + 1);
        
        -- Insert individual booking
        INSERT INTO bookings (
            bus_id, user_id, seat_number, passenger_name, booking_date,
            booking_status, group_booking_id, booking_reference,
            payment_method, payment_status, discount_type,
            base_fare, discount_amount, final_fare
        ) VALUES (
            p_bus_id, p_user_id, @seat_number, @passenger_name, p_booking_date,
            'confirmed', p_group_id, @booking_ref,
            p_payment_method, 'pending', @discount_type,
            v_base_fare, @discount_amount, @final_fare
        );
        
        SET v_total_amount = v_total_amount + @final_fare;
        SET @i = @i + 1;
    END WHILE;
    
    -- Update group booking totals
    UPDATE booking_groups 
    SET total_amount = v_total_amount,
        total_discount = (v_base_fare * v_ticket_count) - v_total_amount
    WHERE group_id = p_group_id;
    
    IF v_exit_handler_called = FALSE THEN
        COMMIT;
        SELECT 'SUCCESS' as status, p_group_id as group_id, v_total_amount as total_amount;
    END IF;
END$$

-- ============================================================================
-- FUNCTIONS
-- ============================================================================

CREATE DEFINER=`root`@`localhost` FUNCTION `CalculateFareWithDiscount` (
    `base_fare` DECIMAL(10,2), 
    `discount_type` VARCHAR(20)
) 
RETURNS DECIMAL(10,2) 
DETERMINISTIC 
READS SQL DATA 
BEGIN
    DECLARE final_fare DECIMAL(10,2);
    
    CASE discount_type
        WHEN 'student' THEN SET final_fare = base_fare * 0.8;
        WHEN 'senior' THEN SET final_fare = base_fare * 0.8;
        WHEN 'pwd' THEN SET final_fare = base_fare * 0.8;
        ELSE SET final_fare = base_fare;
    END CASE;
    
    RETURN final_fare;
END$$

CREATE DEFINER=`root`@`localhost` FUNCTION `CheckSeatsAvailability` (
    `p_bus_id` INT, 
    `p_date` DATE, 
    `p_seat_numbers` TEXT
) 
RETURNS TINYINT(1) 
DETERMINISTIC 
READS SQL DATA 
BEGIN
    DECLARE v_booked_count INT DEFAULT 0;
    
    -- Count how many of the requested seats are already booked
    SELECT COUNT(*) INTO v_booked_count
    FROM bookings
    WHERE bus_id = p_bus_id
      AND DATE(booking_date) = p_date
      AND booking_status = 'confirmed'
      AND FIND_IN_SET(seat_number, p_seat_numbers) > 0;
    
    -- Return TRUE if no seats are booked, FALSE otherwise
    RETURN v_booked_count = 0;
END$$

CREATE DEFINER=`root`@`localhost` FUNCTION `GetNextBookingReference` () 
RETURNS VARCHAR(20) 
CHARSET utf8mb4 COLLATE utf8mb4_general_ci 
DETERMINISTIC 
READS SQL DATA 
BEGIN
    DECLARE next_ref VARCHAR(20);
    DECLARE next_id INT;
    
    SELECT COALESCE(MAX(id), 0) + 1 INTO next_id FROM bookings;
    SET next_ref = CONCAT('BK-', DATE_FORMAT(NOW(), '%Y%m%d'), '-', LPAD(next_id, 6, '0'));
    
    RETURN next_ref;
END$$

CREATE DEFINER=`root`@`localhost` FUNCTION `IsSeatAvailable` (
    `p_bus_id` INT, 
    `p_seat_number` INT, 
    `p_booking_date` DATE
) 
RETURNS TINYINT(1) 
DETERMINISTIC 
READS SQL DATA 
BEGIN
    DECLARE seat_count INT DEFAULT 0;
    
    SELECT COUNT(*) INTO seat_count
    FROM bookings
    WHERE bus_id = p_bus_id
      AND seat_number = p_seat_number
      AND booking_date = p_booking_date
      AND booking_status = 'confirmed';
    
    RETURN seat_count = 0;
END$$

DELIMITER ;

-- ============================================================================
-- TABLES
-- ============================================================================

-- Table structure for table `users`
CREATE TABLE `users` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `first_name` varchar(50) NOT NULL,
  `last_name` varchar(50) NOT NULL,
  `gender` enum('male','female','other') NOT NULL,
  `birthdate` date NOT NULL,
  `contact_number` varchar(20) NOT NULL,
  `email` varchar(100) NOT NULL,
  `password` varchar(255) NOT NULL,
  `is_verified` tinyint(1) DEFAULT 0,
  `user_type` enum('customer','admin','staff') DEFAULT 'customer',
  `profile_image` varchar(255) DEFAULT NULL,
  `address` text DEFAULT NULL,
  `emergency_contact` varchar(100) DEFAULT NULL,
  `emergency_phone` varchar(20) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `last_login` datetime DEFAULT NULL,
  `status` enum('active','inactive','suspended') DEFAULT 'active',
  `full_name` varchar(101) GENERATED ALWAYS AS (concat(`first_name`,' ',`last_name`)) STORED,
  PRIMARY KEY (`id`),
  UNIQUE KEY `email` (`email`),
  KEY `idx_user_type` (`user_type`),
  KEY `idx_status` (`status`),
  KEY `idx_email_status` (`email`,`status`),
  KEY `idx_users_id` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=1000 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Table structure for table `routes`
CREATE TABLE `routes` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `origin` varchar(255) NOT NULL,
  `destination` varchar(255) NOT NULL,
  `distance` decimal(10,2) NOT NULL,
  `estimated_duration` varchar(50) NOT NULL,
  `fare` decimal(10,2) NOT NULL,
  `description` text DEFAULT NULL,
  `status` enum('active','inactive') DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_route` (`origin`,`destination`),
  KEY `idx_route_search` (`origin`,`destination`),
  KEY `idx_status` (`status`),
  KEY `idx_route_active` (`status`,`origin`,`destination`)
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Table structure for table `buses`
CREATE TABLE `buses` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `bus_type` varchar(50) NOT NULL,
  `seat_capacity` int(11) NOT NULL,
  `plate_number` varchar(20) NOT NULL,
  `driver_name` varchar(100) NOT NULL,
  `conductor_name` varchar(100) NOT NULL,
  `status` enum('Active','Inactive','Under Maintenance') DEFAULT 'Active',
  `route_id` int(11) DEFAULT NULL,
  `route_name` varchar(255) NOT NULL,
  `origin` varchar(100) DEFAULT NULL,
  `destination` varchar(100) DEFAULT NULL,
  `bus_model` varchar(100) DEFAULT NULL,
  `year_manufactured` year(4) DEFAULT NULL,
  `last_maintenance` date DEFAULT NULL,
  `next_maintenance` date DEFAULT NULL,
  `fuel_type` enum('diesel','gasoline','electric','hybrid') DEFAULT 'diesel',
  `wifi_available` tinyint(1) DEFAULT 0,
  `charging_ports` tinyint(1) DEFAULT 0,
  `wheelchair_accessible` tinyint(1) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `plate_number` (`plate_number`),
  KEY `status` (`status`),
  KEY `fk_bus_route` (`route_id`),
  KEY `idx_bus_type` (`bus_type`),
  KEY `idx_route_name` (`route_name`),
  KEY `idx_bus_status_type` (`status`,`bus_type`),
  KEY `idx_buses_id` (`id`),
  CONSTRAINT `fk_bus_route` FOREIGN KEY (`route_id`) REFERENCES `routes` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=100 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Table structure for table `schedules`
CREATE TABLE `schedules` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `bus_id` int(11) NOT NULL,
  `origin` varchar(255) NOT NULL,
  `destination` varchar(255) NOT NULL,
  `departure_time` time NOT NULL,
  `arrival_time` time NOT NULL,
  `fare_amount` decimal(10,2) NOT NULL,
  `recurring` tinyint(1) NOT NULL DEFAULT 0,
  `trip_number` varchar(20) DEFAULT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'active',
  `days_of_week` set('monday','tuesday','wednesday','thursday','friday','saturday','sunday') DEFAULT 'monday,tuesday,wednesday,thursday,friday,saturday,sunday',
  `effective_date` date DEFAULT NULL,
  `expiry_date` date DEFAULT NULL,
  `date` date DEFAULT NULL,
  `special_notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_bus_trip` (`bus_id`,`trip_number`),
  KEY `origin_destination` (`origin`,`destination`),
  KEY `idx_trip_number` (`trip_number`),
  KEY `idx_schedule_status` (`status`),
  KEY `idx_departure_time` (`departure_time`),
  KEY `idx_schedule_date` (`date`),
  KEY `idx_schedule_time` (`departure_time`,`arrival_time`),
  CONSTRAINT `fk_schedule_bus` FOREIGN KEY (`bus_id`) REFERENCES `buses` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Table structure for table `bookings`
CREATE TABLE `bookings` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `bus_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `seat_number` int(11) NOT NULL,
  `passenger_name` varchar(100) DEFAULT NULL,
  `booking_date` date NOT NULL,
  `booking_status` enum('pending','confirmed','cancelled','completed','no_show') NOT NULL DEFAULT 'pending',
  `group_booking_id` varchar(50) DEFAULT NULL,
  `booking_reference` varchar(20) DEFAULT NULL,
  `trip_number` varchar(50) DEFAULT NULL,
  `payment_method` varchar(50) DEFAULT NULL,
  `payment_status` varchar(20) DEFAULT 'pending',
  `payment_proof` varchar(255) DEFAULT NULL,
  `payment_proof_status` varchar(20) DEFAULT 'pending',
  `payment_proof_timestamp` datetime DEFAULT NULL,
  `discount_type` varchar(20) DEFAULT 'regular',
  `discount_id_proof` varchar(255) DEFAULT NULL,
  `discount_verified` tinyint(1) DEFAULT 0,
  `discount_verified_by` int(11) DEFAULT NULL,
  `discount_verified_at` datetime DEFAULT NULL,
  `base_fare` decimal(10,2) DEFAULT NULL,
  `discount_amount` decimal(10,2) DEFAULT 0.00,
  `final_fare` decimal(10,2) DEFAULT NULL,
  `cancel_reason` varchar(255) DEFAULT NULL,
  `cancelled_at` datetime DEFAULT NULL,
  `cancelled_by` int(11) DEFAULT NULL,
  `refund_status` enum('not_applicable','pending','processed','denied') DEFAULT 'not_applicable',
  `refund_amount` decimal(10,2) DEFAULT NULL,
  `refund_note` varchar(255) DEFAULT NULL,
  `refund_processed_by` int(11) DEFAULT NULL,
  `refund_processed_at` datetime DEFAULT NULL,
  `special_requests` text DEFAULT NULL,
  `check_in_time` datetime DEFAULT NULL,
  `boarding_status` enum('not_boarded','boarded','completed') DEFAULT 'not_boarded',
  `qr_code` varchar(255) DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `passenger_contact` varchar(20) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `bus_id` (`bus_id`),
  KEY `user_id` (`user_id`),
  KEY `idx_booking_reference` (`booking_reference`),
  KEY `idx_group_booking` (`group_booking_id`),
  KEY `idx_discount_type` (`discount_type`),
  KEY `idx_payment_status` (`payment_status`),
  KEY `idx_booking_date_status` (`booking_date`,`booking_status`),
  KEY `idx_passenger_name` (`passenger_name`),
  KEY `idx_discount_verification` (`discount_type`,`discount_verified`),
  KEY `idx_payment_verification` (`payment_method`,`payment_status`),
  KEY `fk_discount_verifier` (`discount_verified_by`),
  KEY `fk_cancelled_by` (`cancelled_by`),
  KEY `fk_refund_processor` (`refund_processed_by`),
  KEY `idx_bookings_bus_id` (`bus_id`),
  KEY `idx_bookings_user_id` (`user_id`),
  KEY `idx_group_booking_id` (`group_booking_id`),
  KEY `idx_bus_date` (`bus_id`,`booking_date`),
  KEY `idx_booking_status` (`booking_status`),
  KEY `idx_booking_date` (`booking_date`),
  CONSTRAINT `fk_booking_bus` FOREIGN KEY (`bus_id`) REFERENCES `buses` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_booking_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_cancelled_by` FOREIGN KEY (`cancelled_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_discount_verifier` FOREIGN KEY (`discount_verified_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_refund_processor` FOREIGN KEY (`refund_processed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Table structure for table `booking_groups`
CREATE TABLE `booking_groups` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `group_id` varchar(50) NOT NULL,
  `user_id` int(11) NOT NULL,
  `bus_id` int(11) NOT NULL,
  `booking_date` date NOT NULL,
  `total_tickets` int(11) NOT NULL DEFAULT 1,
  `total_amount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `total_discount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `payment_method` varchar(50) DEFAULT NULL,
  `payment_status` varchar(20) DEFAULT 'pending',
  `payment_proof` varchar(255) DEFAULT NULL,
  `payment_proof_status` varchar(20) DEFAULT 'pending',
  `payment_verified_by` int(11) DEFAULT NULL,
  `payment_verified_at` datetime DEFAULT NULL,
  `group_status` enum('pending','confirmed','cancelled','completed','refunded') DEFAULT 'pending',
  `booking_notes` text DEFAULT NULL,
  `cancellation_reason` text DEFAULT NULL,
  `cancelled_by` int(11) DEFAULT NULL,
  `cancelled_at` datetime DEFAULT NULL,
  `refund_amount` decimal(10,2) DEFAULT NULL,
  `refund_status` enum('pending','processed','denied') DEFAULT NULL,
  `refund_processed_by` int(11) DEFAULT NULL,
  `refund_processed_at` datetime DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `group_id` (`group_id`),
  KEY `idx_group_user` (`user_id`,`booking_date`),
  KEY `idx_group_bus` (`bus_id`,`booking_date`),
  KEY `idx_group_status` (`group_status`),
  KEY `idx_payment_status` (`payment_status`),
  KEY `fk_group_payment_verifier` (`payment_verified_by`),
  KEY `idx_group_booking_user` (`user_id`,`booking_date`),
  KEY `idx_group_booking_bus` (`bus_id`,`booking_date`),
  CONSTRAINT `fk_group_bus` FOREIGN KEY (`bus_id`) REFERENCES `buses` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_group_payment_verifier` FOREIGN KEY (`payment_verified_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_group_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Table structure for table `audit_logs`
CREATE TABLE `audit_logs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) DEFAULT NULL,
  `action` varchar(100) NOT NULL,
  `table_name` varchar(50) DEFAULT NULL,
  `record_id` int(11) DEFAULT NULL,
  `old_values` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`old_values`)),
  `new_values` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`new_values`)),
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` text DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `fk_audit_user` (`user_id`),
  KEY `idx_audit_action` (`action`),
  KEY `idx_audit_table` (`table_name`),
  KEY `idx_audit_created` (`created_at`),
  CONSTRAINT `fk_audit_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Table structure for table `contact_messages`
CREATE TABLE `contact_messages` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `email` varchar(100) NOT NULL,
  `subject` varchar(255) NOT NULL,
  `message` text NOT NULL,
  `priority` enum('low','normal','high','urgent') DEFAULT 'normal',
  `category` varchar(50) DEFAULT 'general',
  `status` enum('open','in_progress','resolved','closed') DEFAULT 'open',
  `assigned_to` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `is_read` tinyint(1) DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `fk_contact_user` (`user_id`),
  KEY `fk_contact_assigned` (`assigned_to`),
  KEY `idx_contact_status` (`status`),
  KEY `idx_contact_priority` (`priority`),
  CONSTRAINT `fk_contact_assigned` FOREIGN KEY (`assigned_to`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_contact_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Table structure for table `contact_replies`
CREATE TABLE `contact_replies` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `message_id` int(11) NOT NULL,
  `reply_message` text NOT NULL,
  `replied_by` int(11) NOT NULL,
  `replied_by_name` varchar(100) NOT NULL,
  `is_internal` tinyint(1) DEFAULT 0,
  `replied_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `fk_reply_message` (`message_id`),
  KEY `fk_reply_user` (`replied_by`),
  CONSTRAINT `fk_reply_message` FOREIGN KEY (`message_id`) REFERENCES `contact_messages` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_reply_user` FOREIGN KEY (`replied_by`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Table structure for table `discount_verifications`
CREATE TABLE `discount_verifications` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `booking_id` int(11) NOT NULL,
  `discount_type` varchar(20) NOT NULL,
  `id_proof_path` varchar(255) NOT NULL,
  `passenger_name` varchar(100) NOT NULL,
  `id_number` varchar(50) DEFAULT NULL,
  `institution_name` varchar(200) DEFAULT NULL,
  `verified_by` int(11) DEFAULT NULL,
  `verification_status` enum('pending','approved','rejected','requires_resubmission') DEFAULT 'pending',
  `verification_notes` text DEFAULT NULL,
  `rejection_reason` text DEFAULT NULL,
  `verified_at` datetime DEFAULT NULL,
  `resubmission_count` int(11) DEFAULT 0,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `fk_discount_booking` (`booking_id`),
  KEY `idx_verification_status` (`verification_status`),
  KEY `idx_discount_type` (`discount_type`),
  KEY `fk_discount_verified_by` (`verified_by`),
  CONSTRAINT `fk_discount_booking` FOREIGN KEY (`booking_id`) REFERENCES `bookings` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_discount_verified_by` FOREIGN KEY (`verified_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Table structure for table `payment_verifications`
CREATE TABLE `payment_verifications` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `booking_id` int(11) DEFAULT NULL,
  `group_booking_id` varchar(50) DEFAULT NULL,
  `payment_method` varchar(50) NOT NULL,
  `payment_proof_path` varchar(255) DEFAULT NULL,
  `amount_claimed` decimal(10,2) NOT NULL,
  `transaction_id` varchar(100) DEFAULT NULL,
  `payment_date` datetime DEFAULT NULL,
  `verified_by` int(11) DEFAULT NULL,
  `verification_status` enum('pending','approved','rejected','requires_resubmission') DEFAULT 'pending',
  `verification_notes` text DEFAULT NULL,
  `rejection_reason` text DEFAULT NULL,
  `verified_at` datetime DEFAULT NULL,
  `resubmission_count` int(11) DEFAULT 0,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `fk_payment_booking` (`booking_id`),
  KEY `idx_payment_verification_status` (`verification_status`),
  KEY `idx_payment_method` (`payment_method`),
  KEY `idx_group_booking_payment` (`group_booking_id`),
  KEY `fk_payment_verified_by` (`verified_by`),
  CONSTRAINT `fk_payment_booking` FOREIGN KEY (`booking_id`) REFERENCES `bookings` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_payment_verified_by` FOREIGN KEY (`verified_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Table structure for table `system_settings`
CREATE TABLE `system_settings` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `setting_key` varchar(100) NOT NULL,
  `setting_value` text DEFAULT NULL,
  `setting_type` enum('string','number','boolean','json','text') DEFAULT 'string',
  `description` text DEFAULT NULL,
  `is_public` tinyint(1) DEFAULT 0,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `setting_key` (`setting_key`)
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ============================================================================
-- TRIGGERS
-- ============================================================================

DELIMITER $

CREATE TRIGGER `calculate_final_fare_insert` BEFORE INSERT ON `bookings` FOR EACH ROW 
BEGIN
    IF NEW.base_fare IS NOT NULL THEN
        SET NEW.final_fare = NEW.base_fare - COALESCE(NEW.discount_amount, 0);
    END IF;
END$

CREATE TRIGGER `update_final_fare` BEFORE UPDATE ON `bookings` FOR EACH ROW 
BEGIN
    IF NEW.base_fare IS NOT NULL THEN
        SET NEW.final_fare = NEW.base_fare - COALESCE(NEW.discount_amount, 0);
    END IF;
END$

DELIMITER ;

-- ============================================================================
-- VIEWS
-- ============================================================================

-- View for booking details
CREATE VIEW `booking_details_view` AS 
SELECT 
    `b`.`id` AS `id`,
    `b`.`booking_reference` AS `booking_reference`,
    `b`.`passenger_name` AS `passenger_name`,
    `b`.`seat_number` AS `seat_number`,
    `b`.`booking_date` AS `booking_date`,
    `b`.`booking_status` AS `booking_status`,
    `b`.`group_booking_id` AS `group_booking_id`,
    `b`.`discount_type` AS `discount_type`,
    `b`.`discount_verified` AS `discount_verified`,
    `b`.`base_fare` AS `base_fare`,
    `b`.`discount_amount` AS `discount_amount`,
    `b`.`final_fare` AS `final_fare`,
    `b`.`payment_method` AS `payment_method`,
    `b`.`payment_status` AS `payment_status`,
    `b`.`boarding_status` AS `boarding_status`,
    `b`.`created_at` AS `created_at`,
    `u`.`first_name` AS `booker_first_name`,
    `u`.`last_name` AS `booker_last_name`,
    `u`.`email` AS `booker_email`,
    `u`.`contact_number` AS `booker_phone`,
    `bus`.`bus_type` AS `bus_type`,
    `bus`.`plate_number` AS `plate_number`,
    `bus`.`route_name` AS `route_name`,
    `bus`.`driver_name` AS `driver_name`,
    `bus`.`conductor_name` AS `conductor_name`,
    `s`.`departure_time` AS `departure_time`,
    `s`.`arrival_time` AS `arrival_time`,
    `s`.`trip_number` AS `trip_number`,
    `r`.`fare` AS `route_fare`,
    `r`.`origin` AS `origin`,
    `r`.`destination` AS `destination`,
    `r`.`distance` AS `distance`,
    `r`.`estimated_duration` AS `estimated_duration`,
    CASE 
        WHEN `b`.`discount_type` = 'student' THEN `r`.`fare` * 0.8 
        WHEN `b`.`discount_type` = 'senior' THEN `r`.`fare` * 0.8 
        WHEN `b`.`discount_type` = 'pwd' THEN `r`.`fare` * 0.8 
        ELSE `r`.`fare` 
    END AS `calculated_fare` 
FROM 
    ((((`bookings` `b` 
    LEFT JOIN `users` `u` ON(`b`.`user_id` = `u`.`id`)) 
    LEFT JOIN `buses` `bus` ON(`b`.`bus_id` = `bus`.`id`)) 
    LEFT JOIN `schedules` `s` ON(`b`.`bus_id` = `s`.`bus_id` AND `b`.`trip_number` = `s`.`trip_number`)) 
    LEFT JOIN `routes` `r` ON(`bus`.`route_name` LIKE CONCAT(`r`.`origin`,' → ',`r`.`destination`)));

-- View for bus utilization
CREATE VIEW `bus_utilization_view` AS 
SELECT 
    `b`.`id` AS `bus_id`,
    `b`.`plate_number` AS `plate_number`,
    `b`.`bus_type` AS `bus_type`,
    `b`.`seat_capacity` AS `seat_capacity`,
    `b`.`route_name` AS `route_name`,
    `b`.`status` AS `status`,
    CAST(`bk`.`booking_date` AS date) AS `travel_date`,
    COUNT(`bk`.`id`) AS `total_bookings`,
    COUNT(CASE WHEN `bk`.`booking_status` = 'confirmed' THEN 1 END) AS `confirmed_bookings`,
    COUNT(CASE WHEN `bk`.`booking_status` = 'cancelled' THEN 1 END) AS `cancelled_bookings`,
    ROUND(COUNT(CASE WHEN `bk`.`booking_status` = 'confirmed' THEN 1 END) / `b`.`seat_capacity` * 100, 2) AS `occupancy_rate`,
    SUM(CASE WHEN `bk`.`booking_status` = 'confirmed' THEN `bk`.`final_fare` ELSE 0 END) AS `total_revenue` 
FROM 
    (`buses` `b` 
    LEFT JOIN `bookings` `bk` ON(`b`.`id` = `bk`.`bus_id`)) 
GROUP BY 
    `b`.`id`, CAST(`bk`.`booking_date` AS date) 
ORDER BY 
    CAST(`bk`.`booking_date` AS date) DESC, 
    ROUND(COUNT(CASE WHEN `bk`.`booking_status` = 'confirmed' THEN 1 END) / `b`.`seat_capacity` * 100, 2) DESC;

-- View for group booking summary
CREATE VIEW `group_booking_summary` AS 
SELECT 
    `b`.`group_booking_id` AS `group_booking_id`,
    COUNT(`b`.`id`) AS `total_passengers`,
    SUM(`b`.`base_fare`) AS `total_base_fare`,
    SUM(`b`.`discount_amount`) AS `total_discount`,
    SUM(`b`.`final_fare`) AS `total_amount`,
    GROUP_CONCAT(CONCAT(`b`.`passenger_name`,' (Seat ',`b`.`seat_number`,')') SEPARATOR ', ') AS `passengers_list`,
    GROUP_CONCAT(DISTINCT `b`.`discount_type` SEPARATOR ',') AS `discount_types`,
    `b`.`booking_date` AS `booking_date`,
    `b`.`payment_method` AS `payment_method`,
    `b`.`payment_status` AS `payment_status`,
    `b`.`created_at` AS `created_at`,
    `u`.`first_name` AS `booker_first_name`,
    `u`.`last_name` AS `booker_last_name`,
    `u`.`email` AS `booker_email`,
    `bus`.`plate_number` AS `plate_number`,
    `bus`.`route_name` AS `route_name`,
    `s`.`departure_time` AS `departure_time`,
    `s`.`arrival_time` AS `arrival_time`,
    `s`.`trip_number` AS `trip_number` 
FROM 
    (((`bookings` `b` 
    LEFT JOIN `users` `u` ON(`b`.`user_id` = `u`.`id`)) 
    LEFT JOIN `buses` `bus` ON(`b`.`bus_id` = `bus`.`id`)) 
    LEFT JOIN `schedules` `s` ON(`b`.`bus_id` = `s`.`bus_id` AND `b`.`trip_number` = `s`.`trip_number`)) 
WHERE 
    `b`.`group_booking_id` IS NOT NULL 
GROUP BY 
    `b`.`group_booking_id` 
ORDER BY 
    `b`.`created_at` DESC;

-- View for revenue summary
CREATE VIEW `revenue_summary_view` AS 
SELECT 
    CAST(`b`.`booking_date` AS date) AS `revenue_date`,
    COUNT(`b`.`id`) AS `total_bookings`,
    COUNT(CASE WHEN `b`.`booking_status` = 'confirmed' THEN 1 END) AS `confirmed_bookings`,
    SUM(CASE WHEN `b`.`booking_status` = 'confirmed' THEN `b`.`final_fare` ELSE 0 END) AS `total_revenue`,
    SUM(CASE WHEN `b`.`booking_status` = 'confirmed' AND `b`.`discount_type` <> 'regular' THEN `b`.`discount_amount` ELSE 0 END) AS `total_discounts`,
    SUM(CASE WHEN `b`.`booking_status` = 'confirmed' AND `b`.`payment_method` = 'counter' THEN `b`.`final_fare` ELSE 0 END) AS `counter_revenue`,
    SUM(CASE WHEN `b`.`booking_status` = 'confirmed' AND `b`.`payment_method` = 'gcash' THEN `b`.`final_fare` ELSE 0 END) AS `gcash_revenue`,
    SUM(CASE WHEN `b`.`booking_status` = 'confirmed' AND `b`.`payment_method` = 'paymaya' THEN `b`.`final_fare` ELSE 0 END) AS `paymaya_revenue`,
    AVG(CASE WHEN `b`.`booking_status` = 'confirmed' THEN `b`.`final_fare` END) AS `average_fare` 
FROM 
    `bookings` AS `b` 
WHERE 
    `b`.`booking_date` >= CURDATE() - INTERVAL 30 DAY 
GROUP BY 
    CAST(`b`.`booking_date` AS date) 
ORDER BY 
    CAST(`b`.`booking_date` AS date) DESC;

-- View for group booking analytics
CREATE VIEW `group_booking_analytics` AS 
SELECT 
    CAST(`group_booking_summary`.`booking_date` AS date) AS `travel_date`,
    COUNT(DISTINCT `group_booking_summary`.`group_booking_id`) AS `total_group_bookings`,
    COUNT(0) AS `total_group_passengers`,
    AVG(`group_booking_summary`.`total_passengers`) AS `avg_group_size`,
    MAX(`group_booking_summary`.`total_passengers`) AS `largest_group_size`,
    SUM(`group_booking_summary`.`total_amount`) AS `total_group_revenue`,
    SUM(`group_booking_summary`.`total_discount`) AS `total_group_discounts`,
    `group_booking_summary`.`payment_method` AS `payment_method`,
    `group_booking_summary`.`payment_status` AS `payment_status`,
    `group_booking_summary`.`discount_types` AS `discount_type` 
FROM 
    `group_booking_summary` 
GROUP BY 
    CAST(`group_booking_summary`.`booking_date` AS date), 
    `group_booking_summary`.`payment_method`, 
    `group_booking_summary`.`payment_status`, 
    `group_booking_summary`.`discount_types`;

-- ============================================================================
-- SAMPLE DATA INSERTS
-- ============================================================================

-- Insert sample user (admin)
INSERT INTO `users` (`id`, `first_name`, `last_name`, `gender`, `birthdate`, `contact_number`, `email`, `password`, `is_verified`, `user_type`, `created_at`, `updated_at`) VALUES
(1000, 'larry', 'Biaco', 'male', '2000-06-03', '09123456789', 'larrydenverbiaco@gmail.com', '$2y$10$k2X.BNrWCLmRXuV20ZU72uRTRtkt9WNaUlEw/0x4Sf0aZKNzdNo6i', 1, 'customer', '2025-06-01 18:54:03', '2025-06-01 18:54:03');

-- Commit the transaction
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;