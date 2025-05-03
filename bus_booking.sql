-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: May 03, 2025 at 08:14 PM
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

-- --------------------------------------------------------

--
-- Table structure for table `bookings`
--

CREATE TABLE `bookings` (
  `id` int(11) NOT NULL,
  `bus_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `seat_number` int(11) NOT NULL,
  `booking_date` date NOT NULL,
  `booking_status` enum('pending','confirmed','cancelled') NOT NULL DEFAULT 'pending',
  `cancel_reason` varchar(255) DEFAULT NULL,
  `cancelled_at` datetime DEFAULT NULL,
  `refund_status` enum('pending','processed','denied') DEFAULT NULL,
  `refund_amount` decimal(10,2) DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `booking_reference` varchar(20) DEFAULT NULL,
  `trip_number` varchar(50) DEFAULT NULL,
  `payment_proof` varchar(255) DEFAULT NULL,
  `payment_proof_status` varchar(20) DEFAULT 'pending',
  `payment_proof_timestamp` datetime DEFAULT NULL,
  `payment_method` varchar(50) DEFAULT NULL,
  `payment_status` varchar(20) DEFAULT 'pending',
  `discount_type` varchar(20) DEFAULT 'regular',
  `discount_id_proof` varchar(255) DEFAULT NULL,
  `discount_verified` tinyint(1) DEFAULT 0,
  `refund_note` varchar(255) DEFAULT NULL
) ;

--
-- Dumping data for table `bookings`
--

INSERT INTO `bookings` (`id`, `bus_id`, `user_id`, `seat_number`, `booking_date`, `booking_status`, `cancel_reason`, `cancelled_at`, `refund_status`, `refund_amount`, `created_at`, `booking_reference`, `trip_number`, `payment_proof`, `payment_proof_status`, `payment_proof_timestamp`, `payment_method`, `payment_status`, `discount_type`, `discount_id_proof`, `discount_verified`, `refund_note`) VALUES
(102, 61, 3, 1, '2025-05-06', 'cancelled', 'Change of plans', '2025-05-04 01:43:18', 'pending', 364.75, '2025-05-04 01:32:42', 'BK-20250503-102', '4th Trip', NULL, 'verified', '2025-05-03 19:32:42', 'counter', 'verified', 'regular', NULL, 0, 'Full refund (more than 48 hours before departure)'),
(103, 60, 3, 1, '2025-05-03', 'confirmed', NULL, NULL, NULL, NULL, '2025-05-04 01:35:59', 'BK-20250503-103', '1st Trip', NULL, 'verified', '2025-05-03 19:35:59', 'counter', 'verified', 'regular', NULL, 0, NULL),
(104, 60, 3, 1, '2025-05-07', 'cancelled', 'Change of plans', '2025-05-04 01:40:58', 'pending', 274.75, '2025-05-04 01:36:40', 'BK-20250503-104', '1st Trip', NULL, 'verified', '2025-05-03 19:36:40', 'counter', 'verified', 'regular', NULL, 0, 'Full refund (more than 48 hours before departure)'),
(105, 60, 3, 45, '2025-05-13', 'cancelled', 'Booking error', '2025-05-04 01:43:59', 'pending', 274.75, '2025-05-04 01:43:46', 'BK-20250503-105', '1st Trip', 'uploads/payment_proofs/gcash_1746294226_681655d2ab809.jpeg', 'uploaded', '2025-05-03 19:43:46', 'gcash', 'awaiting_verificatio', 'regular', NULL, 0, 'Full refund (more than 48 hours before departure)'),
(106, 60, 3, 45, '2025-05-14', 'cancelled', 'Found alternative transportation', '2025-05-04 01:48:10', 'pending', 274.75, '2025-05-04 01:47:47', 'BK-20250503-106', '1st Trip', 'uploads/payment_proofs/gcash_1746294467_681656c3095b9.jpeg', 'verified', '2025-05-03 19:47:47', 'gcash', 'verified', 'regular', NULL, 0, 'Full refund (more than 48 hours before departure)'),
(107, 60, 3, 1, '2025-05-05', 'confirmed', NULL, NULL, NULL, NULL, '2025-05-04 01:57:42', 'BK-20250503-107', '1st Trip', 'uploads/payment_proofs/paymaya_1746295062_68165916276ab.jpeg', 'verified', '2025-05-03 19:57:42', 'paymaya', 'verified', 'regular', NULL, 0, NULL);

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
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `route_id` int(11) DEFAULT NULL,
  `route_name` varchar(255) NOT NULL,
  `origin` varchar(100) DEFAULT NULL,
  `destination` varchar(100) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `buses`
--

INSERT INTO `buses` (`id`, `bus_type`, `seat_capacity`, `plate_number`, `driver_name`, `conductor_name`, `status`, `created_at`, `updated_at`, `route_id`, `route_name`, `origin`, `destination`) VALUES
(60, 'Aircondition', 50, 'gfdfh12', 'Jose', 'Maria', 'Active', '2025-05-03 17:28:35', '2025-05-03 17:28:35', 17, 'iloilo → aklan', 'iloilo', 'aklan'),
(61, 'Regular', 50, 'dds', 'Jose', 'Maria', 'Active', '2025-05-03 17:30:32', '2025-05-03 17:30:32', 20, 'Aklan → Iloilo', 'Aklan', 'Iloilo');

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
  `created_at` datetime NOT NULL,
  `is_read` tinyint(1) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `contact_messages`
--

INSERT INTO `contact_messages` (`id`, `user_id`, `name`, `email`, `subject`, `message`, `created_at`, `is_read`) VALUES
(5, 3, 'Larry Denver Biaco', 'larrydenverbiaco@gmail.com', '1', 'fsdfgsdfd', '2025-05-03 17:24:04', 1);

-- --------------------------------------------------------

--
-- Table structure for table `contact_replies`
--

CREATE TABLE `contact_replies` (
  `id` int(11) NOT NULL,
  `message_id` int(11) NOT NULL,
  `reply_message` text NOT NULL,
  `replied_by` varchar(100) NOT NULL,
  `replied_at` datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

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
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `routes`
--

INSERT INTO `routes` (`id`, `origin`, `destination`, `distance`, `estimated_duration`, `fare`, `created_at`, `updated_at`) VALUES
(17, 'iloilo', 'aklan', 150.00, '2h 30', 274.75, '2025-05-02 11:56:00', '2025-05-02 11:56:00'),
(18, 'roxas ', 'iloilo', 100.00, '1h', 184.75, '2025-05-02 12:17:54', '2025-05-02 12:17:54'),
(20, 'Aklan', 'Iloilo', 200.00, '3h', 364.75, '2025-05-03 17:29:43', '2025-05-03 17:29:43');

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
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `date` date DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `schedules`
--

INSERT INTO `schedules` (`id`, `bus_id`, `origin`, `destination`, `departure_time`, `arrival_time`, `fare_amount`, `recurring`, `trip_number`, `status`, `created_at`, `updated_at`, `date`) VALUES
(36, 61, 'iloilo', 'aklan', '15:00:00', '17:30:00', 275.00, 1, '4th Trip', 'active', '2025-05-03 17:28:55', '2025-05-03 17:32:02', NULL),
(37, 60, 'iloilo', 'aklan', '06:00:00', '08:30:00', 275.00, 1, '1st Trip', 'active', '2025-05-03 17:31:00', '2025-05-03 17:31:00', NULL);

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
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `full_name` varchar(101) GENERATED ALWAYS AS (concat(`first_name`,' ',`last_name`)) STORED
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `first_name`, `last_name`, `gender`, `birthdate`, `contact_number`, `email`, `password`, `is_verified`, `created_at`, `updated_at`) VALUES
(3, 'Larry Denver', 'Biaco', 'male', '1999-04-21', '09123456789', 'larrydenverbiaco@gmail.com', '$2y$10$uZxqDXKIChpXIXZwREUVx.TuZ.RALawN3.dfX6c.epI1nR9NKtJHK', 1, '2025-04-26 19:14:33', '2025-05-03 08:42:46'),
(7, 'Shaira', 'Digimon', 'female', '2000-04-30', '09123456789', 'shaira.digomon@students.isatu.edu.ph', '$2y$10$TCXfZ.TN0T8RJBcmlQRGLetjxoj2.7eo9LNEWIpF3Ui5.E72bAPlm', 1, '2025-05-02 11:50:01', '2025-05-02 11:50:01');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `bookings`
--
ALTER TABLE `bookings`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `prevent_duplicate_confirmed` (`bus_id`,`seat_number`,`booking_date`,`booking_status`),
  ADD KEY `bus_id` (`bus_id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `idx_booking_reference` (`booking_reference`),
  ADD KEY `fk_bookings_schedules_trip_number` (`bus_id`,`trip_number`);

--
-- Indexes for table `buses`
--
ALTER TABLE `buses`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `plate_number` (`plate_number`),
  ADD KEY `status` (`status`),
  ADD KEY `fk_bus_route` (`route_id`);

--
-- Indexes for table `contact_messages`
--
ALTER TABLE `contact_messages`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `contact_replies`
--
ALTER TABLE `contact_replies`
  ADD PRIMARY KEY (`id`),
  ADD KEY `message_id` (`message_id`);

--
-- Indexes for table `routes`
--
ALTER TABLE `routes`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_route_search` (`origin`,`destination`);

--
-- Indexes for table `schedules`
--
ALTER TABLE `schedules`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_bus_trip` (`bus_id`,`trip_number`),
  ADD KEY `origin_destination` (`origin`,`destination`),
  ADD KEY `idx_trip_number` (`trip_number`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `email` (`email`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `bookings`
--
ALTER TABLE `bookings`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `buses`
--
ALTER TABLE `buses`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=62;

--
-- AUTO_INCREMENT for table `contact_messages`
--
ALTER TABLE `contact_messages`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `contact_replies`
--
ALTER TABLE `contact_replies`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8;

--
-- AUTO_INCREMENT for table `routes`
--
ALTER TABLE `routes`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=21;

--
-- AUTO_INCREMENT for table `schedules`
--
ALTER TABLE `schedules`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=38;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `bookings`
--
ALTER TABLE `bookings`
  ADD CONSTRAINT `fk_bookings_schedules_trip_number` FOREIGN KEY (`bus_id`,`trip_number`) REFERENCES `schedules` (`bus_id`, `trip_number`) ON UPDATE CASCADE;

--
-- Constraints for table `buses`
--
ALTER TABLE `buses`
  ADD CONSTRAINT `fk_bus_route` FOREIGN KEY (`route_id`) REFERENCES `routes` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `contact_messages`
--
ALTER TABLE `contact_messages`
  ADD CONSTRAINT `contact_messages_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`);

--
-- Constraints for table `contact_replies`
--
ALTER TABLE `contact_replies`
  ADD CONSTRAINT `contact_replies_ibfk_1` FOREIGN KEY (`message_id`) REFERENCES `contact_messages` (`id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
