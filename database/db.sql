CREATE DATABASE IF NOT EXISTS badminton_booking
    CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE badminton_booking;
 
-- ── ADMIN ────────────────────────────────────────────────────
CREATE TABLE admin (
    Admin_ID   INT(11)      NOT NULL AUTO_INCREMENT,
    Name       VARCHAR(100) NOT NULL,
    Surname    VARCHAR(100),
    Gender     VARCHAR(20),
    Image_pay  VARCHAR(255) COMMENT 'QR code shown to owners for payment',
    Username   VARCHAR(100) NOT NULL UNIQUE,
    Password   VARCHAR(255) NOT NULL,
    PRIMARY KEY (Admin_ID)
);
 
-- ── COURT OWNER ──────────────────────────────────────────────
CREATE TABLE court_owner (
    CA_ID      INT(11)      NOT NULL AUTO_INCREMENT,
    Name       VARCHAR(100) NOT NULL,
    Username   VARCHAR(100) NOT NULL UNIQUE,
    Password   VARCHAR(255) NOT NULL,
    Email      VARCHAR(100) NOT NULL UNIQUE,
    Phone      VARCHAR(20),
    Status     VARCHAR(20)  NOT NULL DEFAULT 'Active' COMMENT 'Active or Banned',
    PRIMARY KEY (CA_ID)
);
 
-- ── CUSTOMER ─────────────────────────────────────────────────
CREATE TABLE customer (
    C_ID       INT(11)      NOT NULL AUTO_INCREMENT,
    Name       VARCHAR(100) NOT NULL,
    Username   VARCHAR(100) NOT NULL UNIQUE,
    Password   VARCHAR(255) NOT NULL,
    Email      VARCHAR(100) NOT NULL UNIQUE,
    Phone      VARCHAR(20),
    Gender     VARCHAR(20),
    Status     VARCHAR(20)  NOT NULL DEFAULT 'Active' COMMENT 'Active or Banned',
    PRIMARY KEY (C_ID)
);
 
-- ── PACKAGE RATE ─────────────────────────────────────────────
CREATE TABLE package_rate (
    Package_rate_ID  INT(11)       NOT NULL AUTO_INCREMENT,
    Package_duration VARCHAR(50)   NOT NULL,
    Price            DOUBLE        NOT NULL,
    Is_Popular       TINYINT(1)    NOT NULL DEFAULT 0,
    Is_Best_Value    TINYINT(1)    NOT NULL DEFAULT 0,
    PRIMARY KEY (Package_rate_ID)
);
 
-- ── PACKAGE ──────────────────────────────────────────────────
-- UPDATED: Added Reject_reason column (replaces owner_notification for packages)
CREATE TABLE package (
    Package_ID       INT(11)       NOT NULL AUTO_INCREMENT,
    Status_Package   VARCHAR(50)   NOT NULL DEFAULT 'Pending'
                     COMMENT 'Pending, Active, Expired, Rejected',
    Slip_payment     VARCHAR(255),
    Package_date     DATETIME      NOT NULL,
    Start_time       DATETIME,
    End_time         DATETIME,
    Reject_reason    TEXT          DEFAULT NULL
                     COMMENT 'Set by admin when rejecting. Cleared on resubmit.',
    VN_ID            INT(11)       DEFAULT NULL,
    CA_ID            INT(11)       NOT NULL,
    Package_rate_ID  INT(11)       NOT NULL,
    PRIMARY KEY (Package_ID),
    KEY CA_ID (CA_ID),
    KEY Package_rate_ID (Package_rate_ID)
);
 
-- ── ADVERTISEMENT RATE ───────────────────────────────────────
CREATE TABLE advertisement_rate (
    AD_Rate_ID    INT(11)      NOT NULL AUTO_INCREMENT,
    Duration      VARCHAR(50)  NOT NULL,
    Price         DOUBLE       NOT NULL,
    Is_Popular    TINYINT(1)   NOT NULL DEFAULT 0,
    Is_Best_Value TINYINT(1)   NOT NULL DEFAULT 0,
    PRIMARY KEY (AD_Rate_ID)
);
 
-- ── VENUE DATA ───────────────────────────────────────────────
CREATE TABLE Venue_data (
    VN_ID          INT(11)       NOT NULL AUTO_INCREMENT,
    VN_Name        VARCHAR(255)  NOT NULL,
    VN_Address     VARCHAR(255)  NOT NULL,
    VN_Description TEXT,
    VN_Image       VARCHAR(255),
    VN_QR_Payment  VARCHAR(255)  COMMENT 'QR code image for customer payment',
    Open_time      TIME          NOT NULL,
    Close_time     TIME          NOT NULL,
    Price_per_hour VARCHAR(50)   NOT NULL,
    VN_MapURL      VARCHAR(500),
    VN_Status      VARCHAR(50)   NOT NULL DEFAULT 'Pending',
    Reject_reason  TEXT          DEFAULT NULL
                   COMMENT 'Set by admin when rejecting venue. Cleared on approval.',
    CA_ID          INT(11)       NOT NULL,
    PRIMARY KEY (VN_ID),
    KEY CA_ID (CA_ID)
);
 
-- ── COURT DATA ───────────────────────────────────────────────
CREATE TABLE Court_data (
    COURT_ID     INT(11)      NOT NULL AUTO_INCREMENT,
    COURT_Name   VARCHAR(100) NOT NULL,
    Court_Status VARCHAR(50)  NOT NULL DEFAULT 'Active' COMMENT 'Active, Inactive, Maintaining',
    Open_time    TIME         DEFAULT NULL,
    Close_time   TIME         DEFAULT NULL,
    VN_ID        INT(11)      NOT NULL,
    PRIMARY KEY (COURT_ID),
    KEY VN_ID (VN_ID)
);
 
-- ── FACILITIES ───────────────────────────────────────────────
CREATE TABLE facilities (
    Fac_ID    INT(11)      NOT NULL AUTO_INCREMENT,
    Fac_Name  VARCHAR(100) NOT NULL,
    Fac_Icon  VARCHAR(100),
    VN_ID     INT(11)      NOT NULL,
    PRIMARY KEY (Fac_ID),
    KEY VN_ID (VN_ID)
);
 
-- ── BOOKING ──────────────────────────────────────────────────
CREATE TABLE booking (
    Book_ID        INT(11)      NOT NULL AUTO_INCREMENT,
    Booking_date   DATETIME     NOT NULL,
    Status_booking VARCHAR(50)  NOT NULL DEFAULT 'Unpaid'
                   COMMENT 'Unpaid, Pending, Confirmed, Cancelled',
    Slip_payment   VARCHAR(255) DEFAULT NULL,
    C_ID           INT(11)      NOT NULL,
    PRIMARY KEY (Book_ID),
    KEY C_ID (C_ID)
);
 
-- ── BOOKING DETAIL ───────────────────────────────────────────
CREATE TABLE booking_detail (
    ID          INT(11)  NOT NULL AUTO_INCREMENT,
    Book_ID     INT(11)  NOT NULL,
    COURT_ID    INT(11)  NOT NULL,
    Start_time  DATETIME NOT NULL,
    End_time    DATETIME NOT NULL,
    PRIMARY KEY (ID),
    KEY Book_ID (Book_ID),
    KEY COURT_ID (COURT_ID)
);
 
-- ── CANCEL BOOKING ───────────────────────────────────────────
CREATE TABLE cancel_booking (
    Cancel_ID   INT(11)   NOT NULL AUTO_INCREMENT,
    Comment     TEXT,
    Book_ID     INT(11)   NOT NULL,
    PRIMARY KEY (Cancel_ID),
    KEY Book_ID (Book_ID)
);
 
-- ── APPROVE BOOKING ──────────────────────────────────────────
CREATE TABLE approve_booking (
    AP_BK_ID  INT(11) NOT NULL AUTO_INCREMENT,
    Book_ID   INT(11) NOT NULL,
    CA_ID     INT(11) NOT NULL,
    PRIMARY KEY (AP_BK_ID),
    KEY Book_ID (Book_ID),
    KEY CA_ID (CA_ID)
);
 
-- ── APPROVE PACKAGE ──────────────────────────────────────────
CREATE TABLE approve_package (
    AP_Package_ID  INT(11) NOT NULL AUTO_INCREMENT,
    Package_ID     INT(11) NOT NULL,
    Admin_ID       INT(11) NOT NULL,
    PRIMARY KEY (AP_Package_ID),
    KEY Package_ID (Package_ID),
    KEY Admin_ID (Admin_ID)
);
 
-- ── ADVERTISEMENT ────────────────────────────────────────────
-- UPDATED: Added Reject_reason column (replaces owner_notification for ads)
CREATE TABLE advertisement (
    AD_ID         INT(11)      NOT NULL AUTO_INCREMENT,
    AD_date       DATETIME     NOT NULL,
    Start_time    DATETIME     DEFAULT NULL,
    End_time      DATETIME     DEFAULT NULL,
    Slip_payment  VARCHAR(255),
    Status_AD     VARCHAR(50)  NOT NULL DEFAULT 'Pending'
                  COMMENT 'Pending, Approved, Active, Rejected',
    Reject_reason TEXT         DEFAULT NULL
                  COMMENT 'Set by admin when rejecting. Cleared on resubmit.',
    VN_ID         INT(11)      NOT NULL,
    AD_Rate_ID    INT(11)      NOT NULL,
    PRIMARY KEY (AD_ID),
    KEY VN_ID (VN_ID),
    KEY AD_Rate_ID (AD_Rate_ID)
);
 
-- ── APPROVE ADVERTISEMENT ────────────────────────────────────
CREATE TABLE approve_advertisement (
    AP_AD_ID  INT(11) NOT NULL AUTO_INCREMENT,
    AD_ID     INT(11) NOT NULL,
    Admin_ID  INT(11) NOT NULL,
    PRIMARY KEY (AP_AD_ID),
    KEY AD_ID (AD_ID),
    KEY Admin_ID (Admin_ID)
);