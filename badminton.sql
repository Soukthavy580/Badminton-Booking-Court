-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: May 08, 2026 at 04:43 AM
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
-- Database: `badminton_booking`
--

-- --------------------------------------------------------

--
-- Table structure for table `admin`
--

CREATE TABLE `admin` (
  `Admin_ID` int(11) NOT NULL,
  `Name` varchar(100) NOT NULL,
  `Surname` varchar(100) DEFAULT NULL,
  `Email` varchar(100) DEFAULT NULL,
  `Phone` varchar(20) DEFAULT NULL,
  `Gender` varchar(20) DEFAULT NULL,
  `Image_pay` varchar(255) DEFAULT NULL COMMENT 'QR code shown to owners for payment',
  `Username` varchar(100) NOT NULL,
  `Password` varchar(255) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `admin`
--

INSERT INTO `admin` (`Admin_ID`, `Name`, `Surname`, `Email`, `Phone`, `Gender`, `Image_pay`, `Username`, `Password`) VALUES
(1, 'Admin', 'System', 'admin1@badminton.com', '02077037520', 'Male', 'AdminQrcode_1773971335.jpg', 'admin', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi');

-- --------------------------------------------------------

--
-- Table structure for table `advertisement`
--

CREATE TABLE `advertisement` (
  `AD_ID` int(11) NOT NULL,
  `AD_date` datetime NOT NULL,
  `Start_time` datetime DEFAULT NULL,
  `End_time` datetime DEFAULT NULL,
  `Slip_payment` varchar(255) DEFAULT NULL,
  `Status_AD` varchar(50) NOT NULL DEFAULT 'Pending' COMMENT 'Pending, Approved, Active, Rejected',
  `VN_ID` int(11) NOT NULL,
  `AD_Rate_ID` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `advertisement`
--

INSERT INTO `advertisement` (`AD_ID`, `AD_date`, `Start_time`, `End_time`, `Slip_payment`, `Status_AD`, `VN_ID`, `AD_Rate_ID`) VALUES
(1, '2026-03-20 09:02:06', '2026-03-20 03:02:34', '2026-04-20 03:02:34', 'ad_1_1773972126.jpg', 'Expired', 1, 1),
(2, '2026-03-20 10:29:03', '2026-03-20 04:29:13', '2026-05-20 04:29:13', 'ad_2_1773977343.png', 'Approved', 2, 2),
(3, '2026-03-21 10:25:11', '2026-03-21 04:25:19', '2026-06-21 04:25:19', 'ad_3_1774063511.png', 'Approved', 3, 4),
(4, '2026-03-23 11:34:27', '2026-03-23 06:41:33', '2026-04-23 06:41:33', 'ad_4_1774240467.jpg', 'Expired', 4, 1),
(5, '2026-04-21 19:47:33', '2026-04-23 06:41:33', '2026-05-23 06:41:33', 'ad_4_1776775653.jpg', 'Approved', 4, 1);

-- --------------------------------------------------------

--
-- Table structure for table `advertisement_rate`
--

CREATE TABLE `advertisement_rate` (
  `AD_Rate_ID` int(11) NOT NULL,
  `Duration` varchar(50) NOT NULL,
  `Price` double NOT NULL,
  `Is_Popular` tinyint(1) NOT NULL DEFAULT 0,
  `Is_Best_Value` tinyint(1) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `advertisement_rate`
--

INSERT INTO `advertisement_rate` (`AD_Rate_ID`, `Duration`, `Price`, `Is_Popular`, `Is_Best_Value`) VALUES
(1, '1 ເດືອນ', 200000, 0, 0),
(2, '2 ເດືອນ', 350000, 1, 0),
(4, '3 ເດືອນ', 600000, 0, 0),
(6, '6 ເດືອນ', 1200000, 0, 0),
(9, '1 ປິ', 2000000, 0, 0);

-- --------------------------------------------------------

--
-- Table structure for table `approve_advertisement`
--

CREATE TABLE `approve_advertisement` (
  `AP_AD_ID` int(11) NOT NULL,
  `AD_ID` int(11) NOT NULL,
  `Admin_ID` int(11) NOT NULL,
  `Action` varchar(20) NOT NULL DEFAULT 'Approved',
  `Reject_reason` text DEFAULT NULL,
  `actioned_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `approve_advertisement`
--

INSERT INTO `approve_advertisement` (`AP_AD_ID`, `AD_ID`, `Admin_ID`, `Action`, `Reject_reason`, `actioned_at`) VALUES
(1, 1, 1, 'Approved', NULL, '2026-03-20 14:20:59'),
(2, 2, 1, 'Approved', NULL, '2026-03-20 14:20:59'),
(3, 3, 1, 'Approved', NULL, '2026-03-21 10:25:19'),
(4, 4, 1, 'Approved', NULL, '2026-03-23 12:41:31'),
(5, 4, 1, 'Approved', NULL, '2026-03-23 12:41:33'),
(6, 5, 1, 'Approved', NULL, '2026-04-21 19:47:45');

-- --------------------------------------------------------

--
-- Table structure for table `approve_booking`
--

CREATE TABLE `approve_booking` (
  `AP_BK_ID` int(11) NOT NULL,
  `Book_ID` int(11) NOT NULL,
  `CA_ID` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `approve_booking`
--

INSERT INTO `approve_booking` (`AP_BK_ID`, `Book_ID`, `CA_ID`) VALUES
(1, 1, 1),
(2, 4, 4),
(3, 3, 4),
(4, 1, 4),
(5, 5, 4),
(6, 1, 4),
(7, 2, 4),
(8, 3, 4),
(9, 1, 4),
(10, 2, 4),
(11, 1, 4),
(12, 2, 4),
(13, 3, 4),
(14, 7, 4),
(15, 17, 4),
(16, 18, 4),
(17, 19, 4),
(18, 20, 4);

-- --------------------------------------------------------

--
-- Table structure for table `approve_package`
--

CREATE TABLE `approve_package` (
  `AP_Package_ID` int(11) NOT NULL,
  `Package_ID` int(11) NOT NULL,
  `Admin_ID` int(11) NOT NULL,
  `Action` varchar(20) NOT NULL DEFAULT 'Approved',
  `Reject_reason` text DEFAULT NULL,
  `actioned_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `approve_package`
--

INSERT INTO `approve_package` (`AP_Package_ID`, `Package_ID`, `Admin_ID`, `Action`, `Reject_reason`, `actioned_at`) VALUES
(1, 1, 1, 'Approved', NULL, '2026-03-20 14:20:26'),
(2, 2, 1, 'Approved', NULL, '2026-03-20 14:20:26'),
(3, 3, 1, 'Rejected', 'ຂໍ້ມູນບໍ່ຄົບຖ້ວນກາລຸນາກວດສອບແລະສົ່ງໃໝ່', '2026-03-20 19:29:42'),
(4, 3, 1, 'Approved', NULL, '2026-03-20 20:27:52'),
(5, 3, 1, 'Approved', NULL, '2026-03-20 20:27:55'),
(6, 3, 1, 'Approved', NULL, '2026-03-20 20:31:53'),
(7, 4, 1, 'Approved', NULL, '2026-03-21 10:22:49'),
(8, 5, 1, 'Approved', NULL, '2026-03-23 11:25:12'),
(9, 6, 1, 'Approved', NULL, '2026-03-23 12:41:27'),
(10, 7, 1, 'Approved', NULL, '2026-03-26 16:16:58'),
(11, 8, 1, 'Approved', NULL, '2026-04-25 09:39:30'),
(12, 9, 1, 'Approved', NULL, '2026-05-07 22:46:57');

-- --------------------------------------------------------

--
-- Table structure for table `booking`
--

CREATE TABLE `booking` (
  `Book_ID` int(11) NOT NULL,
  `Booking_date` datetime NOT NULL,
  `Status_booking` varchar(50) NOT NULL DEFAULT 'Unpaid' COMMENT 'Unpaid, Pending, Confirmed, Cancelled',
  `Slip_payment` varchar(255) DEFAULT NULL,
  `C_ID` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `booking`
--

INSERT INTO `booking` (`Book_ID`, `Booking_date`, `Status_booking`, `Slip_payment`, `C_ID`) VALUES
(1, '2026-04-21 11:02:33', 'Completed', 'slip_1_1776744177.png', 1),
(2, '2026-04-21 11:11:06', 'Cancelled', 'slip_2_1776744671.png', 1),
(3, '2026-04-21 11:46:40', 'Completed', 'slip_3_1776746809.png', 1),
(7, '2026-04-21 11:55:50', 'Completed', 'slip_7_1776747356.png', 2),
(16, '2026-04-21 17:26:41', 'Unpaid', '', 1),
(17, '2026-04-23 16:43:30', 'Completed', 'slip_17_1776937420.png', 2),
(18, '2026-04-23 16:46:22', 'No_Show', 'slip_18_1776937589.png', 2),
(19, '2026-04-24 10:41:37', 'Completed', 'slip_19_1777002100.png', 2),
(20, '2026-04-24 10:46:45', 'Cancelled', 'slip_20_1777002409.png', 2),
(21, '2026-04-25 10:14:33', 'Cancelled', 'slip_21_1777086892.png', 2);

-- --------------------------------------------------------

--
-- Table structure for table `booking_detail`
--

CREATE TABLE `booking_detail` (
  `ID` int(11) NOT NULL,
  `Book_ID` int(11) NOT NULL,
  `COURT_ID` int(11) NOT NULL,
  `Start_time` datetime NOT NULL,
  `End_time` datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `booking_detail`
--

INSERT INTO `booking_detail` (`ID`, `Book_ID`, `COURT_ID`, `Start_time`, `End_time`) VALUES
(1, 1, 4, '2026-04-21 12:00:00', '2026-04-21 13:00:00'),
(2, 2, 4, '2026-04-21 13:00:00', '2026-04-21 14:00:00'),
(3, 3, 4, '2026-04-21 15:00:00', '2026-04-21 16:00:00'),
(7, 7, 4, '2026-04-21 16:00:00', '2026-04-21 17:00:00'),
(12, 16, 4, '2026-04-21 20:00:00', '2026-04-21 21:00:00'),
(13, 17, 4, '2026-04-23 17:00:00', '2026-04-23 18:00:00'),
(14, 18, 4, '2026-04-23 18:00:00', '2026-04-23 19:00:00'),
(15, 19, 4, '2026-04-24 12:00:00', '2026-04-24 13:00:00'),
(16, 20, 4, '2026-04-24 12:00:00', '2026-04-24 13:00:00'),
(17, 21, 1, '2026-04-25 15:00:00', '2026-04-25 16:00:00');

-- --------------------------------------------------------

--
-- Table structure for table `cancel_booking`
--

CREATE TABLE `cancel_booking` (
  `Cancel_ID` int(11) NOT NULL,
  `Comment` text DEFAULT NULL,
  `Book_ID` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `cancel_booking`
--

INSERT INTO `cancel_booking` (`Cancel_ID`, `Comment`, `Book_ID`) VALUES
(1, 'I am busy', 2),
(2, 'busy', 20),
(3, 'br pai lw', 21);

-- --------------------------------------------------------

--
-- Table structure for table `court_data`
--

CREATE TABLE `court_data` (
  `COURT_ID` int(11) NOT NULL,
  `COURT_Name` varchar(100) NOT NULL,
  `Court_Status` varchar(50) NOT NULL DEFAULT 'Active' COMMENT 'Active, Inactive, Maintaining',
  `Open_time` time DEFAULT NULL,
  `Close_time` time DEFAULT NULL,
  `VN_ID` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `court_data`
--

INSERT INTO `court_data` (`COURT_ID`, `COURT_Name`, `Court_Status`, `Open_time`, `Close_time`, `VN_ID`) VALUES
(1, 'A', 'Active', '09:00:00', '21:00:00', 1),
(2, 'B', 'Active', '10:00:00', '22:00:00', 1),
(3, 'A', 'Active', '10:00:00', '22:00:00', 3),
(4, 'A', 'Active', '11:00:00', '23:00:00', 4),
(5, 'B', 'Active', '11:00:00', '23:00:00', 4);

-- --------------------------------------------------------

--
-- Table structure for table `court_owner`
--

CREATE TABLE `court_owner` (
  `CA_ID` int(11) NOT NULL,
  `Name` varchar(100) NOT NULL,
  `Username` varchar(100) NOT NULL,
  `Password` varchar(255) NOT NULL,
  `Email` varchar(100) NOT NULL,
  `Phone` varchar(20) DEFAULT NULL,
  `Status` varchar(20) NOT NULL DEFAULT 'Active' COMMENT 'Active or Banned'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `court_owner`
--

INSERT INTO `court_owner` (`CA_ID`, `Name`, `Username`, `Password`, `Email`, `Phone`, `Status`) VALUES
(1, 'Souk', 'Tester', '$2y$10$HBGHmjt9Vd5Yxl8D4eC6VuX3aARstrExosnqrRcnMyRV2xQuJKdS2', 'souk@gmail.com', '02055553339', 'Active'),
(2, 'Soukthavy', 'test', '$2y$10$foYerPpPLRq0FlT5Z500auuNJ8teC6dzPlnqupenKx9K8D8WOjMR2', 'soukthavy@gmail.com', '02055553339', 'Active'),
(3, 'Benny', 'beny', '$2y$10$hVvWgpiFj.oyOrn6/hq0tOEh8U5xic2YdeWMJCGs8UGNJMSfhiJ16', 'ben@gmail.com', '02077744420', 'Active'),
(4, 'Souvanthana', 'suna', '$2y$10$ebtn2nj3WIvTmrlgn8j3le/Nz88inSPLwyDVv4TXewnqfsrC23kHq', 'suna@gmail.com', '02099877455', 'Active'),
(5, 'Saiy', 'sai', '$2y$10$dqmPP23CqFYy8Q4K3prG6emxXAvY6doi5n5rTaR8jyldSnI0/tEqu', 'saiy@gmail.com', '02077744420', 'Active');

-- --------------------------------------------------------

--
-- Table structure for table `customer`
--

CREATE TABLE `customer` (
  `C_ID` int(11) NOT NULL,
  `Name` varchar(100) NOT NULL,
  `Username` varchar(100) NOT NULL,
  `Password` varchar(255) NOT NULL,
  `Email` varchar(100) NOT NULL,
  `Phone` varchar(20) DEFAULT NULL,
  `Gender` varchar(20) DEFAULT NULL,
  `Status` varchar(20) NOT NULL DEFAULT 'Active' COMMENT 'Active or Banned'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `customer`
--

INSERT INTO `customer` (`C_ID`, `Name`, `Username`, `Password`, `Email`, `Phone`, `Gender`, `Status`) VALUES
(1, 'Poppy', 'Pop', '$2y$10$Gu99yTTwq43Elax1VQpmvOVwtltYJpHFT.1bOP5y7FhEaKJkqOR5q', 'pop@gmail.com', '02055552565', 'Male', 'Active'),
(2, 'bon', 'bon', '$2y$10$90cD0nSVJzXkST9DARhf5.cQotswvToHEFOa11IsGXStHIW24o1pu', 'bob@example.com', '02055552565', 'Male', 'Active');

-- --------------------------------------------------------

--
-- Table structure for table `facilities`
--

CREATE TABLE `facilities` (
  `Fac_ID` int(11) NOT NULL,
  `Fac_Name` varchar(100) NOT NULL,
  `Fac_Icon` varchar(100) DEFAULT NULL,
  `VN_ID` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `facilities`
--

INSERT INTO `facilities` (`Fac_ID`, `Fac_Name`, `Fac_Icon`, `VN_ID`) VALUES
(1, 'ທີ່ຈອດລົດ', NULL, 1),
(2, 'ຫ້ອງເຄື່ອງ', NULL, 1),
(3, 'WiFi', NULL, 1),
(4, 'ນ້ຳດື່ມ', NULL, 1),
(5, 'ທີ່ຈອດລົດ', NULL, 4),
(6, 'ຫ້ອງເຄື່ອງ', NULL, 4),
(7, 'WiFi', NULL, 4),
(8, 'ຫ້ອງອາບນ້ຳ', NULL, 4);

-- --------------------------------------------------------

--
-- Table structure for table `package`
--

CREATE TABLE `package` (
  `Package_ID` int(11) NOT NULL,
  `Status_Package` varchar(50) NOT NULL DEFAULT 'Pending' COMMENT 'Pending, Active, Expired, Rejected',
  `Slip_payment` varchar(255) DEFAULT NULL,
  `Package_date` datetime NOT NULL,
  `Start_time` datetime DEFAULT NULL,
  `End_time` datetime DEFAULT NULL,
  `VN_ID` int(11) DEFAULT NULL,
  `CA_ID` int(11) NOT NULL,
  `Package_rate_ID` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `package`
--

INSERT INTO `package` (`Package_ID`, `Status_Package`, `Slip_payment`, `Package_date`, `Start_time`, `End_time`, `VN_ID`, `CA_ID`, `Package_rate_ID`) VALUES
(1, 'Active', 'pkg_1_1773970706.jpg', '2026-03-20 08:38:26', '2026-03-20 02:49:04', '2026-06-20 02:49:04', 1, 1, 2),
(2, 'Active', 'pkg_2_1773977091.jpg', '2026-03-20 10:24:51', '2026-03-20 04:25:02', '2026-06-20 04:25:02', 2, 2, 2),
(3, 'Active', 'pkg_1_1774013262.png', '2026-03-20 20:27:42', '2026-06-20 02:49:04', '2026-07-20 02:49:04', 1, 1, 1),
(4, 'Expired', 'pkg_3_1774063361.png', '2026-03-21 10:22:41', '2026-03-21 04:22:49', '2026-04-21 04:22:49', 3, 3, 1),
(5, 'Expired', 'pkg_4_1774239906.jpg', '2026-03-23 11:25:06', '2026-03-23 05:25:12', '2026-04-23 05:25:12', 4, 4, 1),
(6, 'Active', 'pkg_4_1774240479.png', '2026-03-23 11:34:39', '2026-04-23 05:25:12', '2026-05-23 05:25:12', 4, 4, 1),
(7, 'Expired', 'pkg_5_1774516526.jpg', '2026-03-26 16:15:26', '2026-03-26 10:16:58', '2026-04-26 10:16:58', NULL, 5, 1),
(8, 'Active', 'pkg_4_1777084755.png', '2026-04-25 09:39:15', '2026-05-23 05:25:12', '2026-06-23 05:25:12', 4, 4, 1),
(9, 'Active', 'pkg_3_1778168803.jpg', '2026-05-07 22:46:43', '2026-05-07 17:46:57', '2026-07-07 17:46:57', 3, 3, 5);

-- --------------------------------------------------------

--
-- Table structure for table `package_rate`
--

CREATE TABLE `package_rate` (
  `Package_rate_ID` int(11) NOT NULL,
  `Package_duration` varchar(50) NOT NULL,
  `Price` double NOT NULL,
  `Is_Popular` tinyint(1) NOT NULL DEFAULT 0,
  `Is_Best_Value` tinyint(1) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `package_rate`
--

INSERT INTO `package_rate` (`Package_rate_ID`, `Package_duration`, `Price`, `Is_Popular`, `Is_Best_Value`) VALUES
(1, '1 ເດືອນ', 500000, 0, 0),
(2, '3 ເດືອນ', 1300000, 1, 0),
(3, '6 ເດືອນ', 2400000, 0, 1),
(4, '12 ເດືອນ', 4000000, 0, 0),
(5, '2 ເດືອນ', 1000000, 0, 0);

-- --------------------------------------------------------

--
-- Table structure for table `venue_data`
--

CREATE TABLE `venue_data` (
  `VN_ID` int(11) NOT NULL,
  `VN_Name` varchar(255) NOT NULL,
  `VN_Address` varchar(255) NOT NULL,
  `VN_Description` text DEFAULT NULL,
  `VN_Image` varchar(255) DEFAULT NULL,
  `VN_QR_Payment` varchar(255) DEFAULT NULL COMMENT 'QR code image for customer payment',
  `Open_time` time NOT NULL,
  `Close_time` time NOT NULL,
  `Price_per_hour` varchar(50) NOT NULL,
  `VN_MapURL` varchar(500) DEFAULT NULL,
  `VN_Status` varchar(50) NOT NULL DEFAULT 'Pending',
  `Reject_reason` text DEFAULT NULL COMMENT 'Set by admin when rejecting venue. Cleared on approval.',
  `CA_ID` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `venue_data`
--

INSERT INTO `venue_data` (`VN_ID`, `VN_Name`, `VN_Address`, `VN_Description`, `VN_Image`, `VN_QR_Payment`, `Open_time`, `Close_time`, `Price_per_hour`, `VN_MapURL`, `VN_Status`, `Reject_reason`, `CA_ID`) VALUES
(1, 'ເດີ່ນຕີດອກປີກໄກ່ ສຸກທະວີ', 'ບ້ານໂພນປາເປົ້າ, ເມືອງສີສັດຕະນາກ, ນະຄອນຫຼວງວຽງຈັນ', 'ເດີ່ນຂອງພວກເຮົາມີສະຖານທີ່ທີ່ກວາງຂ້ວາງ ແລະ ສະຖານທີ່ຈອດລົດພຽງພໍສຳລັບທຸກທ່ານ', 'venue_1_1773972028.png', 'qr_1_1773972028.jpg', '08:00:00', '22:00:00', '120000', 'https://www.google.com/maps/dir/%E0%B8%9A%E0%B8%B8%E0%B8%9F%E0%B9%80%E0%B8%9F%E0%B9%88%E0%B8%A5%E0%B8%B9%E0%B8%81%E0%B8%8A%E0%B8%B4%E0%B9%89%E0%B8%99%2B%E0%B8%AA%E0%B9%89%E0%B8%A1%E0%B8%95%E0%B8%B3+XJ6P%2BVC8,+Vientiane/17.9442901,102.6438329/@17.9532264,102.6297491,15z/data=!3m1!4b1!4m9!4m8!1m5!1m1!1s0x3124677b444f5937:0xe203d565b0daeb49!2m2!1d102.6360815!2d17.9621631!1m1!4e1?entry=ttu&g_ep=EgoyMDI2MDMxNy4wIKXMDSoASAFQAw%3D%3D', 'Active', NULL, 1),
(2, 'ເດີ່ນຕີດອກປີກໄກ່ ເບັ້ນ', 'ບ້ານໂພນພະເນົາ, ເມືອງຈັນທະບູລີ, ນະຄອນຫຼວງວຽງຈັນ', 'ເດີ່ນທີ່ດີທີ່ສຸດໃນໂລກ', 'venue_2_1773977318.png', 'qr_2_1773977318.jpg', '07:00:00', '22:00:00', '100000', 'https://www.google.com/maps/dir//17.9432347,102.6430753/@17.9457667,102.6361243,15.6z?entry=ttu&g_ep=EgoyMDI2MDMxNy4wIKXMDSoASAFQAw%3D%3D', 'Active', NULL, 2),
(3, 'ເດີ່ນຕີດອກປີກ ແສນຫົວພັນ', 'ບ້ານໂພນປາເປົ້າ, ເມືອງສີສັດຕະນາກ, ນະຄອນຫຼວງວຽງຈັນ', 'ສະດວກສະບາຍ', 'venue_3_1774063464.png', 'qr_3_1774063464.jpg', '10:00:00', '22:00:00', '90000', 'https://www.google.com/maps/dir/%E0%B8%9A%E0%B8%B8%E0%B8%9F%E0%B9%80%E0%B8%9F%E0%B9%88%E0%B8%A5%E0%B8%B9%E0%B8%81%E0%B8%8A%E0%B8%B4%E0%B9%89%E0%B8%99%2B%E0%B8%AA%E0%B9%89%E0%B8%A1%E0%B8%95%E0%B8%B3+XJ6P%2BVC8,+Vientiane/17.9442901,102.6438329/@17.9532264,102.6297491,15z/data=!3m1!4b1!4m9!4m8!1m5!1m1!1s0x3124677b444f5937:0xe203d565b0daeb49!2m2!1d102.6360815!2d17.9621631!1m1!4e1?entry=ttu&g_ep=EgoyMDI2MDMxNy4wIKXMDSoASAFQAw%3D%3D', 'Active', NULL, 3),
(4, 'Souvanthana Badminton', 'ໂພນປາເປົ້າ ເມືອງສີສັດຕະນາກ ນະຄອນຫຼວງວຽງຈັນ', 'ເດີ່ນຂອງພວກເຮົາມີສະຖານທີ່ກ້າງຂວງ ແລະ ບ່ອນຈອດລົດທີ່ພຽງພໍ', 'venue_4_1774240347.png', 'qr_4_1774240347.jpg', '07:00:00', '22:00:00', '100000', 'https://maps.app.goo.gl/c4DHmuzmxXvFC7wJ8', 'Active', NULL, 4);

--
-- Indexes for dumped tables
--

--
-- Indexes for table `admin`
--
ALTER TABLE `admin`
  ADD PRIMARY KEY (`Admin_ID`),
  ADD UNIQUE KEY `Username` (`Username`);

--
-- Indexes for table `advertisement`
--
ALTER TABLE `advertisement`
  ADD PRIMARY KEY (`AD_ID`),
  ADD KEY `VN_ID` (`VN_ID`),
  ADD KEY `AD_Rate_ID` (`AD_Rate_ID`);

--
-- Indexes for table `advertisement_rate`
--
ALTER TABLE `advertisement_rate`
  ADD PRIMARY KEY (`AD_Rate_ID`);

--
-- Indexes for table `approve_advertisement`
--
ALTER TABLE `approve_advertisement`
  ADD PRIMARY KEY (`AP_AD_ID`),
  ADD KEY `AD_ID` (`AD_ID`),
  ADD KEY `Admin_ID` (`Admin_ID`);

--
-- Indexes for table `approve_booking`
--
ALTER TABLE `approve_booking`
  ADD PRIMARY KEY (`AP_BK_ID`),
  ADD KEY `Book_ID` (`Book_ID`),
  ADD KEY `CA_ID` (`CA_ID`);

--
-- Indexes for table `approve_package`
--
ALTER TABLE `approve_package`
  ADD PRIMARY KEY (`AP_Package_ID`),
  ADD KEY `Package_ID` (`Package_ID`),
  ADD KEY `Admin_ID` (`Admin_ID`);

--
-- Indexes for table `booking`
--
ALTER TABLE `booking`
  ADD PRIMARY KEY (`Book_ID`),
  ADD KEY `C_ID` (`C_ID`);

--
-- Indexes for table `booking_detail`
--
ALTER TABLE `booking_detail`
  ADD PRIMARY KEY (`ID`),
  ADD KEY `Book_ID` (`Book_ID`),
  ADD KEY `COURT_ID` (`COURT_ID`);

--
-- Indexes for table `cancel_booking`
--
ALTER TABLE `cancel_booking`
  ADD PRIMARY KEY (`Cancel_ID`),
  ADD KEY `Book_ID` (`Book_ID`);

--
-- Indexes for table `court_data`
--
ALTER TABLE `court_data`
  ADD PRIMARY KEY (`COURT_ID`),
  ADD KEY `VN_ID` (`VN_ID`);

--
-- Indexes for table `court_owner`
--
ALTER TABLE `court_owner`
  ADD PRIMARY KEY (`CA_ID`),
  ADD UNIQUE KEY `Username` (`Username`),
  ADD UNIQUE KEY `Email` (`Email`);

--
-- Indexes for table `customer`
--
ALTER TABLE `customer`
  ADD PRIMARY KEY (`C_ID`),
  ADD UNIQUE KEY `Username` (`Username`),
  ADD UNIQUE KEY `Email` (`Email`);

--
-- Indexes for table `facilities`
--
ALTER TABLE `facilities`
  ADD PRIMARY KEY (`Fac_ID`),
  ADD KEY `VN_ID` (`VN_ID`);

--
-- Indexes for table `package`
--
ALTER TABLE `package`
  ADD PRIMARY KEY (`Package_ID`),
  ADD KEY `CA_ID` (`CA_ID`),
  ADD KEY `Package_rate_ID` (`Package_rate_ID`);

--
-- Indexes for table `package_rate`
--
ALTER TABLE `package_rate`
  ADD PRIMARY KEY (`Package_rate_ID`);

--
-- Indexes for table `venue_data`
--
ALTER TABLE `venue_data`
  ADD PRIMARY KEY (`VN_ID`),
  ADD KEY `CA_ID` (`CA_ID`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `admin`
--
ALTER TABLE `admin`
  MODIFY `Admin_ID` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `advertisement`
--
ALTER TABLE `advertisement`
  MODIFY `AD_ID` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `advertisement_rate`
--
ALTER TABLE `advertisement_rate`
  MODIFY `AD_Rate_ID` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=10;

--
-- AUTO_INCREMENT for table `approve_advertisement`
--
ALTER TABLE `approve_advertisement`
  MODIFY `AP_AD_ID` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT for table `approve_booking`
--
ALTER TABLE `approve_booking`
  MODIFY `AP_BK_ID` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=19;

--
-- AUTO_INCREMENT for table `approve_package`
--
ALTER TABLE `approve_package`
  MODIFY `AP_Package_ID` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=13;

--
-- AUTO_INCREMENT for table `booking`
--
ALTER TABLE `booking`
  MODIFY `Book_ID` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=22;

--
-- AUTO_INCREMENT for table `booking_detail`
--
ALTER TABLE `booking_detail`
  MODIFY `ID` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=18;

--
-- AUTO_INCREMENT for table `cancel_booking`
--
ALTER TABLE `cancel_booking`
  MODIFY `Cancel_ID` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `court_data`
--
ALTER TABLE `court_data`
  MODIFY `COURT_ID` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `court_owner`
--
ALTER TABLE `court_owner`
  MODIFY `CA_ID` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `customer`
--
ALTER TABLE `customer`
  MODIFY `C_ID` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `facilities`
--
ALTER TABLE `facilities`
  MODIFY `Fac_ID` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;

--
-- AUTO_INCREMENT for table `package`
--
ALTER TABLE `package`
  MODIFY `Package_ID` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=10;

--
-- AUTO_INCREMENT for table `package_rate`
--
ALTER TABLE `package_rate`
  MODIFY `Package_rate_ID` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `venue_data`
--
ALTER TABLE `venue_data`
  MODIFY `VN_ID` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
