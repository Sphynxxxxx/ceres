-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Jun 02, 2025 at 08:34 AM
-- Server version: 10.4.32-MariaDB
-- PHP Version: 8.2.12

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `bus_booking`
--

DELIMITER $$
--
-- Procedures
--
CREATE DEFINER=`root`@`localhost` PROCEDURE `GetBusAvailability` (IN `p_bus_id` INT, IN `p_booking_date` DATE)   BEGIN
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

CREATE DEFINER=`root`@`localhost` PROCEDURE `ProcessGroupBooking` (IN `p_group_id` VARCHAR(50), IN `p_user_id` INT, IN `p_bus_id` INT, IN `p_booking_date` DATE, IN `p_payment_method` VARCHAR(50), IN `p_tickets` JSON)   BEGIN
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

--
-- Functions
--
CREATE DEFINER=`root`@`localhost` FUNCTION `CalculateFareWithDiscount` (`base_fare` DECIMAL(10,2), `discount_type` VARCHAR(20)) RETURNS DECIMAL(10,2) DETERMINISTIC READS SQL DATA BEGIN
    DECLARE final_fare DECIMAL(10,2);
    
    CASE discount_type
        WHEN 'student' THEN SET final_fare = base_fare * 0.8;
        WHEN 'senior' THEN SET final_fare = base_fare * 0.8;
        WHEN 'pwd' THEN SET final_fare = base_fare * 0.8;
        ELSE SET final_fare = base_fare;
    END CASE;
    
    RETURN final_fare;
END$$

CREATE DEFINER=`root`@`localhost` FUNCTION `GetNextBookingReference` () RETURNS VARCHAR(20) CHARSET utf8mb4 COLLATE utf8mb4_general_ci DETERMINISTIC READS SQL DATA BEGIN
    DECLARE next_ref VARCHAR(20);
    DECLARE next_id INT;
    
    SELECT COALESCE(MAX(id), 0) + 1 INTO next_id FROM bookings;
    SET next_ref = CONCAT('BK-', DATE_FORMAT(NOW(), '%Y%m%d'), '-', LPAD(next_id, 6, '0'));
    
    RETURN next_ref;
END$$

CREATE DEFINER=`root`@`localhost` FUNCTION `IsSeatAvailable` (`p_bus_id` INT, `p_seat_number` INT, `p_booking_date` DATE) RETURNS TINYINT(1) DETERMINISTIC READS SQL DATA BEGIN
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

-- --------------------------------------------------------

--
-- Table structure for table `audit_logs`
--

CREATE TABLE `audit_logs` (
  `id` int(11) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `action` varchar(100) NOT NULL,
  `table_name` varchar(50) DEFAULT NULL,
  `record_id` int(11) DEFAULT NULL,
  `old_values` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`old_values`)),
  `new_values` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`new_values`)),
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` text DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `bookings`
--

CREATE TABLE `bookings` (
  `id` int(11) NOT NULL,
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
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `bookings`
--

INSERT INTO `bookings` (`id`, `bus_id`, `user_id`, `seat_number`, `passenger_name`, `booking_date`, `booking_status`, `group_booking_id`, `booking_reference`, `trip_number`, `payment_method`, `payment_status`, `payment_proof`, `payment_proof_status`, `payment_proof_timestamp`, `discount_type`, `discount_id_proof`, `discount_verified`, `discount_verified_by`, `discount_verified_at`, `base_fare`, `discount_amount`, `final_fare`, `cancel_reason`, `cancelled_at`, `cancelled_by`, `refund_status`, `refund_amount`, `refund_note`, `refund_processed_by`, `refund_processed_at`, `special_requests`, `check_in_time`, `boarding_status`, `qr_code`, `created_at`, `updated_at`) VALUES
(10004, 100, 1000, 2, NULL, '2025-06-02', 'confirmed', NULL, 'BK-20250602-10004', '1st Trip', 'counter', 'pending', NULL, 'not_required', '2025-06-02 03:05:54', 'regular', NULL, 0, NULL, NULL, NULL, 0.00, NULL, NULL, NULL, NULL, 'not_applicable', NULL, NULL, NULL, NULL, NULL, NULL, 'not_boarded', NULL, '2025-06-02 09:05:54', '2025-06-02 09:05:54'),
(10005, 100, 1000, 1, NULL, '2025-06-02', 'confirmed', NULL, 'BK-20250602-10005', '1st Trip', 'gcash', 'awaiting_verificatio', 'uploads/payment_proofs/gcash_1748831272_683d0c280c987.jpg', 'uploaded', '2025-06-02 04:27:52', 'student', 'uploads/discount_ids/student_id_1748831272_683d0c280d330.jpg', 0, NULL, NULL, NULL, 0.00, NULL, NULL, NULL, NULL, 'not_applicable', NULL, NULL, NULL, NULL, NULL, NULL, 'not_boarded', NULL, '2025-06-02 10:27:52', '2025-06-02 10:27:52'),
(10006, 100, 1000, 3, NULL, '2025-06-02', 'confirmed', NULL, 'BK-20250602-10006', '1st Trip', 'gcash', 'awaiting_verificatio', 'uploads/payment_proofs/gcash_1748832350_683d105eb2bc6.jpg', 'uploaded', '2025-06-02 04:45:50', 'senior', 'uploads/discount_ids/senior_id_1748832350_683d105eb30bf.jpg', 0, NULL, NULL, NULL, 0.00, NULL, NULL, NULL, NULL, 'not_applicable', NULL, NULL, NULL, NULL, NULL, NULL, 'not_boarded', NULL, '2025-06-02 10:45:50', '2025-06-02 10:45:50'),
(10007, 100, 1000, 4, NULL, '2025-06-02', 'confirmed', NULL, 'BK-20250602-10007', '1st Trip', 'gcash', 'awaiting_verificatio', 'uploads/payment_proofs/gcash_1748833644_683d156c24134.jpg', 'uploaded', '2025-06-02 05:07:24', 'student', 'uploads/discount_ids/student_id_1748833644_683d156c245a6.jpg', 0, NULL, NULL, NULL, 0.00, NULL, NULL, NULL, NULL, 'not_applicable', NULL, NULL, NULL, NULL, NULL, NULL, 'not_boarded', NULL, '2025-06-02 11:07:24', '2025-06-02 11:07:24'),
(10008, 100, 1000, 5, NULL, '2025-06-02', 'confirmed', NULL, 'BK-20250602-10008', '1st Trip', 'gcash', 'awaiting_verificatio', 'uploads/payment_proofs/gcash_1748835465_683d1c890a92a.jpg', 'uploaded', '2025-06-02 05:37:45', 'student', 'uploads/discount_ids/student_id_1748835465_683d1c890ada2.jpg', 0, NULL, NULL, NULL, 0.00, NULL, NULL, NULL, NULL, 'not_applicable', NULL, NULL, NULL, NULL, NULL, NULL, 'not_boarded', NULL, '2025-06-02 11:37:45', '2025-06-02 11:37:45'),
(10009, 100, 1000, 6, NULL, '2025-06-02', 'confirmed', NULL, 'BK-20250602-10009', '1st Trip', 'gcash', 'awaiting_verificatio', 'uploads/payment_proofs/gcash_1748841441_683d33e16bb05.jpg', 'uploaded', '2025-06-02 07:17:21', 'student', 'uploads/discount_ids/student_id_1748841441_683d33e16bfce.jpg', 0, NULL, NULL, NULL, 0.00, NULL, NULL, NULL, NULL, 'not_applicable', NULL, NULL, NULL, NULL, NULL, NULL, 'not_boarded', NULL, '2025-06-02 13:17:21', '2025-06-02 13:17:21'),
(10010, 100, 1000, 7, NULL, '2025-06-02', 'confirmed', NULL, 'BK-20250602-10010', '1st Trip', 'gcash', 'awaiting_verificatio', 'uploads/payment_proofs/gcash_1748844248_683d3ed8aec0a.jpg', 'uploaded', '2025-06-02 08:04:08', 'student', 'uploads/discount_ids/student_id_1748844248_683d3ed8af09d.jpg', 0, NULL, NULL, NULL, 0.00, NULL, NULL, NULL, NULL, 'not_applicable', NULL, NULL, NULL, NULL, NULL, NULL, 'not_boarded', NULL, '2025-06-02 14:04:08', '2025-06-02 14:04:08');

-- --------------------------------------------------------

--
-- Stand-in structure for view `booking_details_view`
-- (See below for the actual view)
--
CREATE TABLE `booking_details_view` (
`id` int(11)
,`booking_reference` varchar(20)
,`passenger_name` varchar(100)
,`seat_number` int(11)
,`booking_date` date
,`booking_status` enum('pending','confirmed','cancelled','completed','no_show')
,`group_booking_id` varchar(50)
,`discount_type` varchar(20)
,`discount_verified` tinyint(1)
,`base_fare` decimal(10,2)
,`discount_amount` decimal(10,2)
,`final_fare` decimal(10,2)
,`payment_method` varchar(50)
,`payment_status` varchar(20)
,`boarding_status` enum('not_boarded','boarded','completed')
,`created_at` datetime
,`booker_first_name` varchar(50)
,`booker_last_name` varchar(50)
,`booker_email` varchar(100)
,`booker_phone` varchar(20)
,`bus_type` varchar(50)
,`plate_number` varchar(20)
,`route_name` varchar(255)
,`driver_name` varchar(100)
,`conductor_name` varchar(100)
,`departure_time` time
,`arrival_time` time
,`trip_number` varchar(20)
,`route_fare` decimal(10,2)
,`origin` varchar(255)
,`destination` varchar(255)
,`distance` decimal(10,2)
,`estimated_duration` varchar(50)
,`calculated_fare` decimal(12,3)
);

-- --------------------------------------------------------

--
-- Table structure for table `booking_groups`
--

CREATE TABLE `booking_groups` (
  `id` int(11) NOT NULL,
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
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ;

-- --------------------------------------------------------

--
-- Table structure for table `buses`
--

CREATE TABLE `buses` (
  `id` int(11) NOT NULL,
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
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ;

--
-- Dumping data for table `buses`
--

INSERT INTO `buses` (`id`, `bus_type`, `seat_capacity`, `plate_number`, `driver_name`, `conductor_name`, `status`, `route_id`, `route_name`, `origin`, `destination`, `bus_model`, `year_manufactured`, `last_maintenance`, `next_maintenance`, `fuel_type`, `wifi_available`, `charging_ports`, `wheelchair_accessible`, `created_at`, `updated_at`) VALUES
(1, 'Aircondition', 35, 'ABC-1234', 'Juan dela Cruz', 'Maria Santos', 'Active', NULL, 'ISAT-U Main Campus → SM City Iloilo', NULL, NULL, 'Nissan Civilian', '2020', NULL, NULL, 'diesel', 0, 0, 0, '2025-06-01 18:52:06', '2025-06-01 18:52:06'),
(100, 'Regular', 33, 'fa12121333', 'Jose', 'Maria', 'Active', 9, 'iloilo → roxas', 'iloilo', 'roxas', NULL, NULL, NULL, NULL, 'diesel', 0, 0, 0, '2025-06-01 20:39:59', '2025-06-01 20:39:59');

-- --------------------------------------------------------

--
-- Stand-in structure for view `bus_utilization_view`
-- (See below for the actual view)
--
CREATE TABLE `bus_utilization_view` (
`bus_id` int(11)
,`plate_number` varchar(20)
,`bus_type` varchar(50)
,`seat_capacity` int(11)
,`route_name` varchar(255)
,`status` enum('Active','Inactive','Under Maintenance')
,`travel_date` date
,`total_bookings` bigint(21)
,`confirmed_bookings` bigint(21)
,`cancelled_bookings` bigint(21)
,`occupancy_rate` decimal(26,2)
,`total_revenue` decimal(32,2)
);

-- --------------------------------------------------------

--
-- Table structure for table `contact_messages`
--

CREATE TABLE `contact_messages` (
  `id` int(11) NOT NULL,
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
  `is_read` tinyint(1) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `contact_replies`
--

CREATE TABLE `contact_replies` (
  `id` int(11) NOT NULL,
  `message_id` int(11) NOT NULL,
  `reply_message` text NOT NULL,
  `replied_by` int(11) NOT NULL,
  `replied_by_name` varchar(100) NOT NULL,
  `is_internal` tinyint(1) DEFAULT 0,
  `replied_at` datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `discount_verifications`
--

CREATE TABLE `discount_verifications` (
  `id` int(11) NOT NULL,
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
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `payment_verifications`
--

CREATE TABLE `payment_verifications` (
  `id` int(11) NOT NULL,
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
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Stand-in structure for view `revenue_summary_view`
-- (See below for the actual view)
--
CREATE TABLE `revenue_summary_view` (
`revenue_date` date
,`total_bookings` bigint(21)
,`confirmed_bookings` bigint(21)
,`total_revenue` decimal(32,2)
,`total_discounts` decimal(32,2)
,`counter_revenue` decimal(32,2)
,`gcash_revenue` decimal(32,2)
,`paymaya_revenue` decimal(32,2)
,`average_fare` decimal(14,6)
);

-- --------------------------------------------------------

--
-- Table structure for table `routes`
--

CREATE TABLE `routes` (
  `id` int(11) NOT NULL,
  `origin` varchar(255) NOT NULL,
  `destination` varchar(255) NOT NULL,
  `distance` decimal(10,2) NOT NULL,
  `estimated_duration` varchar(50) NOT NULL,
  `fare` decimal(10,2) NOT NULL,
  `description` text DEFAULT NULL,
  `status` enum('active','inactive') DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ;

--
-- Dumping data for table `routes`
--

INSERT INTO `routes` (`id`, `origin`, `destination`, `distance`, `estimated_duration`, `fare`, `description`, `status`, `created_at`, `updated_at`) VALUES
(2, 'ISAT-U Main Campus', 'Iloilo International Airport', 25.00, '45 minutes', 35.00, NULL, 'active', '2025-06-01 18:52:06', '2025-06-01 18:52:06'),
(9, 'iloilo', 'roxas', 95.00, '2h 30m', 175.75, NULL, 'active', '2025-06-01 20:38:58', '2025-06-01 20:38:58');

-- --------------------------------------------------------

--
-- Table structure for table `schedules`
--

CREATE TABLE `schedules` (
  `id` int(11) NOT NULL,
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
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `schedules`
--

INSERT INTO `schedules` (`id`, `bus_id`, `origin`, `destination`, `departure_time`, `arrival_time`, `fare_amount`, `recurring`, `trip_number`, `status`, `days_of_week`, `effective_date`, `expiry_date`, `date`, `special_notes`, `created_at`, `updated_at`) VALUES
(14, 100, 'iloilo', 'roxas', '08:08:00', '10:38:00', 176.00, 1, '1st Trip', 'active', 'monday,tuesday,wednesday,thursday,friday,saturday,sunday', NULL, NULL, NULL, NULL, '2025-06-01 20:40:19', '2025-06-01 20:40:30');

-- --------------------------------------------------------

--
-- Table structure for table `system_settings`
--

CREATE TABLE `system_settings` (
  `id` int(11) NOT NULL,
  `setting_key` varchar(100) NOT NULL,
  `setting_value` text DEFAULT NULL,
  `setting_type` enum('string','number','boolean','json','text') DEFAULT 'string',
  `description` text DEFAULT NULL,
  `is_public` tinyint(1) DEFAULT 0,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `system_settings`
--

INSERT INTO `system_settings` (`id`, `setting_key`, `setting_value`, `setting_type`, `description`, `is_public`, `created_at`, `updated_at`) VALUES
(1, 'site_name', 'ISAT-U Ceres Bus Ticket System', 'string', 'Name of the booking system', 1, '2025-06-01 18:52:06', '2025-06-01 18:52:06'),
(2, 'site_email', 'admin@isatuceresbus.com', 'string', 'System administrator email', 1, '2025-06-01 18:52:06', '2025-06-01 18:52:06'),
(3, 'booking_advance_days', '30', 'number', 'Maximum days in advance for booking', 1, '2025-06-01 18:52:06', '2025-06-01 18:52:06'),
(4, 'cancellation_hours', '24', 'number', 'Hours before departure for free cancellation', 1, '2025-06-01 18:52:06', '2025-06-01 18:52:06'),
(5, 'student_discount_rate', '0.20', 'number', 'Student discount rate (20%)', 0, '2025-06-01 18:52:06', '2025-06-01 18:52:06'),
(6, 'senior_discount_rate', '0.20', 'number', 'Senior citizen discount rate (20%)', 0, '2025-06-01 18:52:06', '2025-06-01 18:52:06'),
(7, 'pwd_discount_rate', '0.20', 'number', 'PWD discount rate (20%)', 0, '2025-06-01 18:52:06', '2025-06-01 18:52:06'),
(8, 'payment_verification_required', 'true', 'boolean', 'Whether online payments require verification', 0, '2025-06-01 18:52:06', '2025-06-01 18:52:06'),
(9, 'discount_verification_required', 'true', 'boolean', 'Whether discounts require ID verification', 0, '2025-06-01 18:52:06', '2025-06-01 18:52:06'),
(10, 'max_seats_per_booking', '10', 'number', 'Maximum seats per single booking', 1, '2025-06-01 18:52:06', '2025-06-01 18:52:06'),
(11, 'booking_cutoff_minutes', '30', 'number', 'Minutes before departure to stop bookings', 1, '2025-06-01 18:52:06', '2025-06-01 18:52:06');

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int(11) NOT NULL,
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
  `full_name` varchar(101) GENERATED ALWAYS AS (concat(`first_name`,' ',`last_name`)) STORED
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `first_name`, `last_name`, `gender`, `birthdate`, `contact_number`, `email`, `password`, `is_verified`, `user_type`, `profile_image`, `address`, `emergency_contact`, `emergency_phone`, `created_at`, `updated_at`, `last_login`, `status`) VALUES
(1000, 'larry', 'Biaco', 'male', '2000-06-03', '09123456789', 'larrydenverbiaco@gmail.com', '$2y$10$k2X.BNrWCLmRXuV20ZU72uRTRtkt9WNaUlEw/0x4Sf0aZKNzdNo6i', 1, 'customer', NULL, NULL, NULL, NULL, '2025-06-01 18:54:03', '2025-06-01 18:54:03', NULL, 'active');

-- --------------------------------------------------------

--
-- Structure for view `booking_details_view`
--
DROP TABLE IF EXISTS `booking_details_view`;

CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `booking_details_view`  AS SELECT `b`.`id` AS `id`, `b`.`booking_reference` AS `booking_reference`, `b`.`passenger_name` AS `passenger_name`, `b`.`seat_number` AS `seat_number`, `b`.`booking_date` AS `booking_date`, `b`.`booking_status` AS `booking_status`, `b`.`group_booking_id` AS `group_booking_id`, `b`.`discount_type` AS `discount_type`, `b`.`discount_verified` AS `discount_verified`, `b`.`base_fare` AS `base_fare`, `b`.`discount_amount` AS `discount_amount`, `b`.`final_fare` AS `final_fare`, `b`.`payment_method` AS `payment_method`, `b`.`payment_status` AS `payment_status`, `b`.`boarding_status` AS `boarding_status`, `b`.`created_at` AS `created_at`, `u`.`first_name` AS `booker_first_name`, `u`.`last_name` AS `booker_last_name`, `u`.`email` AS `booker_email`, `u`.`contact_number` AS `booker_phone`, `bus`.`bus_type` AS `bus_type`, `bus`.`plate_number` AS `plate_number`, `bus`.`route_name` AS `route_name`, `bus`.`driver_name` AS `driver_name`, `bus`.`conductor_name` AS `conductor_name`, `s`.`departure_time` AS `departure_time`, `s`.`arrival_time` AS `arrival_time`, `s`.`trip_number` AS `trip_number`, `r`.`fare` AS `route_fare`, `r`.`origin` AS `origin`, `r`.`destination` AS `destination`, `r`.`distance` AS `distance`, `r`.`estimated_duration` AS `estimated_duration`, CASE WHEN `b`.`discount_type` = 'student' THEN `r`.`fare`* 0.8 WHEN `b`.`discount_type` = 'senior' THEN `r`.`fare`* 0.8 WHEN `b`.`discount_type` = 'pwd' THEN `r`.`fare`* 0.8 ELSE `r`.`fare` END AS `calculated_fare` FROM ((((`bookings` `b` left join `users` `u` on(`b`.`user_id` = `u`.`id`)) left join `buses` `bus` on(`b`.`bus_id` = `bus`.`id`)) left join `schedules` `s` on(`b`.`bus_id` = `s`.`bus_id` and `b`.`trip_number` = `s`.`trip_number`)) left join `routes` `r` on(`bus`.`route_name` like concat(`r`.`origin`,' → ',`r`.`destination`))) ;

-- --------------------------------------------------------

--
-- Structure for view `bus_utilization_view`
--
DROP TABLE IF EXISTS `bus_utilization_view`;

CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `bus_utilization_view`  AS SELECT `b`.`id` AS `bus_id`, `b`.`plate_number` AS `plate_number`, `b`.`bus_type` AS `bus_type`, `b`.`seat_capacity` AS `seat_capacity`, `b`.`route_name` AS `route_name`, `b`.`status` AS `status`, cast(`bk`.`booking_date` as date) AS `travel_date`, count(`bk`.`id`) AS `total_bookings`, count(case when `bk`.`booking_status` = 'confirmed' then 1 end) AS `confirmed_bookings`, count(case when `bk`.`booking_status` = 'cancelled' then 1 end) AS `cancelled_bookings`, round(count(case when `bk`.`booking_status` = 'confirmed' then 1 end) / `b`.`seat_capacity` * 100,2) AS `occupancy_rate`, sum(case when `bk`.`booking_status` = 'confirmed' then `bk`.`final_fare` else 0 end) AS `total_revenue` FROM (`buses` `b` left join `bookings` `bk` on(`b`.`id` = `bk`.`bus_id`)) GROUP BY `b`.`id`, cast(`bk`.`booking_date` as date) ORDER BY cast(`bk`.`booking_date` as date) DESC, round(count(case when `bk`.`booking_status` = 'confirmed' then 1 end) / `b`.`seat_capacity` * 100,2) DESC ;

-- --------------------------------------------------------

--
-- Structure for view `revenue_summary_view`
--
DROP TABLE IF EXISTS `revenue_summary_view`;

CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `revenue_summary_view`  AS SELECT cast(`b`.`booking_date` as date) AS `revenue_date`, count(`b`.`id`) AS `total_bookings`, count(case when `b`.`booking_status` = 'confirmed' then 1 end) AS `confirmed_bookings`, sum(case when `b`.`booking_status` = 'confirmed' then `b`.`final_fare` else 0 end) AS `total_revenue`, sum(case when `b`.`booking_status` = 'confirmed' and `b`.`discount_type` <> 'regular' then `b`.`discount_amount` else 0 end) AS `total_discounts`, sum(case when `b`.`booking_status` = 'confirmed' and `b`.`payment_method` = 'counter' then `b`.`final_fare` else 0 end) AS `counter_revenue`, sum(case when `b`.`booking_status` = 'confirmed' and `b`.`payment_method` = 'gcash' then `b`.`final_fare` else 0 end) AS `gcash_revenue`, sum(case when `b`.`booking_status` = 'confirmed' and `b`.`payment_method` = 'paymaya' then `b`.`final_fare` else 0 end) AS `paymaya_revenue`, avg(case when `b`.`booking_status` = 'confirmed' then `b`.`final_fare` end) AS `average_fare` FROM `bookings` AS `b` WHERE `b`.`booking_date` >= curdate() - interval 30 day GROUP BY cast(`b`.`booking_date` as date) ORDER BY cast(`b`.`booking_date` as date) DESC ;

--
-- Indexes for dumped tables
--

--
-- Indexes for table `audit_logs`
--
ALTER TABLE `audit_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_audit_user` (`user_id`),
  ADD KEY `idx_audit_action` (`action`),
  ADD KEY `idx_audit_table` (`table_name`),
  ADD KEY `idx_audit_created` (`created_at`);

--
-- Indexes for table `bookings`
--
ALTER TABLE `bookings`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `prevent_duplicate_confirmed` (`bus_id`,`seat_number`,`booking_date`,`booking_status`),
  ADD UNIQUE KEY `booking_reference` (`booking_reference`),
  ADD KEY `bus_id` (`bus_id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `idx_booking_reference` (`booking_reference`),
  ADD KEY `idx_group_booking` (`group_booking_id`),
  ADD KEY `idx_discount_type` (`discount_type`),
  ADD KEY `idx_payment_status` (`payment_status`),
  ADD KEY `idx_booking_date_status` (`booking_date`,`booking_status`),
  ADD KEY `idx_passenger_name` (`passenger_name`),
  ADD KEY `idx_discount_verification` (`discount_type`,`discount_verified`),
  ADD KEY `idx_payment_verification` (`payment_method`,`payment_status`),
  ADD KEY `fk_discount_verifier` (`discount_verified_by`),
  ADD KEY `fk_cancelled_by` (`cancelled_by`),
  ADD KEY `fk_refund_processor` (`refund_processed_by`),
  ADD KEY `idx_bookings_bus_id` (`bus_id`),
  ADD KEY `idx_bookings_user_id` (`user_id`);

--
-- Indexes for table `booking_groups`
--
ALTER TABLE `booking_groups`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `group_id` (`group_id`),
  ADD KEY `idx_group_user` (`user_id`,`booking_date`),
  ADD KEY `idx_group_bus` (`bus_id`,`booking_date`),
  ADD KEY `idx_group_status` (`group_status`),
  ADD KEY `idx_payment_status` (`payment_status`),
  ADD KEY `fk_group_payment_verifier` (`payment_verified_by`),
  ADD KEY `idx_group_booking_user` (`user_id`,`booking_date`),
  ADD KEY `idx_group_booking_bus` (`bus_id`,`booking_date`);

--
-- Indexes for table `buses`
--
ALTER TABLE `buses`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `plate_number` (`plate_number`),
  ADD KEY `status` (`status`),
  ADD KEY `fk_bus_route` (`route_id`),
  ADD KEY `idx_bus_type` (`bus_type`),
  ADD KEY `idx_route_name` (`route_name`),
  ADD KEY `idx_bus_status_type` (`status`,`bus_type`),
  ADD KEY `idx_buses_id` (`id`);

--
-- Indexes for table `contact_messages`
--
ALTER TABLE `contact_messages`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_contact_user` (`user_id`),
  ADD KEY `fk_contact_assigned` (`assigned_to`),
  ADD KEY `idx_contact_status` (`status`),
  ADD KEY `idx_contact_priority` (`priority`);

--
-- Indexes for table `contact_replies`
--
ALTER TABLE `contact_replies`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_reply_message` (`message_id`),
  ADD KEY `fk_reply_user` (`replied_by`);

--
-- Indexes for table `discount_verifications`
--
ALTER TABLE `discount_verifications`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_discount_booking` (`booking_id`),
  ADD KEY `idx_verification_status` (`verification_status`),
  ADD KEY `idx_discount_type` (`discount_type`),
  ADD KEY `fk_discount_verified_by` (`verified_by`);

--
-- Indexes for table `payment_verifications`
--
ALTER TABLE `payment_verifications`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_payment_booking` (`booking_id`),
  ADD KEY `idx_payment_verification_status` (`verification_status`),
  ADD KEY `idx_payment_method` (`payment_method`),
  ADD KEY `idx_group_booking_payment` (`group_booking_id`),
  ADD KEY `fk_payment_verified_by` (`verified_by`);

--
-- Indexes for table `routes`
--
ALTER TABLE `routes`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_route` (`origin`,`destination`),
  ADD KEY `idx_route_search` (`origin`,`destination`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `idx_route_active` (`status`,`origin`,`destination`);

--
-- Indexes for table `schedules`
--
ALTER TABLE `schedules`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_bus_trip` (`bus_id`,`trip_number`),
  ADD KEY `origin_destination` (`origin`,`destination`),
  ADD KEY `idx_trip_number` (`trip_number`),
  ADD KEY `idx_schedule_status` (`status`),
  ADD KEY `idx_departure_time` (`departure_time`),
  ADD KEY `idx_schedule_date` (`date`),
  ADD KEY `idx_schedule_time` (`departure_time`,`arrival_time`);

--
-- Indexes for table `system_settings`
--
ALTER TABLE `system_settings`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `setting_key` (`setting_key`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `email` (`email`),
  ADD KEY `idx_user_type` (`user_type`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `idx_email_status` (`email`,`status`),
  ADD KEY `idx_users_id` (`id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `audit_logs`
--
ALTER TABLE `audit_logs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `bookings`
--
ALTER TABLE `bookings`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=10011;

--
-- AUTO_INCREMENT for table `booking_groups`
--
ALTER TABLE `booking_groups`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `buses`
--
ALTER TABLE `buses`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `contact_messages`
--
ALTER TABLE `contact_messages`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `contact_replies`
--
ALTER TABLE `contact_replies`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `discount_verifications`
--
ALTER TABLE `discount_verifications`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `payment_verifications`
--
ALTER TABLE `payment_verifications`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `routes`
--
ALTER TABLE `routes`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `schedules`
--
ALTER TABLE `schedules`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=16;

--
-- AUTO_INCREMENT for table `system_settings`
--
ALTER TABLE `system_settings`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=12;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=1001;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `audit_logs`
--
ALTER TABLE `audit_logs`
  ADD CONSTRAINT `fk_audit_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `bookings`
--
ALTER TABLE `bookings`
  ADD CONSTRAINT `fk_booking_bus` FOREIGN KEY (`bus_id`) REFERENCES `buses` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_booking_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_cancelled_by` FOREIGN KEY (`cancelled_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_discount_verifier` FOREIGN KEY (`discount_verified_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_refund_processor` FOREIGN KEY (`refund_processed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `booking_groups`
--
ALTER TABLE `booking_groups`
  ADD CONSTRAINT `fk_group_bus` FOREIGN KEY (`bus_id`) REFERENCES `buses` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_group_payment_verifier` FOREIGN KEY (`payment_verified_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_group_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `buses`
--
ALTER TABLE `buses`
  ADD CONSTRAINT `fk_bus_route` FOREIGN KEY (`route_id`) REFERENCES `routes` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `contact_messages`
--
ALTER TABLE `contact_messages`
  ADD CONSTRAINT `fk_contact_assigned` FOREIGN KEY (`assigned_to`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_contact_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `contact_replies`
--
ALTER TABLE `contact_replies`
  ADD CONSTRAINT `fk_reply_message` FOREIGN KEY (`message_id`) REFERENCES `contact_messages` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_reply_user` FOREIGN KEY (`replied_by`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `discount_verifications`
--
ALTER TABLE `discount_verifications`
  ADD CONSTRAINT `fk_discount_booking` FOREIGN KEY (`booking_id`) REFERENCES `bookings` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_discount_verified_by` FOREIGN KEY (`verified_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `payment_verifications`
--
ALTER TABLE `payment_verifications`
  ADD CONSTRAINT `fk_payment_booking` FOREIGN KEY (`booking_id`) REFERENCES `bookings` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_payment_verified_by` FOREIGN KEY (`verified_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `schedules`
--
ALTER TABLE `schedules`
  ADD CONSTRAINT `fk_schedule_bus` FOREIGN KEY (`bus_id`) REFERENCES `buses` (`id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
