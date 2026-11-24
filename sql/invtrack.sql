CREATE DATABASE IF NOT EXISTS invtrack CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE invtrack;


CREATE TABLE IF NOT EXISTS users (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  name        VARCHAR(100) NOT NULL,
  email       VARCHAR(150) NOT NULL UNIQUE,
  password    VARCHAR(255) NOT NULL,
  role        ENUM('admin','staff','warehouse') NOT NULL DEFAULT 'staff',
  avatar      VARCHAR(10)  NOT NULL DEFAULT 'U'
) ENGINE=InnoDB;

DELETE FROM users WHERE email IN (
  'admin@invtrack.com',
  'staff@invtrack.com',
  'customer@invtrack.com',
  'warehouse@invtrack.com'
);
DELETE FROM users WHERE role = 'customer';
ALTER TABLE users MODIFY role ENUM('admin','staff','warehouse') NOT NULL DEFAULT 'staff';

CREATE TABLE IF NOT EXISTS categories (
  id          VARCHAR(20) PRIMARY KEY,
  name        VARCHAR(80)  NOT NULL,
  color       VARCHAR(10)  NOT NULL DEFAULT '#6366F1',
  description VARCHAR(200) NOT NULL DEFAULT ''
) ENGINE=InnoDB;

INSERT IGNORE INTO categories VALUES
  ('CAT-001', 'Laptops',       '#6366F1', 'Portable computers and notebooks'),
  ('CAT-002', 'Smartphones',   '#06B6D4', 'Mobile phones and accessories'),
  ('CAT-003', 'Tablets',       '#10B981', 'Tablet devices and e-readers'),
  ('CAT-004', 'Peripherals',   '#F59E0B', 'Keyboards, mice, and input devices'),
  ('CAT-005', 'Networking',    '#EF4444', 'Routers, switches, and cables'),
  ('CAT-006', 'Audio & Video', '#8B5CF6', 'Headphones, speakers, and displays'),
  ('CAT-007', 'Storage',       '#EC4899', 'SSDs, HDDs, and flash drives'),
  ('CAT-008', 'Components',    '#14B8A6', 'CPUs, RAM, GPUs, and motherboards');

CREATE TABLE IF NOT EXISTS inventory (
  id          VARCHAR(20)  PRIMARY KEY,
  name        VARCHAR(150) NOT NULL,
  category    VARCHAR(80)  NOT NULL,
  qty         INT          NOT NULL DEFAULT 0,
  reorder     INT          NOT NULL DEFAULT 5,
  status      VARCHAR(30)  NOT NULL DEFAULT 'In Stock',
  location    VARCHAR(30)  NOT NULL DEFAULT '',
  cost        DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  barcode     VARCHAR(50)  NOT NULL DEFAULT ''
) ENGINE=InnoDB;

INSERT IGNORE INTO inventory VALUES
  ('SKU-T001', 'Apple MacBook Air M3 13-inch',         'Laptops',       45,  10, 'In Stock',     'A-01-1', 89990.00,  '1940000000001'),
  ('SKU-T002', 'ASUS VivoBook 15 Intel i5',            'Laptops',       30,   8, 'In Stock',     'A-01-2', 42995.00,  '1940000000002'),
  ('SKU-T003', 'Lenovo ThinkPad X1 Carbon Gen 12',     'Laptops',       12,   5, 'Low Stock',    'A-01-3', 104995.00, '1940000000003'),
  ('SKU-T004', 'Samsung Galaxy S24 Ultra 256GB',       'Smartphones',   80,  20, 'In Stock',     'B-01-1', 64990.00,  '1940000000004'),
  ('SKU-T005', 'Apple iPhone 15 Pro 128GB',            'Smartphones',   55,  15, 'In Stock',     'B-01-2', 66990.00,  '1940000000005'),
  ('SKU-T006', 'Xiaomi Redmi Note 13 Pro 5G',          'Smartphones',    7,  20, 'Low Stock',    'B-01-3', 14999.00,  '1940000000006'),
  ('SKU-T007', 'Apple iPad Air M2 11-inch WiFi 256GB', 'Tablets',       28,   8, 'In Stock',     'C-01-1', 52990.00,  '1940000000007'),
  ('SKU-T008', 'Samsung Galaxy Tab S9 FE 128GB',       'Tablets',        0,  10, 'Out of Stock', 'C-01-2', 22990.00,  '1940000000008'),
  ('SKU-T009', 'Logitech MX Keys S Wireless Keyboard', 'Peripherals',  120,  30, 'In Stock',     'D-01-1',  6995.00,  '1940000000009'),
  ('SKU-T010', 'Logitech MX Master 3S Mouse',          'Peripherals',   95,  25, 'In Stock',     'D-01-2',  4995.00,  '1940000000010'),
  ('SKU-T011', 'TP-Link Archer AX73 WiFi 6 Router',    'Networking',    38,  10, 'In Stock',     'E-01-1',  7499.00,  '1940000000011'),
  ('SKU-T012', 'Sony WH-1000XM5 Noise Cancelling',     'Audio & Video', 22,   8, 'In Stock',     'F-01-1', 18999.00,  '1940000000012'),
  ('SKU-T013', 'Samsung 27" Odyssey G5 QHD 165Hz',     'Audio & Video',  4,   5, 'Low Stock',    'F-01-2', 19990.00,  '1940000000013'),
  ('SKU-T014', 'Samsung 990 Pro 1TB NVMe SSD',         'Storage',      200,  50, 'In Stock',     'G-01-1',  6490.00,  '1940000000014'),
  ('SKU-T015', 'SanDisk Extreme Pro 1TB USB-C',        'Storage',      150,  40, 'In Stock',     'G-01-2',  4299.00,  '1940000000015'),
  ('SKU-T016', 'AMD Ryzen 9 7950X Processor',          'Components',     8,   5, 'Low Stock',    'H-01-1', 32500.00,  '1940000000016'),
  ('SKU-T017', 'NVIDIA RTX 4070 Super 12GB',           'Components',     0,   5, 'Out of Stock', 'H-01-2', 41995.00,  '1940000000017'),
  ('SKU-T018', 'Corsair Vengeance 32GB DDR5 6000MHz',  'Components',    75,  20, 'In Stock',     'H-01-3',  9500.00,  '1940000000018');

CREATE TABLE IF NOT EXISTS suppliers (
  id          VARCHAR(20)  PRIMARY KEY,
  name        VARCHAR(100) NOT NULL,
  country     VARCHAR(60)  NOT NULL,
  category    VARCHAR(60)  NOT NULL,
  rating      DECIMAL(3,1) NOT NULL DEFAULT 0.0,
  orders      INT          NOT NULL DEFAULT 0,
  spend       DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  on_time     INT          NOT NULL DEFAULT 0,
  status      VARCHAR(30)  NOT NULL DEFAULT 'Active'
) ENGINE=InnoDB;

INSERT IGNORE INTO suppliers VALUES
  ('SUP-001', 'Apex Industrial Supply',  'Germany', 'Mechanical',  4.8, 142, 284000.00, 97, 'Active'),
  ('SUP-002', 'Pacific Rim Components',  'China',   'Electronics', 4.2,  89, 521000.00, 88, 'Active'),
  ('SUP-003', 'NordFil AB',              'Sweden',  'Filtration',  4.6,  54,  98000.00, 94, 'Active'),
  ('SUP-004', 'Gulf Coast Metals',       'USA',     'Structural',  3.9, 211, 367000.00, 81, 'Under Review'),
  ('SUP-005', 'Conveyor Systems GmbH',   'Austria', 'Conveyor',    4.5,  37, 142000.00, 92, 'Active');

CREATE TABLE IF NOT EXISTS purchase_orders (
  id            VARCHAR(30)  PRIMARY KEY,
  supplier      VARCHAR(100) NOT NULL,
  items         INT          NOT NULL DEFAULT 0,
  value         DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  order_date    DATE         NOT NULL,
  delivery_date DATE         NOT NULL,
  status        VARCHAR(30)  NOT NULL DEFAULT 'Pending'
) ENGINE=InnoDB;

INSERT IGNORE INTO purchase_orders VALUES
  ('PO-2026-0441', 'Apex Industrial Supply',  8,  18420.00, '2026-07-01', '2026-07-20', 'Confirmed'),
  ('PO-2026-0442', 'Pacific Rim Components',  12, 64800.00, '2026-07-03', '2026-07-28', 'In Review'),
  ('PO-2026-0443', 'NordFil AB',               4,  7200.00, '2026-07-05', '2026-07-18', 'Confirmed'),
  ('PO-2026-0444', 'Gulf Coast Metals',        20, 32100.00, '2026-07-08', '2026-07-22', 'Pending'),
  ('PO-2026-0445', 'Conveyor Systems GmbH',    3,  11400.00, '2026-07-09', '2026-07-25', 'Confirmed');

CREATE TABLE IF NOT EXISTS shop_orders (
  id           VARCHAR(30)  PRIMARY KEY,
  shop         VARCHAR(120) NOT NULL,
  items        VARCHAR(500) NOT NULL,
  units        INT          NOT NULL DEFAULT 0,
  address      VARCHAR(200) NOT NULL DEFAULT '',
  dest_lat     DECIMAL(9,6) NULL,
  dest_lng     DECIMAL(9,6) NULL,
  requested_at DATETIME     NOT NULL,
  status       ENUM('Pending','Picking','Ready for Delivery','Out for Delivery','Completed') NOT NULL DEFAULT 'Pending'
) ENGINE=InnoDB;


INSERT INTO shop_orders (id, shop, items, units, address, dest_lat, dest_lng, requested_at, status) VALUES
  ('SO-1049', 'BGC High Street Shop',    'Sony WH-1000XM5 ×2',                              2, 'High Street, Bonifacio Global City, Taguig', 14.550800, 121.050100, '2026-07-12 10:05:00', 'Ready for Delivery'),
  ('SO-1048', 'Makati Central Shop',     'MacBook Air M3 ×2, MX Master 3S ×4',             6, 'Ayala Avenue, Makati City',                  14.554700, 121.024400, '2026-07-12 09:15:00', 'Pending'),
  ('SO-1047', 'Quezon City North Shop',  'Galaxy S24 Ultra ×3, Galaxy Tab S9 FE ×2',       5, 'North Avenue, Quezon City',                  14.656300, 121.032500, '2026-07-12 08:40:00', 'Picking'),
  ('SO-1046', 'Cebu IT Park Shop',       'iPad Air M2 ×5',                                 5, 'IT Park, Lahug, Cebu City',                  10.330400, 123.905800, '2026-07-11 16:20:00', 'Ready for Delivery'),
  ('SO-1045', 'BGC High Street Shop',    'MX Keys S ×8, MX Master 3S ×8',                  16, 'High Street, Bonifacio Global City, Taguig', 14.550800, 121.050100, '2026-07-11 13:05:00', 'Completed')
ON DUPLICATE KEY UPDATE
  shop = VALUES(shop),
  items = VALUES(items),
  units = VALUES(units),
  address = VALUES(address),
  dest_lat = VALUES(dest_lat),
  dest_lng = VALUES(dest_lng),
  requested_at = VALUES(requested_at);

CREATE TABLE IF NOT EXISTS shipments (
  id          VARCHAR(20)  PRIMARY KEY,
  origin      VARCHAR(100) NOT NULL,
  destination VARCHAR(100) NOT NULL,
  dest_lat    DECIMAL(9,6) NULL,
  dest_lng    DECIMAL(9,6) NULL,
  status      VARCHAR(30)  NOT NULL DEFAULT 'For Approval',
  eta         DATE         NOT NULL,
  carrier     VARCHAR(80)  NOT NULL,
  items       INT          NOT NULL DEFAULT 0,
  weight      VARCHAR(30)  NOT NULL DEFAULT '',
  created_by  INT          NULL,
  created_at  DATETIME     NULL,
  approved_by INT          NULL,
  approved_at DATETIME     NULL,
  review_note VARCHAR(255) NOT NULL DEFAULT ''
) ENGINE=InnoDB;


INSERT IGNORE INTO shipments (id, origin, destination, status, eta, carrier, items, weight) VALUES
  ('SHP-7821', 'Shanghai, CN',  'Houston, TX',     'In Transit', '2026-07-15', 'Maersk',       142, '2,840 kg'),
  ('SHP-7822', 'Rotterdam, NL', 'Newark, NJ',      'Customs',    '2026-07-13', 'MSC',            67, '1,120 kg'),
  ('SHP-7823', 'Houston, TX',   'Chicago, IL',     'Delivered',  '2026-07-10', 'FedEx Freight',  88, '940 kg'),
  ('SHP-7824', 'Toronto, CA',   'Detroit, MI',     'Picked Up',  '2026-07-12', 'XPO Logistics',  23, '380 kg'),
  ('SHP-7825', 'Shenzhen, CN',  'Los Angeles, CA', 'In Transit', '2026-07-18', 'COSCO',         200, '5,200 kg');

INSERT IGNORE INTO shipments (id, origin, destination, dest_lat, dest_lng, status, eta, carrier, items, weight, created_at) VALUES
  ('SHP-1001', 'PCS Computers Warehouse, Naga City', 'SM City Naga, CBD II, Naga City',      13.624500, 123.186800, 'For Approval', '2026-11-25', 'In-house', 12, '35 kg', NOW()),
  ('SHP-1002', 'PCS Computers Warehouse, Naga City', 'Iriga City Public Market, Iriga City', 13.420700, 123.412300, 'Approved',     '2026-11-24', 'In-house',  6, '18 kg', NOW());

CREATE TABLE IF NOT EXISTS warehouse_zones (
  zone        VARCHAR(30)  PRIMARY KEY,
  capacity    INT          NOT NULL DEFAULT 0,
  occupied    INT          NOT NULL DEFAULT 0,
  type        VARCHAR(60)  NOT NULL DEFAULT '',
  temp        VARCHAR(30)  NOT NULL DEFAULT 'Ambient'
) ENGINE=InnoDB;

INSERT IGNORE INTO warehouse_zones VALUES
  ('Zone A', 5000, 4210, 'Raw Materials',    'Ambient'),
  ('Zone B', 3200, 1890, 'Filtration',       'Ambient'),
  ('Zone C', 2400, 2390, 'Mechanical Parts', 'Controlled'),
  ('Zone D', 1800,  950, 'Electronics',      'Climate'),
  ('Zone E', 6000, 3100, 'Conveyor Systems', 'Ambient'),
  ('Zone F', 8000, 6200, 'Bulk Storage',     'Ambient');

CREATE TABLE IF NOT EXISTS vehicles (
  id              VARCHAR(16)  PRIMARY KEY,
  driver_name     VARCHAR(100) NOT NULL,
  plate           VARCHAR(20)  NOT NULL DEFAULT '',
  route_label     VARCHAR(200) NOT NULL DEFAULT '',
  dest_lat        DECIMAL(9,6) NULL,
  dest_lng        DECIMAL(9,6) NULL,
  active          TINYINT(1)   NOT NULL DEFAULT 1,
  last_lat        DECIMAL(9,6) NULL,
  last_lng        DECIMAL(9,6) NULL,
  last_speed_kmh  DECIMAL(6,1) NULL,
  last_heading    SMALLINT     NULL,
  last_accuracy_m DECIMAL(7,1) NULL,
  last_seen       DATETIME(3)  NULL
) ENGINE=InnoDB;

INSERT IGNORE INTO vehicles (id, driver_name, plate, route_label, dest_lat, dest_lng) VALUES
  ('V-01', 'Miguel Santos', '', 'Manila Warehouse → Makati Central Shop',       14.554700, 121.024400),
  ('V-02', 'Ana Reyes',     '', 'Cebu Warehouse',                               NULL,      NULL),
  ('V-03', 'Carlo Mendoza', '', 'Quezon City Warehouse → BGC High Street Shop', 14.550800, 121.050100);

CREATE TABLE IF NOT EXISTS vehicle_positions (
  id          BIGINT AUTO_INCREMENT PRIMARY KEY,
  vehicle_id  VARCHAR(16)  NOT NULL,
  lat         DECIMAL(9,6) NOT NULL,
  lng         DECIMAL(9,6) NOT NULL,
  speed_kmh   DECIMAL(6,1) NULL,
  heading     SMALLINT     NULL,
  accuracy_m  DECIMAL(7,1) NULL,
  recorded_at DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  INDEX idx_vehicle_time (vehicle_id, recorded_at),
  CONSTRAINT fk_pos_vehicle FOREIGN KEY (vehicle_id) REFERENCES vehicles(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS deliveries (
  id             INT AUTO_INCREMENT PRIMARY KEY,
  shop_order_id  VARCHAR(30)  NULL,
  shipment_id    VARCHAR(20)  NULL,
  driver_user_id INT          NOT NULL,
  vehicle_id     VARCHAR(16)  NOT NULL,
  status         ENUM('Accepted','In Transit','Delivered') NOT NULL DEFAULT 'Accepted',
  accepted_at    DATETIME     NOT NULL,
  started_at     DATETIME     NULL,
  delivered_at   DATETIME     NULL,
  recipient_name VARCHAR(100) NOT NULL DEFAULT '',
  notes          VARCHAR(255) NOT NULL DEFAULT '',
  UNIQUE KEY uq_delivery_order (shop_order_id),
  UNIQUE KEY uq_delivery_shipment (shipment_id),
  INDEX idx_delivery_driver (driver_user_id, status),
  CONSTRAINT fk_delivery_order    FOREIGN KEY (shop_order_id)  REFERENCES shop_orders(id) ON DELETE CASCADE,
  CONSTRAINT fk_delivery_shipment FOREIGN KEY (shipment_id)    REFERENCES shipments(id)   ON DELETE CASCADE,
  CONSTRAINT fk_delivery_driver  FOREIGN KEY (driver_user_id) REFERENCES users(id)       ON DELETE CASCADE,
  CONSTRAINT fk_delivery_vehicle FOREIGN KEY (vehicle_id)     REFERENCES vehicles(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS delivery_cancellations (
  id             INT AUTO_INCREMENT PRIMARY KEY,
  kind           ENUM('order','shipment') NOT NULL,
  job_id         VARCHAR(30)  NOT NULL,
  driver_user_id INT          NOT NULL,
  vehicle_id     VARCHAR(16)  NOT NULL,
  reason         VARCHAR(255) NOT NULL,
  was_started    TINYINT(1)   NOT NULL DEFAULT 0,
  cancelled_at   DATETIME     NOT NULL,
  INDEX idx_cancel_job (kind, job_id, cancelled_at),
  INDEX idx_cancel_driver (driver_user_id, cancelled_at),
  CONSTRAINT fk_cancel_driver FOREIGN KEY (driver_user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;
