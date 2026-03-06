-- ============================================================
--  Badminton Court Booking System — Full Database Schema
-- ============================================================

CREATE DATABASE IF NOT EXISTS badminton_booking
    CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE badminton_booking;

-- ── ADMIN ────────────────────────────────────────────────────
CREATE TABLE admin (
    Admin_ID   INT(11)      NOT NULL AUTO_INCREMENT,
    Username   VARCHAR(100) NOT NULL,
    Password   VARCHAR(255) NOT NULL,
    Email      VARCHAR(100),
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
    Profile_image VARCHAR(255),
    PRIMARY KEY (CA_ID)
);

-- ── CUSTOMER ─────────────────────────────────────────────────
CREATE TABLE customer (
    CU_ID      INT(11)      NOT NULL AUTO_INCREMENT,
    Name       VARCHAR(100) NOT NULL,
    Username   VARCHAR(100) NOT NULL UNIQUE,
    Password   VARCHAR(255) NOT NULL,
    Email      VARCHAR(100) NOT NULL UNIQUE,
    Phone      VARCHAR(20),
    Profile_image VARCHAR(255),
    PRIMARY KEY (CU_ID)
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
CREATE TABLE package (
    Package_ID       INT(11)       NOT NULL AUTO_INCREMENT,
    Status_Package   VARCHAR(255)  NOT NULL,
    Slip_payment     VARCHAR(255)  NOT NULL,
    Package_date     DATETIME      NOT NULL,
    Start_time       DATETIME      NOT NULL,
    End_time         DATETIME      NOT NULL,
    VN_ID            INT(11)       DEFAULT NULL,
    CA_ID            INT(11)       DEFAULT NULL,
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
CREATE TABLE venue_data (
    VN_ID          INT(11)       NOT NULL AUTO_INCREMENT,
    VN_Name        VARCHAR(255)  NOT NULL,
    VN_Address     VARCHAR(255)  NOT NULL,
    VN_Description TEXT,
    VN_Image       VARCHAR(255),
    Open_time      TIME          NOT NULL,
    Close_time     TIME          NOT NULL,
    Price_per_hour DOUBLE        NOT NULL,
    Map_link       VARCHAR(500),
    VN_Status      VARCHAR(50)   NOT NULL DEFAULT 'Inactive',
    Reject_reason  TEXT,
    CA_ID          INT(11)       NOT NULL,
    PRIMARY KEY (VN_ID),
    KEY CA_ID (CA_ID)
);

-- ── COURT DATA ───────────────────────────────────────────────
CREATE TABLE court_data (
    COURT_ID          INT(11)      NOT NULL AUTO_INCREMENT,
    COURT_Name        VARCHAR(100) NOT NULL,
    Court_description VARCHAR(255),
    Open_time         TIME         DEFAULT NULL,
    Close_time        TIME         DEFAULT NULL,
    VN_ID             INT(11)      NOT NULL,
    PRIMARY KEY (COURT_ID),
    KEY VN_ID (VN_ID)
);

-- ── COURT SCHEDULE ───────────────────────────────────────────
CREATE TABLE court_schedule (
    Schedule_ID  INT(11)   NOT NULL AUTO_INCREMENT,
    COURT_ID     INT(11)   NOT NULL,
    Date         DATE      NOT NULL,
    Start_time   TIME      NOT NULL,
    End_time     TIME      NOT NULL,
    Status       VARCHAR(50) NOT NULL DEFAULT 'Available',
    PRIMARY KEY (Schedule_ID),
    KEY COURT_ID (COURT_ID)
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
    Book_date      DATETIME     NOT NULL,
    Play_date      DATE         NOT NULL,
    Start_time     TIME         NOT NULL,
    End_time       TIME         NOT NULL,
    Total_price    DOUBLE       NOT NULL,
    Deposit_amount DOUBLE       NOT NULL DEFAULT 0,
    Status_booking VARCHAR(50)  NOT NULL DEFAULT 'Pending',
    Slip_payment   VARCHAR(255),
    CU_ID          INT(11)      NOT NULL,
    COURT_ID       INT(11)      NOT NULL,
    VN_ID          INT(11)      NOT NULL,
    PRIMARY KEY (Book_ID),
    KEY CU_ID (CU_ID),
    KEY COURT_ID (COURT_ID),
    KEY VN_ID (VN_ID)
);

-- ── BOOKING DETAIL ───────────────────────────────────────────
CREATE TABLE booking_detail (
    BD_ID       INT(11) NOT NULL AUTO_INCREMENT,
    Book_ID     INT(11) NOT NULL,
    Start_time  TIME    NOT NULL,
    End_time    TIME    NOT NULL,
    PRIMARY KEY (BD_ID),
    KEY Book_ID (Book_ID)
);

-- ── CANCEL BOOKING ───────────────────────────────────────────
CREATE TABLE cancel_booking (
    Cancel_ID     INT(11)      NOT NULL AUTO_INCREMENT,
    Book_ID       INT(11)      NOT NULL,
    Cancel_date   DATETIME     NOT NULL,
    Cancel_reason TEXT,
    Cancelled_by  VARCHAR(50)  NOT NULL COMMENT 'customer or owner',
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
CREATE TABLE advertisement (
    AD_ID        INT(11)      NOT NULL AUTO_INCREMENT,
    AD_date      DATETIME     NOT NULL,
    Start_time   DATETIME     DEFAULT NULL,
    End_time     DATETIME     DEFAULT NULL,
    Slip_payment VARCHAR(255),
    Status_AD    VARCHAR(50)  NOT NULL DEFAULT 'Pending',
    VN_ID        INT(11)      NOT NULL,
    AD_Rate_ID   INT(11)      NOT NULL,
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

-- ── OWNER NOTIFICATION ───────────────────────────────────────
CREATE TABLE owner_notification (
    notif_id      INT(11)      NOT NULL AUTO_INCREMENT,
    CA_ID         INT(11)      NOT NULL,
    type          VARCHAR(50)  NOT NULL COMMENT 'package, advertisement, venue',
    ref_id        INT(11)      NOT NULL,
    title         VARCHAR(255) NOT NULL,
    message       TEXT         NOT NULL,
    flagged_fields TEXT        DEFAULT NULL,
    created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (notif_id),
    KEY CA_ID (CA_ID)
);

-- ============================================================
--  FOREIGN KEYS
-- ============================================================

ALTER TABLE package
    ADD CONSTRAINT fk_pkg_ca   FOREIGN KEY (CA_ID)           REFERENCES court_owner (CA_ID),
    ADD CONSTRAINT fk_pkg_rate FOREIGN KEY (Package_rate_ID) REFERENCES package_rate (Package_rate_ID);

ALTER TABLE venue_data
    ADD CONSTRAINT fk_vn_ca FOREIGN KEY (CA_ID) REFERENCES court_owner (CA_ID);

ALTER TABLE court_data
    ADD CONSTRAINT fk_court_vn FOREIGN KEY (VN_ID) REFERENCES venue_data (VN_ID);

ALTER TABLE court_schedule
    ADD CONSTRAINT fk_sched_court FOREIGN KEY (COURT_ID) REFERENCES court_data (COURT_ID);

ALTER TABLE facilities
    ADD CONSTRAINT fk_fac_vn FOREIGN KEY (VN_ID) REFERENCES venue_data (VN_ID);

ALTER TABLE booking
    ADD CONSTRAINT fk_bk_cu    FOREIGN KEY (CU_ID)    REFERENCES customer (CU_ID),
    ADD CONSTRAINT fk_bk_court FOREIGN KEY (COURT_ID) REFERENCES court_data (COURT_ID),
    ADD CONSTRAINT fk_bk_vn    FOREIGN KEY (VN_ID)    REFERENCES venue_data (VN_ID);

ALTER TABLE booking_detail
    ADD CONSTRAINT fk_bd_bk FOREIGN KEY (Book_ID) REFERENCES booking (Book_ID);

ALTER TABLE cancel_booking
    ADD CONSTRAINT fk_cancel_bk FOREIGN KEY (Book_ID) REFERENCES booking (Book_ID);

ALTER TABLE approve_booking
    ADD CONSTRAINT fk_apbk_bk FOREIGN KEY (Book_ID) REFERENCES booking (Book_ID),
    ADD CONSTRAINT fk_apbk_ca FOREIGN KEY (CA_ID)   REFERENCES court_owner (CA_ID);

ALTER TABLE approve_package
    ADD CONSTRAINT fk_appkg_pkg   FOREIGN KEY (Package_ID) REFERENCES package (Package_ID),
    ADD CONSTRAINT fk_appkg_admin FOREIGN KEY (Admin_ID)   REFERENCES admin (Admin_ID);

ALTER TABLE advertisement
    ADD CONSTRAINT fk_ad_vn   FOREIGN KEY (VN_ID)      REFERENCES venue_data (VN_ID),
    ADD CONSTRAINT fk_ad_rate FOREIGN KEY (AD_Rate_ID) REFERENCES advertisement_rate (AD_Rate_ID);

ALTER TABLE approve_advertisement
    ADD CONSTRAINT fk_apad_ad    FOREIGN KEY (AD_ID)    REFERENCES advertisement (AD_ID),
    ADD CONSTRAINT fk_apad_admin FOREIGN KEY (Admin_ID) REFERENCES admin (Admin_ID);

ALTER TABLE owner_notification
    ADD CONSTRAINT fk_notif_ca FOREIGN KEY (CA_ID) REFERENCES court_owner (CA_ID);
