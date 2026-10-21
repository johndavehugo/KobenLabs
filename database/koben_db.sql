-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Oct 21, 2026 at 06:52 PM
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
-- Database: `koben_db`
--

-- --------------------------------------------------------

--
-- Table structure for table `accessibility_settings`
--

CREATE TABLE `accessibility_settings` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `user_id` bigint(20) UNSIGNED NOT NULL,
  `text_size` varchar(20) NOT NULL DEFAULT 'normal',
  `high_contrast` tinyint(1) NOT NULL DEFAULT 0,
  `text_to_speech` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `attendances`
--

CREATE TABLE `attendances` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `class_session_id` bigint(20) UNSIGNED NOT NULL,
  `user_id` bigint(20) UNSIGNED NOT NULL,
  `joined_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `attendances`
--

INSERT INTO `attendances` (`id`, `class_session_id`, `user_id`, `joined_at`, `created_at`, `updated_at`) VALUES
(2, 2, 2, '2026-10-09 18:52:04', '2026-10-09 10:52:04', '2026-10-09 10:52:04'),
(3, 2, 3, '2026-10-16 14:25:30', '2026-10-16 06:25:30', '2026-10-16 06:25:30');

-- --------------------------------------------------------

--
-- Table structure for table `audit_logs`
--

CREATE TABLE `audit_logs` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `user_id` bigint(20) UNSIGNED DEFAULT NULL,
  `action` varchar(100) NOT NULL,
  `auditable_type` varchar(100) DEFAULT NULL,
  `auditable_id` bigint(20) UNSIGNED DEFAULT NULL,
  `properties` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`properties`)),
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `audit_logs`
--

INSERT INTO `audit_logs` (`id`, `user_id`, `action`, `auditable_type`, `auditable_id`, `properties`, `ip_address`, `user_agent`, `created_at`) VALUES
(1, 1, 'screen.view', 'App\\Filament\\Pages\\LiveScreens', NULL, '{\"scope\":\"all lab PCs (administrator)\"}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/154.0.0.0 Safari/537.36', '2026-10-09 06:07:35'),
(2, 1, 'screen.view', 'App\\Filament\\Pages\\LiveScreens', NULL, '{\"scope\":\"all lab PCs (administrator)\"}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/154.0.0.0 Safari/537.36', '2026-10-09 06:08:02'),
(3, 1, 'screen.view', 'App\\Filament\\Pages\\LiveScreens', NULL, '{\"scope\":\"all lab PCs (administrator)\"}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/154.0.0.0 Safari/537.36', '2026-10-09 06:08:18'),
(4, 1, 'created.Pc', 'App\\Models\\Pc', 1, '{\"attributes\":{\"name\":\"Joey-PC\",\"ip_address\":\"192.168.1.34\",\"mac_address\":null,\"status\":\"offline\",\"updated_at\":\"2026-10-09 14:08:56\",\"created_at\":\"2026-10-09 14:08:56\",\"id\":1}}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/154.0.0.0 Safari/537.36', '2026-10-09 06:08:57'),
(5, 1, 'created.PcClient', 'App\\Models\\PcClient', 1, '{\"attributes\":{\"pc_id\":1,\"token_hash\":\"[redacted]\",\"state\":\"active\",\"enrolled_at\":\"2026-10-09 14:10:21\",\"revoked_at\":null,\"updated_at\":\"2026-10-09 14:10:21\",\"created_at\":\"2026-10-09 14:10:21\",\"id\":1}}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/154.0.0.0 Safari/537.36', '2026-10-09 06:10:23'),
(6, 1, 'screen.view', 'App\\Filament\\Pages\\LiveScreens', NULL, '{\"scope\":\"all lab PCs (administrator)\"}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/154.0.0.0 Safari/537.36', '2026-10-09 06:12:30'),
(7, 1, 'screen.view', 'App\\Filament\\Pages\\LiveScreens', NULL, '{\"scope\":\"all lab PCs (administrator)\"}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/154.0.0.0 Safari/537.36', '2026-10-09 06:16:05'),
(8, 1, 'created.Pc', 'App\\Models\\Pc', 2, '{\"attributes\":{\"name\":\"koben\",\"ip_address\":\"192.168.11.174\",\"mac_address\":null,\"status\":\"offline\",\"updated_at\":\"2026-10-09 16:45:24\",\"created_at\":\"2026-10-09 16:45:24\",\"id\":2}}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/154.0.0.0 Safari/537.36', '2026-10-09 08:45:26'),
(9, 1, 'screen.view', 'App\\Filament\\Pages\\LiveScreens', NULL, '{\"scope\":\"all lab PCs (administrator)\"}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/154.0.0.0 Safari/537.36', '2026-10-09 08:45:27'),
(10, 2, 'updated.User', 'App\\Models\\User', 2, '{\"changes\":{\"remember_token\":{\"from\":\"[redacted]\",\"to\":\"[redacted]\"}}}', '192.168.1.39', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/154.0.0.0 Safari/537.36', '2026-10-09 08:53:11'),
(11, 1, 'screen.view', 'App\\Filament\\Pages\\LiveScreens', NULL, '{\"scope\":\"all lab PCs (administrator)\"}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/154.0.0.0 Safari/537.36', '2026-10-09 08:53:36'),
(12, 1, 'created.Schedule', 'App\\Models\\Schedule', 1, '{\"attributes\":{\"title\":\"one\",\"teacher_id\":1,\"year_section_id\":null,\"purpose\":null,\"description\":null,\"starts_at\":\"2026-10-10 00:58:00\",\"ends_at\":\"2026-10-10 01:58:00\",\"status\":\"scheduled\",\"updated_at\":\"2026-10-09 16:58:32\",\"created_at\":\"2026-10-09 16:58:32\",\"id\":1}}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/154.0.0.0 Safari/537.36', '2026-10-09 08:58:32'),
(13, 1, 'created.ClassSession', 'App\\Models\\ClassSession', 1, '{\"attributes\":{\"schedule_id\":1,\"started_by_id\":1,\"started_at\":\"2026-10-09 16:59:19\",\"status\":\"active\",\"updated_at\":\"2026-10-09 16:59:19\",\"created_at\":\"2026-10-09 16:59:19\",\"id\":1}}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/154.0.0.0 Safari/537.36', '2026-10-09 08:59:23'),
(14, 1, 'created.PcClient', 'App\\Models\\PcClient', 2, '{\"attributes\":{\"pc_id\":2,\"token_hash\":\"[redacted]\",\"state\":\"active\",\"enrolled_at\":\"2026-10-09 17:01:01\",\"revoked_at\":null,\"updated_at\":\"2026-10-09 17:01:01\",\"created_at\":\"2026-10-09 17:01:01\",\"id\":2}}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/154.0.0.0 Safari/537.36', '2026-10-09 09:01:04'),
(15, 1, 'updated.PcClient', 'App\\Models\\PcClient', 2, '{\"changes\":{\"token_hash\":{\"from\":\"[redacted]\",\"to\":\"[redacted]\"},\"enrolled_at\":{\"from\":\"2026-10-09T17:01:01.000000Z\",\"to\":\"2026-10-09 17:05:45\"},\"updated_at\":{\"from\":\"2026-10-09T17:01:01.000000Z\",\"to\":\"2026-10-09 17:05:45\"}}}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/154.0.0.0 Safari/537.36', '2026-10-09 09:05:49'),
(16, 1, 'updated.PcClient', 'App\\Models\\PcClient', 2, '{\"changes\":{\"token_hash\":{\"from\":\"[redacted]\",\"to\":\"[redacted]\"},\"enrolled_at\":{\"from\":\"2026-10-09T17:05:45.000000Z\",\"to\":\"2026-10-09 17:06:38\"},\"updated_at\":{\"from\":\"2026-10-09T17:05:45.000000Z\",\"to\":\"2026-10-09 17:06:38\"}}}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/154.0.0.0 Safari/537.36', '2026-10-09 09:06:42'),
(17, 1, 'screen.view', 'App\\Filament\\Pages\\LiveScreens', NULL, '{\"scope\":\"all lab PCs (administrator)\"}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/154.0.0.0 Safari/537.36', '2026-10-09 09:08:08'),
(18, 1, 'screen.view', 'App\\Filament\\Pages\\LiveScreens', NULL, '{\"scope\":\"all lab PCs (administrator)\"}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/154.0.0.0 Safari/537.36', '2026-10-09 09:09:25'),
(19, 1, 'screen.view', 'App\\Filament\\Pages\\LiveScreens', NULL, '{\"scope\":\"all lab PCs (administrator)\"}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/154.0.0.0 Safari/537.36', '2026-10-09 09:16:18'),
(20, 1, 'screen.view', 'App\\Filament\\Pages\\LiveScreens', NULL, '{\"scope\":\"all lab PCs (administrator)\"}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/154.0.0.0 Safari/537.36', '2026-10-09 09:23:33'),
(21, 1, 'updated.Pc', 'App\\Models\\Pc', 1, '{\"changes\":{\"status\":{\"from\":\"offline\",\"to\":\"online\"},\"last_seen_at\":{\"from\":null,\"to\":\"2026-10-09 17:30:55\"},\"updated_at\":{\"from\":\"2026-10-09T14:08:56.000000Z\",\"to\":\"2026-10-09 17:30:55\"}}}', '192.168.1.34', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/154.0.0.0 Safari/537.36', '2026-10-09 09:30:56'),
(22, NULL, 'updated.Pc', 'App\\Models\\Pc', 1, '{\"changes\":{\"status\":{\"from\":\"online\",\"to\":\"offline\"},\"last_seen_at\":{\"from\":\"2026-10-09T17:30:55.000000Z\",\"to\":null},\"updated_at\":{\"from\":\"2026-10-09T17:30:55.000000Z\",\"to\":\"2026-10-09 17:45:03\"}}}', '127.0.0.1', 'Symfony', '2026-10-09 09:45:03'),
(23, 1, 'screen.view', 'App\\Filament\\Pages\\LiveScreens', NULL, '{\"scope\":\"all lab PCs (administrator)\"}', '192.168.1.34', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/154.0.0.0 Safari/537.36', '2026-10-09 10:14:41'),
(24, 1, 'screen.view', 'App\\Filament\\Pages\\LiveScreens', NULL, '{\"scope\":\"all lab PCs (administrator)\"}', '192.168.1.34', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/154.0.0.0 Safari/537.36', '2026-10-09 10:14:42'),
(25, 1, 'updated.PcClient', 'App\\Models\\PcClient', 2, '{\"changes\":{\"token_hash\":{\"from\":\"[redacted]\",\"to\":\"[redacted]\"},\"enrolled_at\":{\"from\":\"2026-10-09T17:06:38.000000Z\",\"to\":\"2026-10-09 18:14:49\"},\"updated_at\":{\"from\":\"2026-10-09T17:06:38.000000Z\",\"to\":\"2026-10-09 18:14:49\"}}}', '192.168.1.34', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/154.0.0.0 Safari/537.36', '2026-10-09 10:14:51'),
(26, 1, 'screen.view', 'App\\Filament\\Pages\\LiveScreens', NULL, '{\"scope\":\"all lab PCs (administrator)\"}', '192.168.1.34', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/154.0.0.0 Safari/537.36', '2026-10-09 10:18:15'),
(27, 1, 'screen.view', 'App\\Filament\\Pages\\LiveScreens', NULL, '{\"scope\":\"all lab PCs (administrator)\"}', '192.168.1.34', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/154.0.0.0 Safari/537.36', '2026-10-09 10:18:17'),
(28, 1, 'screen.view', 'App\\Filament\\Pages\\LiveScreens', NULL, '{\"scope\":\"all lab PCs (administrator)\"}', '192.168.1.34', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/154.0.0.0 Safari/537.36', '2026-10-09 10:23:34'),
(29, 1, 'screen.view', 'App\\Filament\\Pages\\LiveScreens', NULL, '{\"scope\":\"all lab PCs (administrator)\"}', '192.168.1.34', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/154.0.0.0 Safari/537.36', '2026-10-09 10:42:09'),
(30, 2, 'updated.User', 'App\\Models\\User', 2, '{\"changes\":{\"remember_token\":{\"from\":\"[redacted]\",\"to\":\"[redacted]\"}}}', '192.168.1.39', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/154.0.0.0 Safari/537.36', '2026-10-09 10:43:10'),
(31, 1, 'screen.view', 'App\\Filament\\Pages\\LiveScreens', NULL, '{\"scope\":\"all lab PCs (administrator)\"}', '192.168.1.34', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/154.0.0.0 Safari/537.36', '2026-10-09 10:43:17'),
(32, 1, 'updated.ClassSession', 'App\\Models\\ClassSession', 1, '{\"changes\":{\"ended_at\":{\"from\":null,\"to\":\"2026-10-09 18:44:20\"},\"status\":{\"from\":\"active\",\"to\":\"completed\"},\"updated_at\":{\"from\":\"2026-10-09T16:59:19.000000Z\",\"to\":\"2026-10-09 18:44:20\"}}}', '192.168.1.34', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/154.0.0.0 Safari/537.36', '2026-10-09 10:44:20'),
(33, 1, 'updated.Schedule', 'App\\Models\\Schedule', 1, '{\"changes\":{\"starts_at\":{\"from\":\"2026-10-10T00:58:00.000000Z\",\"to\":\"2026-10-10 02:48:00\"},\"ends_at\":{\"from\":\"2026-10-10T01:58:00.000000Z\",\"to\":\"2026-10-10 04:00:00\"},\"updated_at\":{\"from\":\"2026-10-09T16:58:32.000000Z\",\"to\":\"2026-10-09 18:47:45\"}}}', '192.168.1.34', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/154.0.0.0 Safari/537.36', '2026-10-09 10:47:46'),
(34, 1, 'screen.view', 'App\\Filament\\Pages\\LiveScreens', NULL, '{\"scope\":\"all lab PCs (administrator)\"}', '192.168.1.34', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/154.0.0.0 Safari/537.36', '2026-10-09 10:48:43'),
(35, 1, 'deleted.Schedule', 'App\\Models\\Schedule', 1, '{\"attributes\":{\"id\":1,\"teacher_id\":1,\"year_section_id\":null,\"title\":\"one\",\"description\":null,\"starts_at\":\"2026-10-10T02:48:00.000000Z\",\"ends_at\":\"2026-10-10T04:00:00.000000Z\",\"purpose\":null,\"status\":\"scheduled\",\"created_at\":\"2026-10-09T16:58:32.000000Z\",\"updated_at\":\"2026-10-09T18:47:45.000000Z\"}}', '192.168.1.34', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/154.0.0.0 Safari/537.36', '2026-10-09 10:48:51'),
(36, 1, 'created.Schedule', 'App\\Models\\Schedule', 2, '{\"attributes\":{\"title\":\"one\",\"teacher_id\":1,\"year_section_id\":null,\"purpose\":null,\"description\":null,\"starts_at\":\"2026-10-10 02:49:00\",\"ends_at\":\"2026-10-10 03:50:00\",\"status\":\"scheduled\",\"updated_at\":\"2026-10-09 18:49:21\",\"created_at\":\"2026-10-09 18:49:21\",\"id\":2}}', '192.168.1.34', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/154.0.0.0 Safari/537.36', '2026-10-09 10:49:22'),
(37, 1, 'screen.view', 'App\\Filament\\Pages\\LiveScreens', NULL, '{\"scope\":\"all lab PCs (administrator)\"}', '192.168.1.34', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/154.0.0.0 Safari/537.36', '2026-10-09 10:49:43'),
(38, 1, 'created.ClassSession', 'App\\Models\\ClassSession', 2, '{\"attributes\":{\"schedule_id\":2,\"started_by_id\":1,\"started_at\":\"2026-10-09 18:50:29\",\"status\":\"active\",\"updated_at\":\"2026-10-09 18:50:29\",\"created_at\":\"2026-10-09 18:50:29\",\"id\":2}}', '192.168.1.34', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/154.0.0.0 Safari/537.36', '2026-10-09 10:50:32'),
(39, 1, 'screen.view', 'App\\Filament\\Pages\\LiveScreens', NULL, '{\"scope\":\"all lab PCs (administrator)\"}', '192.168.1.34', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/154.0.0.0 Safari/537.36', '2026-10-09 10:52:32'),
(40, 1, 'created.Pc', 'App\\Models\\Pc', 3, '{\"attributes\":{\"name\":\"Epoy-PC\",\"ip_address\":\"192.168.254.131\",\"mac_address\":null,\"status\":\"offline\",\"updated_at\":\"2026-10-13 14:34:23\",\"created_at\":\"2026-10-13 14:34:23\",\"id\":3}}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/154.0.0.0 Safari/537.36', '2026-10-13 06:34:25'),
(41, 1, 'created.PcClient', 'App\\Models\\PcClient', 3, '{\"attributes\":{\"pc_id\":3,\"token_hash\":\"[redacted]\",\"state\":\"active\",\"enrolled_at\":\"2026-10-13 14:34:31\",\"revoked_at\":null,\"updated_at\":\"2026-10-13 14:34:31\",\"created_at\":\"2026-10-13 14:34:31\",\"id\":3}}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/154.0.0.0 Safari/537.36', '2026-10-13 06:34:32'),
(42, 1, 'screen.view', 'App\\Filament\\Pages\\LiveScreens', NULL, '{\"scope\":\"all lab PCs (administrator)\"}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/154.0.0.0 Safari/537.36', '2026-10-13 06:42:11'),
(43, 1, 'screen.view', 'App\\Filament\\Pages\\LiveScreens', NULL, '{\"scope\":\"all lab PCs (administrator)\"}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/154.0.0.0 Safari/537.36', '2026-10-13 06:50:51'),
(44, 1, 'screen.view', 'App\\Filament\\Pages\\LiveScreens', NULL, '{\"scope\":\"all lab PCs (administrator)\"}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/154.0.0.0 Safari/537.36', '2026-10-13 10:01:47'),
(45, 1, 'screen.view', 'App\\Filament\\Pages\\LiveScreens', NULL, '{\"scope\":\"all lab PCs (administrator)\"}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/155.0.0.0 Safari/537.36', '2026-10-16 05:31:11'),
(46, 1, 'screen.view', 'App\\Filament\\Pages\\LiveScreens', NULL, '{\"scope\":\"all lab PCs (administrator)\"}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/155.0.0.0 Safari/537.36', '2026-10-16 05:31:13'),
(47, 1, 'updated.PcClient', 'App\\Models\\PcClient', 3, '{\"changes\":{\"token_hash\":{\"from\":\"[redacted]\",\"to\":\"[redacted]\"},\"enrolled_at\":{\"from\":\"2026-10-13T14:34:31.000000Z\",\"to\":\"2026-10-16 13:25:23\"},\"updated_at\":{\"from\":\"2026-10-13T15:33:03.000000Z\",\"to\":\"2026-10-16 13:25:23\"}}}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/155.0.0.0 Safari/537.36', '2026-10-16 05:31:15'),
(48, 1, 'screen.view', 'App\\Filament\\Pages\\LiveScreens', NULL, '{\"scope\":\"all lab PCs (administrator)\"}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/155.0.0.0 Safari/537.36', '2026-10-16 05:32:16'),
(49, 1, 'screen.view', 'App\\Filament\\Pages\\LiveScreens', NULL, '{\"scope\":\"all lab PCs (administrator)\"}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/155.0.0.0 Safari/537.36', '2026-10-16 05:32:18'),
(50, 1, 'screen.view', 'App\\Filament\\Pages\\LiveScreens', NULL, '{\"scope\":\"all lab PCs (administrator)\"}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/155.0.0.0 Safari/537.36', '2026-10-16 05:32:20'),
(51, 1, 'screen.view', 'App\\Filament\\Pages\\LiveScreens', NULL, '{\"scope\":\"all lab PCs (administrator)\"}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/155.0.0.0 Safari/537.36', '2026-10-16 05:32:27'),
(52, 1, 'screen.view', 'App\\Filament\\Pages\\LiveScreens', NULL, '{\"scope\":\"all lab PCs (administrator)\"}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/155.0.0.0 Safari/537.36', '2026-10-16 05:32:43'),
(53, 1, 'screen.view', 'App\\Filament\\Pages\\LiveScreens', NULL, '{\"scope\":\"all lab PCs (administrator)\"}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/155.0.0.0 Safari/537.36', '2026-10-16 05:32:55'),
(54, 1, 'screen.view', 'App\\Filament\\Pages\\LiveScreens', NULL, '{\"scope\":\"all lab PCs (administrator)\"}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/155.0.0.0 Safari/537.36', '2026-10-16 05:32:57'),
(55, 1, 'updated.Schedule', 'App\\Models\\Schedule', 2, '{\"changes\":{\"status\":{\"from\":\"scheduled\",\"to\":\"cancelled\"},\"updated_at\":{\"from\":\"2026-10-09T18:49:21.000000Z\",\"to\":\"2026-10-16 13:33:12\"}}}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/155.0.0.0 Safari/537.36', '2026-10-16 05:33:13'),
(56, 1, 'updated.Schedule', 'App\\Models\\Schedule', 2, '{\"changes\":{\"starts_at\":{\"from\":\"2026-10-10T02:49:00.000000Z\",\"to\":\"2026-10-16 21:30:00\"},\"ends_at\":{\"from\":\"2026-10-10T03:50:00.000000Z\",\"to\":\"2026-10-16 22:30:00\"},\"updated_at\":{\"from\":\"2026-10-16T13:33:12.000000Z\",\"to\":\"2026-10-16 13:34:48\"}}}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/155.0.0.0 Safari/537.36', '2026-10-16 05:34:52'),
(57, 1, 'screen.view', 'App\\Filament\\Pages\\LiveScreens', NULL, '{\"scope\":\"all lab PCs (administrator)\"}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/155.0.0.0 Safari/537.36', '2026-10-16 05:34:54'),
(58, 1, 'screen.view', 'App\\Filament\\Pages\\LiveScreens', NULL, '{\"scope\":\"all lab PCs (administrator)\"}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/155.0.0.0 Safari/537.36', '2026-10-16 05:34:56'),
(59, 1, 'screen.view', 'App\\Filament\\Pages\\LiveScreens', NULL, '{\"scope\":\"all lab PCs (administrator)\"}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/155.0.0.0 Safari/537.36', '2026-10-16 05:43:36'),
(60, 1, 'screen.view', 'App\\Filament\\Pages\\LiveScreens', NULL, '{\"scope\":\"all lab PCs (administrator)\"}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/155.0.0.0 Safari/537.36', '2026-10-16 05:43:38'),
(61, 1, 'screen.view', 'App\\Filament\\Pages\\LiveScreens', NULL, '{\"scope\":\"all lab PCs (administrator)\"}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/155.0.0.0 Safari/537.36', '2026-10-16 06:07:42'),
(62, 1, 'updated.PcClient', 'App\\Models\\PcClient', 3, '{\"changes\":{\"token_hash\":{\"from\":\"[redacted]\",\"to\":\"[redacted]\"},\"enrolled_at\":{\"from\":\"2026-10-16T13:25:23.000000Z\",\"to\":\"2026-10-16 14:08:00\"},\"updated_at\":{\"from\":\"2026-10-16T13:25:23.000000Z\",\"to\":\"2026-10-16 14:08:00\"}}}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/155.0.0.0 Safari/537.36', '2026-10-16 06:08:03'),
(63, 1, 'updated.PcClient', 'App\\Models\\PcClient', 2, '{\"changes\":{\"token_hash\":{\"from\":\"[redacted]\",\"to\":\"[redacted]\"},\"enrolled_at\":{\"from\":\"2026-10-09T18:14:49.000000Z\",\"to\":\"2026-10-16 14:08:36\"},\"updated_at\":{\"from\":\"2026-10-09T18:51:04.000000Z\",\"to\":\"2026-10-16 14:08:36\"}}}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/155.0.0.0 Safari/537.36', '2026-10-16 06:08:38'),
(64, 1, 'updated.PcClient', 'App\\Models\\PcClient', 3, '{\"changes\":{\"token_hash\":{\"from\":\"[redacted]\",\"to\":\"[redacted]\"},\"enrolled_at\":{\"from\":\"2026-10-16T14:08:00.000000Z\",\"to\":\"2026-10-16 14:08:39\"},\"updated_at\":{\"from\":\"2026-10-16T14:08:00.000000Z\",\"to\":\"2026-10-16 14:08:39\"}}}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/155.0.0.0 Safari/537.36', '2026-10-16 06:08:41'),
(65, 1, 'updated.PcClient', 'App\\Models\\PcClient', 3, '{\"changes\":{\"state\":{\"from\":\"active\",\"to\":\"revoked\"},\"revoked_at\":{\"from\":null,\"to\":\"2026-10-16 14:08:47\"},\"updated_at\":{\"from\":\"2026-10-16T14:08:39.000000Z\",\"to\":\"2026-10-16 14:08:47\"}}}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/155.0.0.0 Safari/537.36', '2026-10-16 06:08:47'),
(66, 1, 'updated.PcClient', 'App\\Models\\PcClient', 3, '{\"changes\":{\"token_hash\":{\"from\":\"[redacted]\",\"to\":\"[redacted]\"},\"state\":{\"from\":\"revoked\",\"to\":\"active\"},\"enrolled_at\":{\"from\":\"2026-10-16T14:08:39.000000Z\",\"to\":\"2026-10-16 14:08:50\"},\"revoked_at\":{\"from\":\"2026-10-16T14:08:47.000000Z\",\"to\":null},\"updated_at\":{\"from\":\"2026-10-16T14:08:47.000000Z\",\"to\":\"2026-10-16 14:08:50\"}}}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/155.0.0.0 Safari/537.36', '2026-10-16 06:08:54'),
(67, 1, 'screen.view', 'App\\Filament\\Pages\\LiveScreens', NULL, '{\"scope\":\"all lab PCs (administrator)\"}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/155.0.0.0 Safari/537.36', '2026-10-16 06:11:38'),
(68, 1, 'created.User', 'App\\Models\\User', 3, '{\"attributes\":{\"first_name\":\"haru\",\"last_name\":\"Ramas\",\"username\":\"haru187\",\"email\":\"haru187@gmail.com\",\"password\":\"[redacted]\",\"status\":\"active\",\"role\":\"student\",\"storage_quota_mb\":500,\"student_id\":\"696969\",\"year_section_id\":null,\"updated_at\":\"2026-10-16 14:19:15\",\"created_at\":\"2026-10-16 14:19:15\",\"id\":3}}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/155.0.0.0 Safari/537.36', '2026-10-16 06:19:18'),
(69, 1, 'created.YearSection', 'App\\Models\\YearSection', 1, '{\"attributes\":{\"year\":\"BSIT-1\",\"section\":\"A\",\"is_active\":true,\"updated_at\":\"2026-10-16 14:22:20\",\"created_at\":\"2026-10-16 14:22:20\",\"id\":1}}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/155.0.0.0 Safari/537.36', '2026-10-16 06:22:22'),
(70, 1, 'screen.view', 'App\\Filament\\Pages\\LiveScreens', NULL, '{\"scope\":\"all lab PCs (administrator)\"}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/155.0.0.0 Safari/537.36', '2026-10-16 06:25:42'),
(71, 1, 'screen.view', 'App\\Filament\\Pages\\LiveScreens', NULL, '{\"scope\":\"all lab PCs (administrator)\"}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/155.0.0.0 Safari/537.36', '2026-10-16 06:26:17'),
(72, 1, 'created.GroupChat', 'App\\Models\\GroupChat', 1, '{\"attributes\":{\"name\":\"Sibika-1\",\"description\":\"4 Skul Purpose\",\"join_code\":\"Co2F47vD\",\"created_by_id\":1,\"updated_at\":\"2026-10-16 14:27:54\",\"created_at\":\"2026-10-16 14:27:54\",\"id\":1}}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/155.0.0.0 Safari/537.36', '2026-10-16 06:27:58'),
(73, 1, 'screen.view', 'App\\Filament\\Pages\\LiveScreens', NULL, '{\"scope\":\"all lab PCs (administrator)\"}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/155.0.0.0 Safari/537.36', '2026-10-16 06:28:40'),
(74, 1, 'screen.view', 'App\\Filament\\Pages\\LiveScreens', NULL, '{\"scope\":\"all lab PCs (administrator)\"}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/155.0.0.0 Safari/537.36', '2026-10-16 06:49:20'),
(75, 1, 'screen.view', 'App\\Filament\\Pages\\LiveScreens', NULL, '{\"scope\":\"all lab PCs (administrator)\"}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/155.0.0.0 Safari/537.36', '2026-10-16 06:52:07'),
(76, 1, 'screen.view', 'App\\Filament\\Pages\\LiveScreens', NULL, '{\"scope\":\"all lab PCs (administrator)\"}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/155.0.0.0 Safari/537.36', '2026-10-16 06:54:31'),
(77, 1, 'screen.view', 'App\\Filament\\Pages\\LiveScreens', NULL, '{\"scope\":\"all lab PCs (administrator)\"}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/155.0.0.0 Safari/537.36', '2026-10-16 06:54:43'),
(78, 1, 'screen.view', 'App\\Filament\\Pages\\LiveScreens', NULL, '{\"scope\":\"all lab PCs (administrator)\"}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/155.0.0.0 Safari/537.36', '2026-10-16 06:57:56'),
(79, 1, 'screen.view', 'App\\Filament\\Pages\\LiveScreens', NULL, '{\"scope\":\"all lab PCs (administrator)\"}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/155.0.0.0 Safari/537.36', '2026-10-16 07:05:25'),
(80, 1, 'updated.Pc', 'App\\Models\\Pc', 3, '{\"changes\":{\"ip_address\":{\"from\":\"192.168.254.131\",\"to\":\"192.168.254.124\"},\"updated_at\":{\"from\":\"2026-10-13T14:34:23.000000Z\",\"to\":\"2026-10-18 14:20:57\"}}}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/155.0.0.0 Safari/537.36', '2026-10-18 06:21:02'),
(81, 1, 'updated.PcClient', 'App\\Models\\PcClient', 3, '{\"changes\":{\"token_hash\":{\"from\":\"[redacted]\",\"to\":\"[redacted]\"},\"enrolled_at\":{\"from\":\"2026-10-16T14:08:50.000000Z\",\"to\":\"2026-10-18 14:21:06\"},\"updated_at\":{\"from\":\"2026-10-16T15:11:42.000000Z\",\"to\":\"2026-10-18 14:21:06\"}}}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/155.0.0.0 Safari/537.36', '2026-10-18 06:21:08'),
(82, 1, 'updated.PcClient', 'App\\Models\\PcClient', 3, '{\"changes\":{\"token_hash\":{\"from\":\"[redacted]\",\"to\":\"[redacted]\"},\"enrolled_at\":{\"from\":\"2026-10-18T14:21:06.000000Z\",\"to\":\"2026-10-18 14:23:14\"},\"updated_at\":{\"from\":\"2026-10-18T14:21:06.000000Z\",\"to\":\"2026-10-18 14:23:14\"}}}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/155.0.0.0 Safari/537.36', '2026-10-18 06:23:17'),
(83, 1, 'screen.view', 'App\\Filament\\Pages\\LiveScreens', NULL, '{\"scope\":\"all lab PCs (administrator)\"}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/155.0.0.0 Safari/537.36', '2026-10-18 06:26:37'),
(84, 1, 'screen.view', 'App\\Filament\\Pages\\LiveScreens', NULL, '{\"scope\":\"all lab PCs (administrator)\"}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/155.0.0.0 Safari/537.36', '2026-10-18 06:26:39'),
(85, 1, 'screen.view', 'App\\Filament\\Pages\\LiveScreens', NULL, '{\"scope\":\"all lab PCs (administrator)\"}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/155.0.0.0 Safari/537.36', '2026-10-18 06:39:12'),
(86, 3, 'updated.Pc', 'App\\Models\\Pc', 3, '{\"changes\":{\"status\":{\"from\":\"offline\",\"to\":\"online\"},\"last_seen_at\":{\"from\":null,\"to\":\"2026-10-19 14:12:38\"},\"updated_at\":{\"from\":\"2026-10-18T14:20:57.000000Z\",\"to\":\"2026-10-19 14:12:38\"}}}', '192.168.254.124', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/155.0.0.0 Safari/537.36', '2026-10-19 06:12:46'),
(87, 3, 'updated.Pc', 'App\\Models\\Pc', 3, '{\"changes\":{\"last_seen_at\":{\"from\":\"2026-10-19T14:12:38.000000Z\",\"to\":\"2026-10-19 14:12:42\"},\"updated_at\":{\"from\":\"2026-10-19T14:12:38.000000Z\",\"to\":\"2026-10-19 14:12:42\"}}}', '192.168.254.124', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/155.0.0.0 Safari/537.36', '2026-10-19 06:12:47'),
(88, 1, 'screen.view', 'App\\Filament\\Pages\\LiveScreens', NULL, '{\"scope\":\"all lab PCs (administrator)\"}', '192.168.254.115', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/155.0.0.0 Safari/537.36', '2026-10-19 06:13:22'),
(89, NULL, 'updated.Pc', 'App\\Models\\Pc', 3, '{\"changes\":{\"status\":{\"from\":\"online\",\"to\":\"offline\"},\"last_seen_at\":{\"from\":\"2026-10-19T14:12:42.000000Z\",\"to\":null},\"updated_at\":{\"from\":\"2026-10-19T14:12:42.000000Z\",\"to\":\"2026-10-19 14:25:04\"}}}', '127.0.0.1', 'Symfony', '2026-10-19 06:25:10'),
(90, 1, 'screen.view', 'App\\Filament\\Pages\\LiveScreens', NULL, '{\"scope\":\"all lab PCs (administrator)\"}', '192.168.254.115', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/155.0.0.0 Safari/537.36', '2026-10-19 09:00:03'),
(91, 1, 'updated.User', 'App\\Models\\User', 1, '{\"changes\":{\"username\":{\"from\":\"yreichel\",\"to\":\"koben\"},\"updated_at\":{\"from\":\"2026-10-09T13:55:36.000000Z\",\"to\":\"2026-10-20 15:26:28\"}}}', '192.168.254.115', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/155.0.0.0 Safari/537.36', '2026-10-20 07:26:32');

-- --------------------------------------------------------

--
-- Table structure for table `cache`
--

CREATE TABLE `cache` (
  `key` varchar(255) NOT NULL,
  `value` mediumtext NOT NULL,
  `expiration` bigint(20) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `cache`
--

INSERT INTO `cache` (`key`, `value`, `expiration`) VALUES
('kobenlabs-cache-77de68daecd823babbb58edb1c8e14d7106e83bb', 'i:1;', 1792160994),
('kobenlabs-cache-77de68daecd823babbb58edb1c8e14d7106e83bb:timer', 'i:1792160994;', 1792160994),
('kobenlabs-cache-chat-typing-1-1', 'i:1;', 1792168224),
('kobenlabs-cache-chat-typing-1-1:timer', 'i:1792168224;', 1792168224),
('kobenlabs-cache-chat-typing-1-3', 'i:1;', 1792168232),
('kobenlabs-cache-chat-typing-1-3:timer', 'i:1792168232;', 1792168232),
('kobenlabs-cache-livewire-rate-limiter:1578ec7d7632a27c6ff910f04db56f6c7b042784', 'i:1;', 1791571490),
('kobenlabs-cache-livewire-rate-limiter:1578ec7d7632a27c6ff910f04db56f6c7b042784:timer', 'i:1791571490;', 1791571490),
('kobenlabs-cache-livewire-rate-limiter:16d36dff9abd246c67dfac3e63b993a169af77e6', 'i:1;', 1792590290),
('kobenlabs-cache-livewire-rate-limiter:16d36dff9abd246c67dfac3e63b993a169af77e6:timer', 'i:1792590290;', 1792590290),
('kobenlabs-cache-livewire-rate-limiter:28c5a0d2ed9fa95001ce854c5b5ec48c2f4d275a', 'i:1;', 1792511471),
('kobenlabs-cache-livewire-rate-limiter:28c5a0d2ed9fa95001ce854c5b5ec48c2f4d275a:timer', 'i:1792511471;', 1792511471),
('kobenlabs-cache-livewire-rate-limiter:400910ebaf232bf968c133cc279b2e610dc14912', 'i:1;', 1792419217),
('kobenlabs-cache-livewire-rate-limiter:400910ebaf232bf968c133cc279b2e610dc14912:timer', 'i:1792419217;', 1792419217),
('kobenlabs-cache-livewire-rate-limiter:682cf9825c3ced5d43c6a4fb8b5316803159f98e', 'i:2;', 1792160605),
('kobenlabs-cache-livewire-rate-limiter:682cf9825c3ced5d43c6a4fb8b5316803159f98e:timer', 'i:1792160605;', 1792160605);

-- --------------------------------------------------------

--
-- Table structure for table `cache_locks`
--

CREATE TABLE `cache_locks` (
  `key` varchar(255) NOT NULL,
  `owner` varchar(255) NOT NULL,
  `expiration` bigint(20) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `class_sessions`
--

CREATE TABLE `class_sessions` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `schedule_id` bigint(20) UNSIGNED NOT NULL,
  `started_by_id` bigint(20) UNSIGNED NOT NULL,
  `started_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `ended_at` timestamp NULL DEFAULT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'active',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `class_sessions`
--

INSERT INTO `class_sessions` (`id`, `schedule_id`, `started_by_id`, `started_at`, `ended_at`, `status`, `created_at`, `updated_at`) VALUES
(2, 2, 1, '2026-10-09 10:50:29', NULL, 'active', '2026-10-09 10:50:29', '2026-10-09 10:50:29');

-- --------------------------------------------------------

--
-- Table structure for table `failed_jobs`
--

CREATE TABLE `failed_jobs` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `uuid` varchar(255) NOT NULL,
  `connection` varchar(255) NOT NULL,
  `queue` varchar(255) NOT NULL,
  `payload` longtext NOT NULL,
  `exception` longtext NOT NULL,
  `failed_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `group_chats`
--

CREATE TABLE `group_chats` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `name` varchar(100) NOT NULL,
  `description` text DEFAULT NULL,
  `join_code` varchar(8) NOT NULL,
  `created_by_id` bigint(20) UNSIGNED NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `group_chats`
--

INSERT INTO `group_chats` (`id`, `name`, `description`, `join_code`, `created_by_id`, `is_active`, `created_at`, `updated_at`) VALUES
(1, 'Sibika-1', '4 Skul Purpose', 'Co2F47vD', 1, 1, '2026-10-16 06:27:54', '2026-10-16 06:27:54');

-- --------------------------------------------------------

--
-- Table structure for table `group_chat_members`
--

CREATE TABLE `group_chat_members` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `group_chat_id` bigint(20) UNSIGNED NOT NULL,
  `user_id` bigint(20) UNSIGNED NOT NULL,
  `role` varchar(20) NOT NULL DEFAULT 'member',
  `joined_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `group_chat_members`
--

INSERT INTO `group_chat_members` (`id`, `group_chat_id`, `user_id`, `role`, `joined_at`) VALUES
(1, 1, 1, 'admin', '2026-10-16 06:27:54'),
(2, 1, 3, 'member', '2026-10-16 06:31:42');

-- --------------------------------------------------------

--
-- Table structure for table `group_chat_messages`
--

CREATE TABLE `group_chat_messages` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `group_chat_id` bigint(20) UNSIGNED NOT NULL,
  `sender_id` bigint(20) UNSIGNED DEFAULT NULL,
  `body` text NOT NULL,
  `type` varchar(20) NOT NULL DEFAULT 'text',
  `file_path` varchar(500) DEFAULT NULL,
  `file_name` varchar(255) DEFAULT NULL,
  `file_size` bigint(20) UNSIGNED DEFAULT NULL,
  `reply_to_id` bigint(20) UNSIGNED DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `group_chat_messages`
--

INSERT INTO `group_chat_messages` (`id`, `group_chat_id`, `sender_id`, `body`, `type`, `file_path`, `file_name`, `file_size`, `reply_to_id`, `created_at`, `updated_at`) VALUES
(1, 1, 1, 'part klase taron', 'text', NULL, NULL, NULL, NULL, '2026-10-16 06:28:12', '2026-10-16 06:28:12'),
(2, 1, NULL, 'haru Ramas joined the group.', 'system', NULL, NULL, NULL, NULL, '2026-10-16 06:31:42', '2026-10-16 06:31:42'),
(3, 1, 3, 'utrtyf', 'text', NULL, NULL, NULL, NULL, '2026-10-16 06:31:58', '2026-10-16 06:31:58'),
(4, 1, 1, 'asdasd', 'text', NULL, NULL, NULL, NULL, '2026-10-16 06:58:27', '2026-10-16 06:58:27'),
(5, 1, 1, 'sdfdsf', 'text', NULL, NULL, NULL, NULL, '2026-10-16 07:37:56', '2026-10-16 07:37:56'),
(6, 1, 3, 'ayo', 'text', NULL, NULL, NULL, NULL, '2026-10-16 07:38:54', '2026-10-16 07:38:54'),
(7, 1, 3, 'rtter', 'text', NULL, NULL, NULL, NULL, '2026-10-16 07:50:33', '2026-10-16 07:50:33'),
(8, 1, 3, 'rtter', 'text', NULL, NULL, NULL, NULL, '2026-10-16 07:50:48', '2026-10-16 07:50:48'),
(9, 1, 3, 'qwqew', 'text', NULL, NULL, NULL, NULL, '2026-10-16 07:51:25', '2026-10-16 07:51:25'),
(10, 1, 3, 'qwwqeweqweq23232q3q2eweqw', 'text', NULL, NULL, NULL, NULL, '2026-10-16 07:51:36', '2026-10-16 07:51:36'),
(11, 1, 3, 'haru\nsdasda', 'text', NULL, NULL, NULL, NULL, '2026-10-16 07:52:28', '2026-10-16 07:52:28'),
(12, 1, 1, 'sgsgs', 'text', NULL, NULL, NULL, NULL, '2026-10-16 08:02:17', '2026-10-16 08:02:17'),
(13, 1, 3, 'QWE', 'text', NULL, NULL, NULL, NULL, '2026-10-16 08:02:37', '2026-10-16 08:02:37'),
(14, 1, 3, 'ROBETH', 'text', NULL, NULL, NULL, NULL, '2026-10-16 08:02:46', '2026-10-16 08:02:46'),
(15, 1, 3, 'TDTDD', 'text', NULL, NULL, NULL, NULL, '2026-10-16 08:08:30', '2026-10-16 08:08:30'),
(16, 1, 3, 'GDGFDFD', 'text', NULL, NULL, NULL, NULL, '2026-10-16 08:08:45', '2026-10-16 08:08:45'),
(17, 1, 3, 'asasa', 'text', NULL, NULL, NULL, NULL, '2026-10-16 08:30:14', '2026-10-16 08:30:14'),
(18, 1, 1, 'hgbhnhnhn', 'text', NULL, NULL, NULL, NULL, '2026-10-16 08:30:25', '2026-10-16 08:30:25'),
(19, 1, 3, 'arjhay catanduANES', 'text', NULL, NULL, NULL, NULL, '2026-10-16 08:30:33', '2026-10-16 08:30:33');

-- --------------------------------------------------------

--
-- Table structure for table `group_message_reads`
--

CREATE TABLE `group_message_reads` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `message_id` bigint(20) UNSIGNED NOT NULL,
  `user_id` bigint(20) UNSIGNED NOT NULL,
  `read_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `group_message_reads`
--

INSERT INTO `group_message_reads` (`id`, `message_id`, `user_id`, `read_at`) VALUES
(1, 2, 1, '2026-10-16 06:32:05'),
(2, 3, 1, '2026-10-16 06:32:05'),
(3, 1, 3, '2026-10-16 07:38:40'),
(4, 2, 3, '2026-10-16 07:38:40'),
(5, 4, 3, '2026-10-16 07:38:40'),
(6, 5, 3, '2026-10-16 07:38:40'),
(7, 6, 1, '2026-10-16 07:38:58'),
(8, 7, 1, '2026-10-16 07:50:51'),
(9, 8, 1, '2026-10-16 07:50:51'),
(10, 9, 1, '2026-10-16 07:51:31'),
(11, 10, 1, '2026-10-16 07:51:42'),
(12, 12, 3, '2026-10-16 08:02:32'),
(13, 11, 1, '2026-10-16 08:02:36'),
(14, 13, 1, '2026-10-16 08:02:39'),
(15, 14, 1, '2026-10-16 08:02:50'),
(16, 15, 1, '2026-10-16 08:08:36'),
(17, 16, 1, '2026-10-16 08:08:52'),
(18, 17, 1, '2026-10-16 08:30:18'),
(19, 19, 1, '2026-10-16 08:30:40');

-- --------------------------------------------------------

--
-- Table structure for table `jobs`
--

CREATE TABLE `jobs` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `queue` varchar(255) NOT NULL,
  `payload` longtext NOT NULL,
  `attempts` smallint(5) UNSIGNED NOT NULL,
  `reserved_at` int(10) UNSIGNED DEFAULT NULL,
  `available_at` int(10) UNSIGNED NOT NULL,
  `created_at` int(10) UNSIGNED NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `jobs`
--

INSERT INTO `jobs` (`id`, `queue`, `payload`, `attempts`, `reserved_at`, `available_at`, `created_at`) VALUES
(92, 'default', '{\"uuid\":\"8c5142d4-9a63-4721-aabc-9e069009337e\",\"displayName\":\"App\\\\Jobs\\\\RecordAuditEntry\",\"job\":\"Illuminate\\\\Queue\\\\CallQueuedHandler@call\",\"maxTries\":null,\"maxExceptions\":null,\"failOnTimeout\":false,\"countCrashesAsExceptions\":false,\"backoff\":null,\"timeout\":null,\"retryUntil\":null,\"deleteWhenMissingModels\":false,\"data\":{\"commandName\":\"App\\\\Jobs\\\\RecordAuditEntry\",\"command\":\"O:25:\\\"App\\\\Jobs\\\\RecordAuditEntry\\\":8:{s:6:\\\"action\\\";s:11:\\\"screen.view\\\";s:13:\\\"auditableType\\\";s:30:\\\"App\\\\Filament\\\\Pages\\\\LiveScreens\\\";s:11:\\\"auditableId\\\";N;s:10:\\\"properties\\\";a:1:{s:5:\\\"scope\\\";s:27:\\\"all lab PCs (administrator)\\\";}s:6:\\\"userId\\\";i:1;s:9:\\\"ipAddress\\\";s:9:\\\"127.0.0.1\\\";s:9:\\\"userAgent\\\";s:111:\\\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/155.0.0.0 Safari\\/537.36\\\";s:5:\\\"queue\\\";s:7:\\\"default\\\";}\",\"batchId\":null},\"createdAt\":1792600732,\"delay\":null}', 0, NULL, 1792600732, 1792600732),
(93, 'default', '{\"uuid\":\"7da3dc4c-99a5-4c07-960f-ce9deb6b66f7\",\"displayName\":\"App\\\\Jobs\\\\RecordAuditEntry\",\"job\":\"Illuminate\\\\Queue\\\\CallQueuedHandler@call\",\"maxTries\":null,\"maxExceptions\":null,\"failOnTimeout\":false,\"countCrashesAsExceptions\":false,\"backoff\":null,\"timeout\":null,\"retryUntil\":null,\"deleteWhenMissingModels\":false,\"data\":{\"commandName\":\"App\\\\Jobs\\\\RecordAuditEntry\",\"command\":\"O:25:\\\"App\\\\Jobs\\\\RecordAuditEntry\\\":8:{s:6:\\\"action\\\";s:11:\\\"screen.view\\\";s:13:\\\"auditableType\\\";s:30:\\\"App\\\\Filament\\\\Pages\\\\LiveScreens\\\";s:11:\\\"auditableId\\\";N;s:10:\\\"properties\\\";a:1:{s:5:\\\"scope\\\";s:27:\\\"all lab PCs (administrator)\\\";}s:6:\\\"userId\\\";i:1;s:9:\\\"ipAddress\\\";s:9:\\\"127.0.0.1\\\";s:9:\\\"userAgent\\\";s:111:\\\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/155.0.0.0 Safari\\/537.36\\\";s:5:\\\"queue\\\";s:7:\\\"default\\\";}\",\"batchId\":null},\"createdAt\":1792600737,\"delay\":null}', 0, NULL, 1792600737, 1792600737),
(94, 'default', '{\"uuid\":\"c561467f-e757-4303-869d-24f02be4dbfd\",\"displayName\":\"App\\\\Jobs\\\\RecordAuditEntry\",\"job\":\"Illuminate\\\\Queue\\\\CallQueuedHandler@call\",\"maxTries\":null,\"maxExceptions\":null,\"failOnTimeout\":false,\"countCrashesAsExceptions\":false,\"backoff\":null,\"timeout\":null,\"retryUntil\":null,\"deleteWhenMissingModels\":false,\"data\":{\"commandName\":\"App\\\\Jobs\\\\RecordAuditEntry\",\"command\":\"O:25:\\\"App\\\\Jobs\\\\RecordAuditEntry\\\":8:{s:6:\\\"action\\\";s:11:\\\"screen.view\\\";s:13:\\\"auditableType\\\";s:30:\\\"App\\\\Filament\\\\Pages\\\\LiveScreens\\\";s:11:\\\"auditableId\\\";N;s:10:\\\"properties\\\";a:1:{s:5:\\\"scope\\\";s:27:\\\"all lab PCs (administrator)\\\";}s:6:\\\"userId\\\";i:1;s:9:\\\"ipAddress\\\";s:9:\\\"127.0.0.1\\\";s:9:\\\"userAgent\\\";s:111:\\\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/155.0.0.0 Safari\\/537.36\\\";s:5:\\\"queue\\\";s:7:\\\"default\\\";}\",\"batchId\":null},\"createdAt\":1792600741,\"delay\":null}', 0, NULL, 1792600741, 1792600741);

-- --------------------------------------------------------

--
-- Table structure for table `job_batches`
--

CREATE TABLE `job_batches` (
  `id` varchar(255) NOT NULL,
  `name` varchar(255) NOT NULL,
  `total_jobs` int(11) NOT NULL,
  `pending_jobs` int(11) NOT NULL,
  `failed_jobs` int(11) NOT NULL,
  `failed_job_ids` longtext NOT NULL,
  `options` mediumtext DEFAULT NULL,
  `cancelled_at` int(11) DEFAULT NULL,
  `created_at` int(11) NOT NULL,
  `finished_at` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `migrations`
--

CREATE TABLE `migrations` (
  `id` int(10) UNSIGNED NOT NULL,
  `migration` varchar(255) NOT NULL,
  `batch` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `migrations`
--

INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES
(1, '0001_01_01_000000_create_users_table', 1),
(2, '0001_01_01_000001_create_cache_table', 1),
(3, '0001_01_01_000002_create_jobs_table', 1),
(4, '2026_10_04_000001_create_year_sections_table', 1),
(5, '2026_10_04_000002_create_pcs_table', 1),
(6, '2026_10_04_000003_add_lab_columns_to_users_table', 1),
(7, '2026_10_04_000004_backfill_and_tighten_users_table', 1),
(8, '2026_10_04_000005_create_pc_sessions_table', 1),
(9, '2026_10_04_000006_create_schedules_table', 1),
(10, '2026_10_04_000007_create_class_sessions_table', 1),
(11, '2026_10_04_000008_create_attendances_table', 1),
(12, '2026_10_04_000009_create_stored_files_table', 1),
(13, '2026_10_04_000013_create_group_chats_table', 1),
(14, '2026_10_04_000014_create_group_chat_members_table', 1),
(15, '2026_10_04_000015_create_group_chat_messages_table', 1),
(16, '2026_10_04_000016_create_group_message_reads_table', 1),
(17, '2026_10_04_000017_create_accessibility_settings_table', 1),
(18, '2026_10_04_000018_create_audit_logs_table', 1),
(19, '2026_10_04_000019_create_pc_clients_table', 1),
(20, '2026_10_06_184827_drop_direct_messaging_tables', 1);

-- --------------------------------------------------------

--
-- Table structure for table `password_reset_tokens`
--

CREATE TABLE `password_reset_tokens` (
  `email` varchar(255) NOT NULL,
  `token` varchar(255) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `pcs`
--

CREATE TABLE `pcs` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `name` varchar(100) NOT NULL,
  `ip_address` varchar(45) NOT NULL,
  `mac_address` varchar(17) DEFAULT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'offline',
  `last_seen_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `pcs`
--

INSERT INTO `pcs` (`id`, `name`, `ip_address`, `mac_address`, `status`, `last_seen_at`, `created_at`, `updated_at`) VALUES
(1, 'Joey-PC', '192.168.1.34', NULL, 'offline', NULL, '2026-10-09 06:08:56', '2026-10-09 09:45:03'),
(2, 'koben', '192.168.11.174', NULL, 'offline', NULL, '2026-10-09 08:45:24', '2026-10-09 08:45:24'),
(3, 'Epoy-PC', '192.168.254.124', NULL, 'offline', NULL, '2026-10-13 06:34:23', '2026-10-19 06:25:04');

-- --------------------------------------------------------

--
-- Table structure for table `pc_clients`
--

CREATE TABLE `pc_clients` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `pc_id` bigint(20) UNSIGNED NOT NULL,
  `token_hash` varchar(64) NOT NULL,
  `state` varchar(20) NOT NULL DEFAULT 'active',
  `hostname` varchar(255) DEFAULT NULL,
  `client_version` varchar(20) DEFAULT NULL,
  `last_seen_at` timestamp NULL DEFAULT NULL,
  `last_frame_at` timestamp NULL DEFAULT NULL,
  `enrolled_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `revoked_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `pc_clients`
--

INSERT INTO `pc_clients` (`id`, `pc_id`, `token_hash`, `state`, `hostname`, `client_version`, `last_seen_at`, `last_frame_at`, `enrolled_at`, `revoked_at`, `created_at`, `updated_at`) VALUES
(1, 1, '57372e3cc034231e3af813d1fa379946041556197a2e57bd3deb4bd22ec378f8', 'active', 'Sungit', '1.0.0', '2026-10-09 06:15:45', '2026-10-09 06:15:45', '2026-10-09 06:10:21', NULL, '2026-10-09 06:10:21', '2026-10-09 06:15:45'),
(2, 2, 'aa7ab1f29f4b00c45d595629365898f13bfebb4af92434cff5bde95558b04dd4', 'active', 'DESKTOP-B77LL9T', '1.0.0', '2026-10-09 10:51:04', NULL, '2026-10-16 06:08:36', NULL, '2026-10-09 09:01:01', '2026-10-16 06:08:36'),
(3, 3, 'f01bf1d478f6dafb73ef8112b7c17a4e3e28933a1bb7fbdd5b608bc6d569ef74', 'active', 'DESKTOP-8SLNIFV', '1.0.0', '2026-10-19 10:09:47', '2026-10-19 10:09:47', '2026-10-18 06:23:14', NULL, '2026-10-13 06:34:31', '2026-10-19 10:09:47');

-- --------------------------------------------------------

--
-- Table structure for table `pc_sessions`
--

CREATE TABLE `pc_sessions` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `user_id` bigint(20) UNSIGNED NOT NULL,
  `pc_id` bigint(20) UNSIGNED NOT NULL,
  `started_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `ended_at` timestamp NULL DEFAULT NULL,
  `duration_minutes` int(10) UNSIGNED DEFAULT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'active',
  `terminated_by_id` bigint(20) UNSIGNED DEFAULT NULL,
  `termination_reason` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `pc_sessions`
--

INSERT INTO `pc_sessions` (`id`, `user_id`, `pc_id`, `started_at`, `ended_at`, `duration_minutes`, `status`, `terminated_by_id`, `termination_reason`, `created_at`, `updated_at`) VALUES
(1, 1, 1, '2026-10-09 09:30:55', NULL, NULL, 'active', NULL, NULL, '2026-10-09 09:30:55', '2026-10-09 09:30:55'),
(2, 3, 3, '2026-10-19 06:12:38', '2026-10-19 06:12:42', 0, 'completed', NULL, NULL, '2026-10-19 06:12:38', '2026-10-19 06:12:42'),
(3, 3, 3, '2026-10-19 06:12:42', NULL, NULL, 'active', NULL, NULL, '2026-10-19 06:12:42', '2026-10-19 06:12:42');

-- --------------------------------------------------------

--
-- Table structure for table `schedules`
--

CREATE TABLE `schedules` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `teacher_id` bigint(20) UNSIGNED DEFAULT NULL,
  `year_section_id` bigint(20) UNSIGNED DEFAULT NULL,
  `title` varchar(200) NOT NULL,
  `description` text DEFAULT NULL,
  `starts_at` datetime NOT NULL,
  `ends_at` datetime NOT NULL,
  `purpose` varchar(200) DEFAULT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'scheduled',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `schedules`
--

INSERT INTO `schedules` (`id`, `teacher_id`, `year_section_id`, `title`, `description`, `starts_at`, `ends_at`, `purpose`, `status`, `created_at`, `updated_at`) VALUES
(2, 1, NULL, 'one', NULL, '2026-10-16 21:30:00', '2026-10-16 22:30:00', NULL, 'cancelled', '2026-10-09 10:49:21', '2026-10-16 05:34:48');

-- --------------------------------------------------------

--
-- Table structure for table `sessions`
--

CREATE TABLE `sessions` (
  `id` varchar(255) NOT NULL,
  `user_id` bigint(20) UNSIGNED DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` text DEFAULT NULL,
  `payload` longtext NOT NULL,
  `last_activity` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `sessions`
--

INSERT INTO `sessions` (`id`, `user_id`, `ip_address`, `user_agent`, `payload`, `last_activity`) VALUES
('Sdn9kFDLHmcTCVqTC1vaJwIfHcVi9habcqkSNgbO', 1, '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/155.0.0.0 Safari/537.36', 'eyJfdG9rZW4iOiJTc3dEZmNNNFVIZHp3VTVMeFYzRkIxc0xvd3d3NkNpVERYZlR3eFN1IiwiX3ByZXZpb3VzIjp7InVybCI6Imh0dHA6XC9cL2tvYmVubGFicy50ZXN0XC9hZG1pblwvbW9uaXRvcmluZyIsInJvdXRlIjoiZmlsYW1lbnQuYWRtaW4ucGFnZXMubW9uaXRvcmluZyJ9LCJfZmxhc2giOnsib2xkIjpbXSwibmV3IjpbXX0sImxvZ2luX3dlYl81OWJhMzZhZGRjMmIyZjk0MDE1ODBmMDE0YzdmNThlYTRlMzA5ODlkIjoxLCJwYXNzd29yZF9oYXNoX3dlYiI6IjEzOGYzZTQyZjE4Y2E4MjU0YmU1ZjM2YjNlMmE5ZDQzYjA0OGY2YTZkNDU3NjBhOTIyOGJlOTBlZWIxNzUxMzQiLCJ0YWJsZXMiOnsiMDNhNDRkOGFkZDhiYTg0N2RjMDNlMzExOTkzMzQyNWNfY29sdW1ucyI6W3sidHlwZSI6ImNvbHVtbiIsIm5hbWUiOiJ0aXRsZSIsImxhYmVsIjoiU3ViamVjdCIsImlzSGlkZGVuIjpmYWxzZSwiaXNUb2dnbGVkIjp0cnVlLCJpc1RvZ2dsZWFibGUiOmZhbHNlLCJpc1RvZ2dsZWRIaWRkZW5CeURlZmF1bHQiOm51bGx9LHsidHlwZSI6ImNvbHVtbiIsIm5hbWUiOiJkYXlfb2Zfd2VlayIsImxhYmVsIjoiRGF5IiwiaXNIaWRkZW4iOmZhbHNlLCJpc1RvZ2dsZWQiOnRydWUsImlzVG9nZ2xlYWJsZSI6ZmFsc2UsImlzVG9nZ2xlZEhpZGRlbkJ5RGVmYXVsdCI6bnVsbH0seyJ0eXBlIjoiY29sdW1uIiwibmFtZSI6InN0YXJ0c19hdCIsImxhYmVsIjoiU3RhcnRzIiwiaXNIaWRkZW4iOmZhbHNlLCJpc1RvZ2dsZWQiOmZhbHNlLCJpc1RvZ2dsZWFibGUiOnRydWUsImlzVG9nZ2xlZEhpZGRlbkJ5RGVmYXVsdCI6dHJ1ZX0seyJ0eXBlIjoiY29sdW1uIiwibmFtZSI6ImR1cmF0aW9uX21pbnV0ZXMiLCJsYWJlbCI6Ikxlbmd0aCIsImlzSGlkZGVuIjpmYWxzZSwiaXNUb2dnbGVkIjp0cnVlLCJpc1RvZ2dsZWFibGUiOmZhbHNlLCJpc1RvZ2dsZWRIaWRkZW5CeURlZmF1bHQiOm51bGx9LHsidHlwZSI6ImNvbHVtbiIsIm5hbWUiOiJ0ZWFjaGVyLmZ1bGxfbmFtZSIsImxhYmVsIjoiVGVhY2hlciIsImlzSGlkZGVuIjpmYWxzZSwiaXNUb2dnbGVkIjp0cnVlLCJpc1RvZ2dsZWFibGUiOnRydWUsImlzVG9nZ2xlZEhpZGRlbkJ5RGVmYXVsdCI6ZmFsc2V9LHsidHlwZSI6ImNvbHVtbiIsIm5hbWUiOiJ5ZWFyU2VjdGlvbi5sYWJlbCIsImxhYmVsIjoiWWVhciBhbmQgc2VjdGlvbiIsImlzSGlkZGVuIjpmYWxzZSwiaXNUb2dnbGVkIjp0cnVlLCJpc1RvZ2dsZWFibGUiOnRydWUsImlzVG9nZ2xlZEhpZGRlbkJ5RGVmYXVsdCI6ZmFsc2V9LHsidHlwZSI6ImNvbHVtbiIsIm5hbWUiOiJzdGF0dXMiLCJsYWJlbCI6IlN0YXR1cyIsImlzSGlkZGVuIjpmYWxzZSwiaXNUb2dnbGVkIjp0cnVlLCJpc1RvZ2dsZWFibGUiOmZhbHNlLCJpc1RvZ2dsZWRIaWRkZW5CeURlZmF1bHQiOm51bGx9XSwiYjg4YTRkMWY1YzMzODYyZmFkMTNjNGIzYWI0ZWE3Y2FfY29sdW1ucyI6W3sidHlwZSI6ImNvbHVtbiIsIm5hbWUiOiJzY2hlZHVsZS50aXRsZSIsImxhYmVsIjoiU3ViamVjdCIsImlzSGlkZGVuIjpmYWxzZSwiaXNUb2dnbGVkIjp0cnVlLCJpc1RvZ2dsZWFibGUiOmZhbHNlLCJpc1RvZ2dsZWRIaWRkZW5CeURlZmF1bHQiOm51bGx9LHsidHlwZSI6ImNvbHVtbiIsIm5hbWUiOiJzY2hlZHVsZS55ZWFyU2VjdGlvbi5sYWJlbCIsImxhYmVsIjoiWWVhciBhbmQgc2VjdGlvbiIsImlzSGlkZGVuIjpmYWxzZSwiaXNUb2dnbGVkIjp0cnVlLCJpc1RvZ2dsZWFibGUiOnRydWUsImlzVG9nZ2xlZEhpZGRlbkJ5RGVmYXVsdCI6ZmFsc2V9LHsidHlwZSI6ImNvbHVtbiIsIm5hbWUiOiJzdGFydGVkQnkuZnVsbF9uYW1lIiwibGFiZWwiOiJUZWFjaGVyIiwiaXNIaWRkZW4iOmZhbHNlLCJpc1RvZ2dsZWQiOnRydWUsImlzVG9nZ2xlYWJsZSI6dHJ1ZSwiaXNUb2dnbGVkSGlkZGVuQnlEZWZhdWx0IjpmYWxzZX0seyJ0eXBlIjoiY29sdW1uIiwibmFtZSI6ImF0dGVuZGFuY2VfY291bnQiLCJsYWJlbCI6IkF0dGVuZGVlcyIsImlzSGlkZGVuIjpmYWxzZSwiaXNUb2dnbGVkIjp0cnVlLCJpc1RvZ2dsZWFibGUiOmZhbHNlLCJpc1RvZ2dsZWRIaWRkZW5CeURlZmF1bHQiOm51bGx9LHsidHlwZSI6ImNvbHVtbiIsIm5hbWUiOiJzdGFydGVkX2F0IiwibGFiZWwiOiJTdGFydGVkIiwiaXNIaWRkZW4iOmZhbHNlLCJpc1RvZ2dsZWQiOnRydWUsImlzVG9nZ2xlYWJsZSI6ZmFsc2UsImlzVG9nZ2xlZEhpZGRlbkJ5RGVmYXVsdCI6bnVsbH0seyJ0eXBlIjoiY29sdW1uIiwibmFtZSI6ImVuZGVkX2F0IiwibGFiZWwiOiJFbmRlZCIsImlzSGlkZGVuIjpmYWxzZSwiaXNUb2dnbGVkIjp0cnVlLCJpc1RvZ2dsZWFibGUiOnRydWUsImlzVG9nZ2xlZEhpZGRlbkJ5RGVmYXVsdCI6ZmFsc2V9LHsidHlwZSI6ImNvbHVtbiIsIm5hbWUiOiJzdGF0dXMiLCJsYWJlbCI6IlN0YXR1cyIsImlzSGlkZGVuIjpmYWxzZSwiaXNUb2dnbGVkIjp0cnVlLCJpc1RvZ2dsZWFibGUiOmZhbHNlLCJpc1RvZ2dsZWRIaWRkZW5CeURlZmF1bHQiOm51bGx9XSwiZTA2ODEzOTVmMjJiYzkzM2YzNzU5ZWU4N2Y4MGJmYjVfY29sdW1ucyI6W3sidHlwZSI6ImNvbHVtbiIsIm5hbWUiOiJ1c2VyLmZ1bGxfbmFtZSIsImxhYmVsIjoiU3R1ZGVudCIsImlzSGlkZGVuIjpmYWxzZSwiaXNUb2dnbGVkIjp0cnVlLCJpc1RvZ2dsZWFibGUiOmZhbHNlLCJpc1RvZ2dsZWRIaWRkZW5CeURlZmF1bHQiOm51bGx9LHsidHlwZSI6ImNvbHVtbiIsIm5hbWUiOiJ1c2VyLnN0dWRlbnRfaWQiLCJsYWJlbCI6IlN0dWRlbnQgSUQiLCJpc0hpZGRlbiI6ZmFsc2UsImlzVG9nZ2xlZCI6dHJ1ZSwiaXNUb2dnbGVhYmxlIjpmYWxzZSwiaXNUb2dnbGVkSGlkZGVuQnlEZWZhdWx0IjpudWxsfSx7InR5cGUiOiJjb2x1bW4iLCJuYW1lIjoidXNlci55ZWFyU2VjdGlvbi5sYWJlbCIsImxhYmVsIjoiWWVhciBhbmQgc2VjdGlvbiIsImlzSGlkZGVuIjpmYWxzZSwiaXNUb2dnbGVkIjp0cnVlLCJpc1RvZ2dsZWFibGUiOnRydWUsImlzVG9nZ2xlZEhpZGRlbkJ5RGVmYXVsdCI6ZmFsc2V9LHsidHlwZSI6ImNvbHVtbiIsIm5hbWUiOiJ1c2VyLnN0YXR1cyIsImxhYmVsIjoiVXNlciIsImlzSGlkZGVuIjpmYWxzZSwiaXNUb2dnbGVkIjp0cnVlLCJpc1RvZ2dsZWFibGUiOmZhbHNlLCJpc1RvZ2dsZWRIaWRkZW5CeURlZmF1bHQiOm51bGx9LHsidHlwZSI6ImNvbHVtbiIsIm5hbWUiOiJqb2luZWRfYXQiLCJsYWJlbCI6IkpvaW5lZCIsImlzSGlkZGVuIjpmYWxzZSwiaXNUb2dnbGVkIjp0cnVlLCJpc1RvZ2dsZWFibGUiOmZhbHNlLCJpc1RvZ2dsZWRIaWRkZW5CeURlZmF1bHQiOm51bGx9XSwiOWVlMDEyZWZhODRlNTQxYzNhODIwMDgyZTUxMmVmNDBfY29sdW1ucyI6W3sidHlwZSI6ImNvbHVtbiIsIm5hbWUiOiJpc19mb2xkZXIiLCJsYWJlbCI6IktpbmQiLCJpc0hpZGRlbiI6ZmFsc2UsImlzVG9nZ2xlZCI6dHJ1ZSwiaXNUb2dnbGVhYmxlIjpmYWxzZSwiaXNUb2dnbGVkSGlkZGVuQnlEZWZhdWx0IjpudWxsfSx7InR5cGUiOiJjb2x1bW4iLCJuYW1lIjoibmFtZSIsImxhYmVsIjoiTmFtZSIsImlzSGlkZGVuIjpmYWxzZSwiaXNUb2dnbGVkIjp0cnVlLCJpc1RvZ2dsZWFibGUiOmZhbHNlLCJpc1RvZ2dsZWRIaWRkZW5CeURlZmF1bHQiOm51bGx9LHsidHlwZSI6ImNvbHVtbiIsIm5hbWUiOiJ1c2VyLmZ1bGxfbmFtZSIsImxhYmVsIjoiT3duZXIiLCJpc0hpZGRlbiI6ZmFsc2UsImlzVG9nZ2xlZCI6dHJ1ZSwiaXNUb2dnbGVhYmxlIjpmYWxzZSwiaXNUb2dnbGVkSGlkZGVuQnlEZWZhdWx0IjpudWxsfSx7InR5cGUiOiJjb2x1bW4iLCJuYW1lIjoiaHVtYW5fc2l6ZSIsImxhYmVsIjoiU2l6ZSIsImlzSGlkZGVuIjpmYWxzZSwiaXNUb2dnbGVkIjp0cnVlLCJpc1RvZ2dsZWFibGUiOmZhbHNlLCJpc1RvZ2dsZWRIaWRkZW5CeURlZmF1bHQiOm51bGx9LHsidHlwZSI6ImNvbHVtbiIsIm5hbWUiOiJtaW1lX3R5cGUiLCJsYWJlbCI6IlR5cGUiLCJpc0hpZGRlbiI6ZmFsc2UsImlzVG9nZ2xlZCI6ZmFsc2UsImlzVG9nZ2xlYWJsZSI6dHJ1ZSwiaXNUb2dnbGVkSGlkZGVuQnlEZWZhdWx0Ijp0cnVlfSx7InR5cGUiOiJjb2x1bW4iLCJuYW1lIjoiY3JlYXRlZF9hdCIsImxhYmVsIjoiQWRkZWQiLCJpc0hpZGRlbiI6ZmFsc2UsImlzVG9nZ2xlZCI6ZmFsc2UsImlzVG9nZ2xlYWJsZSI6dHJ1ZSwiaXNUb2dnbGVkSGlkZGVuQnlEZWZhdWx0Ijp0cnVlfV19fQ==', 1792601078);

-- --------------------------------------------------------

--
-- Table structure for table `stored_files`
--

CREATE TABLE `stored_files` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `user_id` bigint(20) UNSIGNED NOT NULL,
  `name` varchar(255) NOT NULL,
  `original_name` varchar(255) NOT NULL,
  `path` varchar(500) NOT NULL,
  `size` bigint(20) UNSIGNED NOT NULL DEFAULT 0,
  `mime_type` varchar(100) DEFAULT NULL,
  `parent_id` bigint(20) UNSIGNED DEFAULT NULL,
  `is_folder` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `username` varchar(50) NOT NULL,
  `email` varchar(255) NOT NULL,
  `first_name` varchar(50) NOT NULL,
  `last_name` varchar(50) NOT NULL,
  `student_id` varchar(20) DEFAULT NULL,
  `year_section_id` bigint(20) UNSIGNED DEFAULT NULL,
  `role` varchar(20) NOT NULL DEFAULT 'student',
  `status` varchar(20) NOT NULL DEFAULT 'active',
  `storage_quota_mb` int(10) UNSIGNED NOT NULL DEFAULT 500,
  `storage_used_mb` decimal(10,2) NOT NULL DEFAULT 0.00,
  `last_login_at` timestamp NULL DEFAULT NULL,
  `email_verified_at` timestamp NULL DEFAULT NULL,
  `password` varchar(255) NOT NULL,
  `remember_token` varchar(100) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `username`, `email`, `first_name`, `last_name`, `student_id`, `year_section_id`, `role`, `status`, `storage_quota_mb`, `storage_used_mb`, `last_login_at`, `email_verified_at`, `password`, `remember_token`, `created_at`, `updated_at`) VALUES
(1, 'koben', 'admin@example.com', 'Lab', 'Administrator', NULL, NULL, 'admin', 'active', 500, 0.00, NULL, '2026-10-09 05:55:35', '$2y$12$ZkuIjqTSFpE3K4aCBaGTuecpmzppE0k36za1iJby55zaklRVaSAN.', 'ZkVZ8SZcB5', '2026-10-09 05:55:36', '2026-10-20 07:26:28'),
(2, 'student', 'student@kobenlabs.local', 'Student', 'User', NULL, NULL, 'student', 'active', 500, 0.00, '2026-10-09 10:43:07', NULL, '$2y$12$eKP3VRDr.2OqeP8go/0ZP.27wTSZxt0MUcdT4y5xVKs/6FAyrWdRu', 'gGTlfi8BdjGY6aZXUpaD7wT1hKsEyQm3JDF0ik3bUGmKn4klulGuqtCnvc8B', '2026-10-09 16:51:57', '2026-10-09 10:43:07'),
(3, 'haru187', 'haru187@gmail.com', 'haru', 'Ramas', '696969', NULL, 'student', 'active', 500, 0.00, NULL, NULL, '$2y$12$PeBexa6GLcYExiKpDW42wujEHBnVjF8o4SiVniuerXrkyxO5m/9fu', NULL, '2026-10-16 06:19:15', '2026-10-16 06:19:15');

-- --------------------------------------------------------

--
-- Table structure for table `year_sections`
--

CREATE TABLE `year_sections` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `year` varchar(50) NOT NULL,
  `section` varchar(50) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `year_sections`
--

INSERT INTO `year_sections` (`id`, `year`, `section`, `is_active`, `created_at`, `updated_at`) VALUES
(1, 'BSIT-1', 'A', 1, '2026-10-16 06:22:20', '2026-10-16 06:22:20');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `accessibility_settings`
--
ALTER TABLE `accessibility_settings`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `accessibility_settings_user_id_unique` (`user_id`);

--
-- Indexes for table `attendances`
--
ALTER TABLE `attendances`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `attendances_class_session_id_user_id_unique` (`class_session_id`,`user_id`),
  ADD KEY `attendances_user_id_foreign` (`user_id`);

--
-- Indexes for table `audit_logs`
--
ALTER TABLE `audit_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `audit_logs_user_id_foreign` (`user_id`),
  ADD KEY `audit_logs_action_index` (`action`),
  ADD KEY `audit_logs_created_at_index` (`created_at`),
  ADD KEY `audit_logs_auditable_type_auditable_id_index` (`auditable_type`,`auditable_id`);

--
-- Indexes for table `cache`
--
ALTER TABLE `cache`
  ADD PRIMARY KEY (`key`),
  ADD KEY `cache_expiration_index` (`expiration`);

--
-- Indexes for table `cache_locks`
--
ALTER TABLE `cache_locks`
  ADD PRIMARY KEY (`key`),
  ADD KEY `cache_locks_expiration_index` (`expiration`);

--
-- Indexes for table `class_sessions`
--
ALTER TABLE `class_sessions`
  ADD PRIMARY KEY (`id`),
  ADD KEY `class_sessions_schedule_id_foreign` (`schedule_id`),
  ADD KEY `class_sessions_status_index` (`status`),
  ADD KEY `class_sessions_started_at_index` (`started_at`),
  ADD KEY `class_sessions_started_by_id_index` (`started_by_id`);

--
-- Indexes for table `failed_jobs`
--
ALTER TABLE `failed_jobs`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `failed_jobs_uuid_unique` (`uuid`),
  ADD KEY `failed_jobs_connection_queue_failed_at_index` (`connection`,`queue`,`failed_at`);

--
-- Indexes for table `group_chats`
--
ALTER TABLE `group_chats`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `group_chats_join_code_unique` (`join_code`),
  ADD KEY `group_chats_created_by_id_index` (`created_by_id`),
  ADD KEY `group_chats_is_active_index` (`is_active`);

--
-- Indexes for table `group_chat_members`
--
ALTER TABLE `group_chat_members`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `group_chat_members_group_chat_id_user_id_unique` (`group_chat_id`,`user_id`),
  ADD KEY `group_chat_members_user_id_foreign` (`user_id`);

--
-- Indexes for table `group_chat_messages`
--
ALTER TABLE `group_chat_messages`
  ADD PRIMARY KEY (`id`),
  ADD KEY `group_chat_messages_sender_id_index` (`sender_id`),
  ADD KEY `group_chat_messages_reply_to_id_index` (`reply_to_id`),
  ADD KEY `group_chat_messages_chat_created_index` (`group_chat_id`,`created_at`);

--
-- Indexes for table `group_message_reads`
--
ALTER TABLE `group_message_reads`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `group_message_reads_message_id_user_id_unique` (`message_id`,`user_id`),
  ADD KEY `group_message_reads_user_id_foreign` (`user_id`);

--
-- Indexes for table `jobs`
--
ALTER TABLE `jobs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `jobs_queue_index` (`queue`);

--
-- Indexes for table `job_batches`
--
ALTER TABLE `job_batches`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `migrations`
--
ALTER TABLE `migrations`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `password_reset_tokens`
--
ALTER TABLE `password_reset_tokens`
  ADD PRIMARY KEY (`email`);

--
-- Indexes for table `pcs`
--
ALTER TABLE `pcs`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `pcs_name_unique` (`name`),
  ADD UNIQUE KEY `pcs_ip_address_unique` (`ip_address`),
  ADD KEY `pcs_status_index` (`status`);

--
-- Indexes for table `pc_clients`
--
ALTER TABLE `pc_clients`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `pc_clients_pc_id_unique` (`pc_id`),
  ADD UNIQUE KEY `pc_clients_token_hash_unique` (`token_hash`),
  ADD KEY `pc_clients_state_seen_index` (`state`,`last_seen_at`);

--
-- Indexes for table `pc_sessions`
--
ALTER TABLE `pc_sessions`
  ADD PRIMARY KEY (`id`),
  ADD KEY `pc_sessions_user_id_foreign` (`user_id`),
  ADD KEY `pc_sessions_pc_id_foreign` (`pc_id`),
  ADD KEY `pc_sessions_status_index` (`status`),
  ADD KEY `pc_sessions_started_at_index` (`started_at`),
  ADD KEY `pc_sessions_terminated_by_id_index` (`terminated_by_id`);

--
-- Indexes for table `schedules`
--
ALTER TABLE `schedules`
  ADD PRIMARY KEY (`id`),
  ADD KEY `schedules_teacher_id_index` (`teacher_id`),
  ADD KEY `schedules_year_section_id_index` (`year_section_id`),
  ADD KEY `schedules_starts_at_index` (`starts_at`),
  ADD KEY `schedules_status_index` (`status`);

--
-- Indexes for table `sessions`
--
ALTER TABLE `sessions`
  ADD PRIMARY KEY (`id`),
  ADD KEY `sessions_user_id_index` (`user_id`),
  ADD KEY `sessions_last_activity_index` (`last_activity`);

--
-- Indexes for table `stored_files`
--
ALTER TABLE `stored_files`
  ADD PRIMARY KEY (`id`),
  ADD KEY `stored_files_user_id_foreign` (`user_id`),
  ADD KEY `stored_files_parent_id_index` (`parent_id`),
  ADD KEY `stored_files_is_folder_index` (`is_folder`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `users_email_unique` (`email`),
  ADD UNIQUE KEY `users_username_unique` (`username`),
  ADD UNIQUE KEY `users_student_id_unique` (`student_id`),
  ADD KEY `users_role_index` (`role`),
  ADD KEY `users_status_index` (`status`),
  ADD KEY `users_year_section_id_index` (`year_section_id`);

--
-- Indexes for table `year_sections`
--
ALTER TABLE `year_sections`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `year_sections_year_section_unique` (`year`,`section`),
  ADD KEY `year_sections_year_index` (`year`),
  ADD KEY `year_sections_is_active_index` (`is_active`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `accessibility_settings`
--
ALTER TABLE `accessibility_settings`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `attendances`
--
ALTER TABLE `attendances`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `audit_logs`
--
ALTER TABLE `audit_logs`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=92;

--
-- AUTO_INCREMENT for table `class_sessions`
--
ALTER TABLE `class_sessions`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `failed_jobs`
--
ALTER TABLE `failed_jobs`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `group_chats`
--
ALTER TABLE `group_chats`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `group_chat_members`
--
ALTER TABLE `group_chat_members`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `group_chat_messages`
--
ALTER TABLE `group_chat_messages`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=20;

--
-- AUTO_INCREMENT for table `group_message_reads`
--
ALTER TABLE `group_message_reads`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=20;

--
-- AUTO_INCREMENT for table `jobs`
--
ALTER TABLE `jobs`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=95;

--
-- AUTO_INCREMENT for table `migrations`
--
ALTER TABLE `migrations`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=21;

--
-- AUTO_INCREMENT for table `pcs`
--
ALTER TABLE `pcs`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `pc_clients`
--
ALTER TABLE `pc_clients`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `pc_sessions`
--
ALTER TABLE `pc_sessions`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `schedules`
--
ALTER TABLE `schedules`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `stored_files`
--
ALTER TABLE `stored_files`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `year_sections`
--
ALTER TABLE `year_sections`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `accessibility_settings`
--
ALTER TABLE `accessibility_settings`
  ADD CONSTRAINT `accessibility_settings_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `attendances`
--
ALTER TABLE `attendances`
  ADD CONSTRAINT `attendances_class_session_id_foreign` FOREIGN KEY (`class_session_id`) REFERENCES `class_sessions` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `attendances_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `audit_logs`
--
ALTER TABLE `audit_logs`
  ADD CONSTRAINT `audit_logs_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `class_sessions`
--
ALTER TABLE `class_sessions`
  ADD CONSTRAINT `class_sessions_schedule_id_foreign` FOREIGN KEY (`schedule_id`) REFERENCES `schedules` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `class_sessions_started_by_id_foreign` FOREIGN KEY (`started_by_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `group_chats`
--
ALTER TABLE `group_chats`
  ADD CONSTRAINT `group_chats_created_by_id_foreign` FOREIGN KEY (`created_by_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `group_chat_members`
--
ALTER TABLE `group_chat_members`
  ADD CONSTRAINT `group_chat_members_group_chat_id_foreign` FOREIGN KEY (`group_chat_id`) REFERENCES `group_chats` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `group_chat_members_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `group_chat_messages`
--
ALTER TABLE `group_chat_messages`
  ADD CONSTRAINT `group_chat_messages_group_chat_id_foreign` FOREIGN KEY (`group_chat_id`) REFERENCES `group_chats` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `group_chat_messages_reply_to_id_foreign` FOREIGN KEY (`reply_to_id`) REFERENCES `group_chat_messages` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `group_chat_messages_sender_id_foreign` FOREIGN KEY (`sender_id`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `group_message_reads`
--
ALTER TABLE `group_message_reads`
  ADD CONSTRAINT `group_message_reads_message_id_foreign` FOREIGN KEY (`message_id`) REFERENCES `group_chat_messages` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `group_message_reads_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `pc_clients`
--
ALTER TABLE `pc_clients`
  ADD CONSTRAINT `pc_clients_pc_id_foreign` FOREIGN KEY (`pc_id`) REFERENCES `pcs` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `pc_sessions`
--
ALTER TABLE `pc_sessions`
  ADD CONSTRAINT `pc_sessions_pc_id_foreign` FOREIGN KEY (`pc_id`) REFERENCES `pcs` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `pc_sessions_terminated_by_id_foreign` FOREIGN KEY (`terminated_by_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `pc_sessions_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `schedules`
--
ALTER TABLE `schedules`
  ADD CONSTRAINT `schedules_teacher_id_foreign` FOREIGN KEY (`teacher_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `schedules_year_section_id_foreign` FOREIGN KEY (`year_section_id`) REFERENCES `year_sections` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `stored_files`
--
ALTER TABLE `stored_files`
  ADD CONSTRAINT `stored_files_parent_id_foreign` FOREIGN KEY (`parent_id`) REFERENCES `stored_files` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `stored_files_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `users`
--
ALTER TABLE `users`
  ADD CONSTRAINT `users_year_section_id_foreign` FOREIGN KEY (`year_section_id`) REFERENCES `year_sections` (`id`) ON DELETE SET NULL;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
