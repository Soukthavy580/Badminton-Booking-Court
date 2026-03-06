CREATE DATABASE BadmintonCourt_booking;
USE BadmintonCourt_booking;

-- 1 admin
CREATE TABLE admin (
    Admin_ID INT(11) AUTO_INCREMENT PRIMARY KEY,
    Name VARCHAR(50) NOT NULL,
    Surname VARCHAR(30) NOT NULL,
    Gender VARCHAR(10) NOT NULL,
    Image_pay VARCHAR(255) NOT NULL,
    Username VARCHAR(20) NOT NULL,
    Password VARCHAR(60) NOT NULL
);

-- 2 court_owner
CREATE TABLE court_owner (
    CA_ID INT(11) AUTO_INCREMENT PRIMARY KEY,
    Name VARCHAR(50) NOT NULL,
    Surname VARCHAR(30) NOT NULL,
    Gender VARCHAR(10) NOT NULL,
    Phone VARCHAR(20) NOT NULL,
    Email VARCHAR(100) NOT NULL,
    Username VARCHAR(20) NOT NULL,
    Password VARCHAR(60) NOT NULL
);

-- 3 customer
CREATE TABLE customer (
    C_ID INT(11) AUTO_INCREMENT PRIMARY KEY,
    Name VARCHAR(50) NOT NULL,
    Surname VARCHAR(30) NOT NULL,
    Gender VARCHAR(10) NOT NULL,
    Phone VARCHAR(20) NOT NULL,
    Email VARCHAR(100) NOT NULL,
    Username VARCHAR(20) NOT NULL,
    Password VARCHAR(60) NOT NULL
);


-- 5 advertisement_rate
CREATE TABLE advertisement_rate (
    AD_Rate_ID INT(11) AUTO_INCREMENT PRIMARY KEY,
    Duration VARCHAR(50) NOT NULL,
    Price DOUBLE NOT NULL
);

-- 6 advertisement
CREATE TABLE advertisement (
    AD_ID INT(11) AUTO_INCREMENT PRIMARY KEY,
    Status_AD VARCHAR(255) NOT NULL,
    Slip_payment VARCHAR(255) NOT NULL,
    AD_date DATETIME NOT NULL,
    Start_time DATETIME NOT NULL,
    End_time DATETIME NOT NULL,
    VN_ID INT(11) NOT NULL,
    AD_Rate_ID INT(11) NOT NULL,
    FOREIGN KEY (VN_ID) REFERENCES Venue_data(VN_ID),
    FOREIGN KEY (AD_Rate_ID) REFERENCES advertisement_rate(AD_Rate_ID)
);

-- 7 approve_advertisement
CREATE TABLE approve_advertisement (
    AP_AD_ID INT(11) AUTO_INCREMENT PRIMARY KEY,
    Comment VARCHAR(255) NOT NULL,
    AD_ID INT(11) NOT NULL,
    Admin_ID INT(11) NOT NULL,
    FOREIGN KEY (AD_ID) REFERENCES advertisement(AD_ID),
    FOREIGN KEY (Admin_ID) REFERENCES admin(Admin_ID)
);

-- 8 Venue_data
CREATE TABLE Venue_data (
    VN_ID INT(11) AUTO_INCREMENT PRIMARY KEY,
    VN_Name VARCHAR(50) NOT NULL,
    VN_Description VARCHAR(255) NOT NULL,
    VN_Address VARCHAR(255) NOT NULL,
    VN_MapURL VARCHAR(255) NOT NULL,
    VN_Image VARCHAR(255) NOT NULL,
    VN_QR_Payment VARCHAR(255) NOT NULL,
    VN_Status VARCHAR(20) NOT NULL,
    Price_per_hour VARCHAR(20) NOT NULL,
    Open_time VARCHAR(20) NOT NULL,
    Close_time VARCHAR(20) NOT NULL,
    Status_Notification VARCHAR(20) NOT NULL,
    CA_ID INT(11) NOT NULL,
    FOREIGN KEY (CA_ID) REFERENCES court_owner(CA_ID)
);

-- 9 Court_data
CREATE TABLE Court_data (
    COURT_ID INT(11) AUTO_INCREMENT PRIMARY KEY,
    COURT_Name VARCHAR(20) NOT NULL,
    VN_ID INT(11) NOT NULL,
    FOREIGN KEY (VN_ID) REFERENCES Venue_data(VN_ID)
);

-- 10 booking
CREATE TABLE booking (
    Book_ID INT(11) AUTO_INCREMENT PRIMARY KEY,
    Booking_date DATETIME NOT NULL,
    Slip_payment VARCHAR(255) NOT NULL,
    Status_booking VARCHAR(20) NOT NULL,
    C_ID INT(11) NOT NULL,
    FOREIGN KEY (C_ID) REFERENCES customer(C_ID)
);

-- 11 booking_detail
CREATE TABLE booking_detail (
    ID INT(11) AUTO_INCREMENT PRIMARY KEY,
    Start_time DATETIME NOT NULL,
    End_time DATETIME NOT NULL,
    Book_ID INT(11) NOT NULL,
    COURT_ID INT(11) NOT NULL,
    FOREIGN KEY (Book_ID) REFERENCES booking(Book_ID),
    FOREIGN KEY (COURT_ID) REFERENCES Court_data(COURT_ID)
);

-- 12 cancel_booking
CREATE TABLE cancel_booking (
    ID INT(11) AUTO_INCREMENT PRIMARY KEY,
    Comment VARCHAR(255) NOT NULL,
    Book_ID INT(11) NOT NULL,
    FOREIGN KEY (Book_ID) REFERENCES booking(Book_ID)
);

-- 13 approve_booking
CREATE TABLE approve_booking (
    AP_BK_ID INT(11) AUTO_INCREMENT PRIMARY KEY,
    Comment VARCHAR(255) NOT NULL,
    Book_ID INT(11) NOT NULL,
    CA_ID INT(11) NOT NULL,
    FOREIGN KEY (Book_ID) REFERENCES booking(Book_ID),
    FOREIGN KEY (CA_ID) REFERENCES court_owner(CA_ID)
);

-- 14 court_schedule
CREATE TABLE court_schedule (
    Table_ID INT(11) AUTO_INCREMENT PRIMARY KEY,
    Start_time DATETIME NOT NULL,
    End_time DATETIME NOT NULL,
    VN_ID INT(11) NOT NULL,
    FOREIGN KEY (VN_ID) REFERENCES Venue_data(VN_ID)
);

-- 15 facility
CREATE TABLE facility (
    Fac_ID INT(11) AUTO_INCREMENT PRIMARY KEY,
    Fac_Name VARCHAR(50) NOT NULL,
    VN_ID INT(11) NOT NULL,
    FOREIGN KEY (VN_ID) REFERENCES Venue_data(VN_ID)
);

-- 16 package_rate
CREATE TABLE package_rate (
    Package_rate_ID INT(11) AUTO_INCREMENT PRIMARY KEY,
    Package_duration VARCHAR(50) NOT NULL,
    Price DOUBLE NOT NULL
);

-- 17 buy_package
CREATE TABLE buy_package (
    Package_ID INT(11) AUTO_INCREMENT PRIMARY KEY,
    Status_Package VARCHAR(255) NOT NULL,
    Slip_payment VARCHAR(255) NOT NULL,
    Package_date DATETIME NOT NULL,
    Start_time DATETIME NOT NULL,
    End_time DATETIME NOT NULL,
    VN_ID INT(11) NOT NULL,
    Package_rate_ID INT(11) NOT NULL,
    FOREIGN KEY (VN_ID) REFERENCES Venue_data(VN_ID),
    FOREIGN KEY (Package_rate_ID) REFERENCES package_rate(Package_rate_ID)
);

-- 18 approve_package
CREATE TABLE approve_package (
    AP_Package_ID INT(11) AUTO_INCREMENT PRIMARY KEY,
    Comment VARCHAR(255) NOT NULL,
    Pakage_ID INT(11) NOT NULL,
    Admin_ID INT(11) NOT NULL,
    FOREIGN KEY (Pakage_ID) REFERENCES buy_package(Package_ID),
    FOREIGN KEY (Admin_ID) REFERENCES admin(Admin_ID)
);
-- 19 owner_notification
CREATE TABLE owner_notification (
    notif_id INT AUTO_INCREMENT PRIMARY KEY,
    CA_ID INT NOT NULL,
    type ENUM('package','advertisement','venue') NOT NULL,
    ref_id INT NOT NULL,
    title VARCHAR(255) NOT NULL,
    message TEXT NOT NULL,
    flagged_fields VARCHAR(500) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);