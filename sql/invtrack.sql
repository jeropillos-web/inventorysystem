-- ============================================================================
-- INVTrack: Inventory Management and Logistics System for PCS Computers
-- Complete database: tables + sample data.
--
-- XAMPP: open phpMyAdmin > Import > choose this file > Go.
--        It creates the "invtrack" database if it does not exist.
-- Web hosting: create the database in your hosting panel first, select it in
--        phpMyAdmin, delete the two lines "CREATE DATABASE ..." and "USE ..."
--        below, then import.
--
-- IMPORTANT: importing this file RESETS all INVTrack tables and data.
-- To keep the data of an older INVTrack database, open upgrade.php instead.
-- After importing, open the sign-up page: the first account may choose the
-- Administrator role.
-- ============================================================================

CREATE DATABASE IF NOT EXISTS invtrack CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE invtrack;

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;
DROP TABLE IF EXISTS tracking_logs;
DROP TABLE IF EXISTS transfer_items;
DROP TABLE IF EXISTS transfers;
DROP TABLE IF EXISTS disposals;
DROP TABLE IF EXISTS item_returns;
DROP TABLE IF EXISTS stock_movements;
DROP TABLE IF EXISTS stock_levels;
DROP TABLE IF EXISTS delivery_cancellations;
DROP TABLE IF EXISTS deliveries;
DROP TABLE IF EXISTS vehicle_positions;
DROP TABLE IF EXISTS vehicles;
DROP TABLE IF EXISTS shipments;
DROP TABLE IF EXISTS shop_orders;
DROP TABLE IF EXISTS purchase_orders;
DROP TABLE IF EXISTS suppliers;
DROP TABLE IF EXISTS warehouse_zones;
DROP TABLE IF EXISTS inventory;
DROP TABLE IF EXISTS categories;
DROP TABLE IF EXISTS warehouses;
DROP TABLE IF EXISTS users;
SET FOREIGN_KEY_CHECKS = 1;

-- ── Users and roles ─────────────────────────────────────────────────────────
CREATE TABLE users (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  name        VARCHAR(100) NOT NULL,
  email       VARCHAR(150) NOT NULL UNIQUE,
  password    VARCHAR(255) NOT NULL,
  role        ENUM('admin','staff','warehouse') NOT NULL DEFAULT 'staff',
  avatar      VARCHAR(10)  NOT NULL DEFAULT 'U'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Warehouses and stock ────────────────────────────────────────────────────
CREATE TABLE warehouses (
  id          VARCHAR(20)  PRIMARY KEY,
  name        VARCHAR(100) NOT NULL UNIQUE,
  address     VARCHAR(200) NOT NULL DEFAULT '',
  is_main     TINYINT(1)   NOT NULL DEFAULT 0,
  active      TINYINT(1)   NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE categories (
  id          VARCHAR(20)  PRIMARY KEY,
  name        VARCHAR(80)  NOT NULL,
  color       VARCHAR(10)  NOT NULL DEFAULT '#6366F1',
  description VARCHAR(200) NOT NULL DEFAULT ''
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- qty is the total of all warehouses (from stock_levels); status is computed
-- from qty and reorder: Out of Stock (0), Low Stock (at or below reorder), In Stock.
CREATE TABLE inventory (
  id          VARCHAR(20)   PRIMARY KEY,
  name        VARCHAR(150)  NOT NULL,
  category    VARCHAR(80)   NOT NULL DEFAULT '',
  qty         INT           NOT NULL DEFAULT 0,
  reorder     INT           NOT NULL DEFAULT 5,
  status      VARCHAR(30)   NOT NULL DEFAULT 'Out of Stock',
  location    VARCHAR(30)   NOT NULL DEFAULT '',
  cost        DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  barcode     VARCHAR(50)   NOT NULL DEFAULT ''
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Quantity of each product in each warehouse.
CREATE TABLE stock_levels (
  item_id      VARCHAR(20) NOT NULL,
  warehouse_id VARCHAR(20) NOT NULL,
  qty          INT         NOT NULL DEFAULT 0,
  PRIMARY KEY (item_id, warehouse_id),
  CONSTRAINT fk_level_item      FOREIGN KEY (item_id)      REFERENCES inventory(id)  ON DELETE CASCADE,
  CONSTRAINT fk_level_warehouse FOREIGN KEY (warehouse_id) REFERENCES warehouses(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Stock movement history (stock card): one row for every quantity change.
-- type: Initial Stock, Stock In, Adjustment, Return Restock, Disposal, Transfer Out, Transfer In
CREATE TABLE stock_movements (
  id            BIGINT AUTO_INCREMENT PRIMARY KEY,
  item_id       VARCHAR(20)  NOT NULL,
  warehouse_id  VARCHAR(20)  NOT NULL,
  qty_change    INT          NOT NULL,
  balance_after INT          NOT NULL,
  type          VARCHAR(30)  NOT NULL,
  reference     VARCHAR(30)  NOT NULL DEFAULT '',
  note          VARCHAR(255) NOT NULL DEFAULT '',
  user_id       INT          NULL,
  created_at    DATETIME     NOT NULL,
  INDEX idx_move_item (item_id, created_at),
  INDEX idx_move_time (created_at),
  CONSTRAINT fk_move_item      FOREIGN KEY (item_id)      REFERENCES inventory(id)  ON DELETE CASCADE,
  CONSTRAINT fk_move_warehouse FOREIGN KEY (warehouse_id) REFERENCES warehouses(id),
  CONSTRAINT fk_move_user      FOREIGN KEY (user_id)      REFERENCES users(id)      ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE warehouse_zones (
  zone        VARCHAR(30)  PRIMARY KEY,
  capacity    INT          NOT NULL DEFAULT 0,
  occupied    INT          NOT NULL DEFAULT 0,
  type        VARCHAR(60)  NOT NULL DEFAULT '',
  temp        VARCHAR(30)  NOT NULL DEFAULT 'Ambient'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Procurement ─────────────────────────────────────────────────────────────
CREATE TABLE suppliers (
  id          VARCHAR(20)   PRIMARY KEY,
  name        VARCHAR(100)  NOT NULL,
  country     VARCHAR(60)   NOT NULL DEFAULT 'Philippines',
  category    VARCHAR(60)   NOT NULL DEFAULT '',
  rating      DECIMAL(3,1)  NOT NULL DEFAULT 0.0,
  orders      INT           NOT NULL DEFAULT 0,
  spend       DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  on_time     INT           NOT NULL DEFAULT 0,
  status      VARCHAR(30)   NOT NULL DEFAULT 'Active'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE purchase_orders (
  id            VARCHAR(30)   PRIMARY KEY,
  supplier      VARCHAR(100)  NOT NULL,
  items         INT           NOT NULL DEFAULT 0,
  value         DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  order_date    DATE          NOT NULL,
  delivery_date DATE          NOT NULL,
  status        VARCHAR(30)   NOT NULL DEFAULT 'Pending'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Shop orders, shipments and deliveries ───────────────────────────────────
CREATE TABLE shop_orders (
  id           VARCHAR(30)  PRIMARY KEY,
  shop         VARCHAR(120) NOT NULL,
  items        VARCHAR(500) NOT NULL,
  units        INT          NOT NULL DEFAULT 0,
  address      VARCHAR(200) NOT NULL DEFAULT '',
  requested_at DATETIME     NOT NULL,
  status       ENUM('Pending','Picking','Ready for Delivery','Out for Delivery','Completed') NOT NULL DEFAULT 'Pending'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Flow: For Approval -> Approved / Rejected (warehouse) -> In Transit (driver) -> Delivered
CREATE TABLE shipments (
  id          VARCHAR(20)  PRIMARY KEY,
  origin      VARCHAR(100) NOT NULL,
  destination VARCHAR(100) NOT NULL,
  status      VARCHAR(30)  NOT NULL DEFAULT 'For Approval',
  eta         DATE         NOT NULL,
  carrier     VARCHAR(80)  NOT NULL DEFAULT 'In-house',
  items       INT          NOT NULL DEFAULT 0,
  weight      VARCHAR(30)  NOT NULL DEFAULT '',
  created_by  INT          NULL,
  created_at  DATETIME     NULL,
  approved_by INT          NULL,
  approved_at DATETIME     NULL,
  review_note VARCHAR(255) NOT NULL DEFAULT ''
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE vehicles (
  id          VARCHAR(16)  PRIMARY KEY,
  plate       VARCHAR(20)  NOT NULL DEFAULT '',
  type        VARCHAR(40)  NOT NULL DEFAULT '',
  active      TINYINT(1)   NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One row per job a driver has accepted: a shop order or a shipment (exactly one
-- of the two). The UNIQUE keys make sure two drivers never take the same job.
CREATE TABLE deliveries (
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
  CONSTRAINT fk_delivery_driver   FOREIGN KEY (driver_user_id) REFERENCES users(id)       ON DELETE CASCADE,
  CONSTRAINT fk_delivery_vehicle  FOREIGN KEY (vehicle_id)     REFERENCES vehicles(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Deliveries a driver cancelled. The job goes back to the queue; the reason stays here.
CREATE TABLE delivery_cancellations (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Returns ─────────────────────────────────────────────────────────────────
-- Flow: Pending Inspection -> Restocked / Returned to Supplier / For Disposal (-> Disposed) / Rejected
CREATE TABLE item_returns (
  id             INT AUTO_INCREMENT PRIMARY KEY,
  item_id        VARCHAR(20)  NOT NULL,
  warehouse_id   VARCHAR(20)  NOT NULL,
  qty            INT          NOT NULL,
  source         VARCHAR(20)  NOT NULL,
  source_ref     VARCHAR(120) NOT NULL DEFAULT '',
  reason         VARCHAR(60)  NOT NULL,
  item_condition VARCHAR(20)  NOT NULL,
  status         VARCHAR(30)  NOT NULL DEFAULT 'Pending Inspection',
  disposition    VARCHAR(30)  NULL,
  supplier       VARCHAR(100) NOT NULL DEFAULT '',
  notes          VARCHAR(255) NOT NULL DEFAULT '',
  decision_note  VARCHAR(255) NOT NULL DEFAULT '',
  created_by     INT          NULL,
  created_at     DATETIME     NOT NULL,
  decided_by     INT          NULL,
  decided_at     DATETIME     NULL,
  INDEX idx_return_status (status),
  CONSTRAINT fk_return_item      FOREIGN KEY (item_id)      REFERENCES inventory(id),
  CONSTRAINT fk_return_warehouse FOREIGN KEY (warehouse_id) REFERENCES warehouses(id),
  CONSTRAINT fk_return_creator   FOREIGN KEY (created_by)   REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_return_decider   FOREIGN KEY (decided_by)   REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Disposal (stock write-off) ──────────────────────────────────────────────
-- Flow: Pending Approval -> Approved (stock deducted) -> Disposed, or Rejected.
-- return_id is set when the item came from a return (no stock deduction then).
CREATE TABLE disposals (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  item_id       VARCHAR(20)   NOT NULL,
  warehouse_id  VARCHAR(20)   NOT NULL,
  qty           INT           NOT NULL,
  reason        VARCHAR(40)   NOT NULL,
  method        VARCHAR(60)   NOT NULL,
  return_id     INT           NULL,
  unit_cost     DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  status        VARCHAR(20)   NOT NULL DEFAULT 'Pending Approval',
  notes         VARCHAR(255)  NOT NULL DEFAULT '',
  review_note   VARCHAR(255)  NOT NULL DEFAULT '',
  reference     VARCHAR(80)   NOT NULL DEFAULT '',
  requested_by  INT           NULL,
  requested_at  DATETIME      NOT NULL,
  approved_by   INT           NULL,
  approved_at   DATETIME      NULL,
  disposed_by   INT           NULL,
  disposed_at   DATETIME      NULL,
  INDEX idx_disposal_status (status),
  CONSTRAINT fk_disposal_item      FOREIGN KEY (item_id)      REFERENCES inventory(id),
  CONSTRAINT fk_disposal_warehouse FOREIGN KEY (warehouse_id) REFERENCES warehouses(id),
  CONSTRAINT fk_disposal_return    FOREIGN KEY (return_id)    REFERENCES item_returns(id) ON DELETE SET NULL,
  CONSTRAINT fk_disposal_requester FOREIGN KEY (requested_by) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_disposal_approver  FOREIGN KEY (approved_by)  REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_disposal_disposer  FOREIGN KEY (disposed_by)  REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Warehouse-to-warehouse transfers ────────────────────────────────────────
-- Flow: Requested -> Approved / Rejected -> In Transit (dispatched) -> Received; Cancelled before dispatch.
CREATE TABLE transfers (
  id             INT AUTO_INCREMENT PRIMARY KEY,
  from_warehouse VARCHAR(20)  NOT NULL,
  to_warehouse   VARCHAR(20)  NOT NULL,
  status         VARCHAR(20)  NOT NULL DEFAULT 'Requested',
  notes          VARCHAR(255) NOT NULL DEFAULT '',
  review_note    VARCHAR(255) NOT NULL DEFAULT '',
  requested_by   INT          NULL,
  requested_at   DATETIME     NOT NULL,
  approved_by    INT          NULL,
  approved_at    DATETIME     NULL,
  dispatched_by  INT          NULL,
  dispatched_at  DATETIME     NULL,
  received_by    INT          NULL,
  received_at    DATETIME     NULL,
  INDEX idx_transfer_status (status),
  CONSTRAINT fk_transfer_from      FOREIGN KEY (from_warehouse) REFERENCES warehouses(id),
  CONSTRAINT fk_transfer_to        FOREIGN KEY (to_warehouse)   REFERENCES warehouses(id),
  CONSTRAINT fk_transfer_requester FOREIGN KEY (requested_by)  REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_transfer_approver  FOREIGN KEY (approved_by)   REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_transfer_sender    FOREIGN KEY (dispatched_by) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_transfer_receiver  FOREIGN KEY (received_by)   REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE transfer_items (
  transfer_id INT         NOT NULL,
  item_id     VARCHAR(20) NOT NULL,
  qty         INT         NOT NULL,
  PRIMARY KEY (transfer_id, item_id),
  CONSTRAINT fk_titem_transfer FOREIGN KEY (transfer_id) REFERENCES transfers(id) ON DELETE CASCADE,
  CONSTRAINT fk_titem_item     FOREIGN KEY (item_id)     REFERENCES inventory(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Text tracking log (replaces the real-time GPS tracker) ───────────────────
-- ref_type: shipment, shop_order, transfer. ref_id: the tracking number
-- (SHP-12345, SO-1048, TRF-00007).
CREATE TABLE tracking_logs (
  id         BIGINT AUTO_INCREMENT PRIMARY KEY,
  ref_type   VARCHAR(20)  NOT NULL,
  ref_id     VARCHAR(30)  NOT NULL,
  status     VARCHAR(40)  NOT NULL,
  location   VARCHAR(150) NOT NULL DEFAULT '',
  remarks    VARCHAR(255) NOT NULL DEFAULT '',
  logged_by  INT          NULL,
  logged_at  DATETIME     NOT NULL,
  INDEX idx_track_ref (ref_type, ref_id, logged_at),
  CONSTRAINT fk_track_user FOREIGN KEY (logged_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- Sample data (PCS Computers, Camarines Sur). Edit or delete it freely.
-- ============================================================================
INSERT INTO warehouses (id, name, address, is_main, active) VALUES
  ('WH-01', 'PCS Computers Naga', 'Yashano Mall, Diversion Road, Brgy. Triangulo, Naga City, Camarines Sur', 1, 1),
  ('WH-02', 'PCS Computers Legazpi', 'Legazpi City, Albay', 0, 1);

INSERT INTO categories (id, name, color, description) VALUES
  ('CAT-001', 'Laptops', '#6366F1', 'Portable computers and notebooks'),
  ('CAT-002', 'Smartphones', '#06B6D4', 'Mobile phones and accessories'),
  ('CAT-003', 'Tablets', '#10B981', 'Tablet devices and e-readers'),
  ('CAT-004', 'Peripherals', '#F59E0B', 'Keyboards, mice, and input devices'),
  ('CAT-005', 'Networking', '#EF4444', 'Routers, switches, and cables'),
  ('CAT-006', 'Audio & Video', '#8B5CF6', 'Headphones, speakers, and displays'),
  ('CAT-007', 'Storage', '#EC4899', 'SSDs, HDDs, and flash drives'),
  ('CAT-008', 'Components', '#14B8A6', 'CPUs, RAM, GPUs, and motherboards');

INSERT INTO inventory (id, name, category, qty, reorder, status, location, cost, barcode) VALUES
  ('SKU-T001', 'Apple MacBook Air M3 13-inch', 'Laptops', 45, 10, 'In Stock', 'A-01-1', 89990.0, '1940000000001'),
  ('SKU-T002', 'ASUS VivoBook 15 Intel i5', 'Laptops', 30, 8, 'In Stock', 'A-01-2', 42995.0, '1940000000002'),
  ('SKU-T003', 'Lenovo ThinkPad X1 Carbon Gen 12', 'Laptops', 4, 5, 'Low Stock', 'A-01-3', 104995.0, '1940000000003'),
  ('SKU-T004', 'Samsung Galaxy S24 Ultra 256GB', 'Smartphones', 80, 20, 'In Stock', 'B-01-1', 64990.0, '1940000000004'),
  ('SKU-T005', 'Apple iPhone 15 Pro 128GB', 'Smartphones', 55, 15, 'In Stock', 'B-01-2', 66990.0, '1940000000005'),
  ('SKU-T006', 'Xiaomi Redmi Note 13 Pro 5G', 'Smartphones', 7, 20, 'Low Stock', 'B-01-3', 14999.0, '1940000000006'),
  ('SKU-T007', 'Apple iPad Air M2 11-inch WiFi 256GB', 'Tablets', 28, 8, 'In Stock', 'C-01-1', 52990.0, '1940000000007'),
  ('SKU-T008', 'Samsung Galaxy Tab S9 FE 128GB', 'Tablets', 0, 10, 'Out of Stock', 'C-01-2', 22990.0, '1940000000008'),
  ('SKU-T009', 'Logitech MX Keys S Wireless Keyboard', 'Peripherals', 120, 30, 'In Stock', 'D-01-1', 6995.0, '1940000000009'),
  ('SKU-T010', 'Logitech MX Master 3S Mouse', 'Peripherals', 96, 25, 'In Stock', 'D-01-2', 4995.0, '1940000000010'),
  ('SKU-T011', 'TP-Link Archer AX73 WiFi 6 Router', 'Networking', 50, 10, 'In Stock', 'E-01-1', 7499.0, '1940000000011'),
  ('SKU-T012', 'Sony WH-1000XM5 Noise Cancelling', 'Audio & Video', 22, 8, 'In Stock', 'F-01-1', 18999.0, '1940000000012'),
  ('SKU-T013', 'Samsung 27-inch Odyssey G5 QHD 165Hz', 'Audio & Video', 3, 5, 'Low Stock', 'F-01-2', 19990.0, '1940000000013'),
  ('SKU-T014', 'Samsung 990 Pro 1TB NVMe SSD', 'Storage', 200, 50, 'In Stock', 'G-01-1', 6490.0, '1940000000014'),
  ('SKU-T015', 'SanDisk Extreme Pro 1TB USB-C', 'Storage', 150, 40, 'In Stock', 'G-01-2', 4299.0, '1940000000015'),
  ('SKU-T016', 'AMD Ryzen 9 7950X Processor', 'Components', 8, 5, 'In Stock', 'H-01-1', 32500.0, '1940000000016'),
  ('SKU-T017', 'NVIDIA RTX 4070 Super 12GB', 'Components', 0, 5, 'Out of Stock', 'H-01-2', 41995.0, '1940000000017'),
  ('SKU-T018', 'Corsair Vengeance 32GB DDR5 6000MHz', 'Components', 75, 20, 'In Stock', 'H-01-3', 9500.0, '1940000000018');

INSERT INTO stock_levels (item_id, warehouse_id, qty) VALUES
  ('SKU-T001', 'WH-01', 40),
  ('SKU-T001', 'WH-02', 5),
  ('SKU-T002', 'WH-01', 24),
  ('SKU-T002', 'WH-02', 6),
  ('SKU-T003', 'WH-01', 4),
  ('SKU-T004', 'WH-01', 60),
  ('SKU-T004', 'WH-02', 20),
  ('SKU-T005', 'WH-01', 55),
  ('SKU-T006', 'WH-01', 7),
  ('SKU-T007', 'WH-01', 28),
  ('SKU-T009', 'WH-01', 110),
  ('SKU-T009', 'WH-02', 10),
  ('SKU-T010', 'WH-01', 81),
  ('SKU-T010', 'WH-02', 15),
  ('SKU-T011', 'WH-01', 50),
  ('SKU-T012', 'WH-01', 22),
  ('SKU-T013', 'WH-01', 3),
  ('SKU-T014', 'WH-01', 170),
  ('SKU-T014', 'WH-02', 30),
  ('SKU-T015', 'WH-01', 150),
  ('SKU-T016', 'WH-01', 8),
  ('SKU-T018', 'WH-01', 75);

INSERT INTO stock_movements (item_id, warehouse_id, qty_change, balance_after, type, reference, note, user_id, created_at) VALUES
  ('SKU-T001', 'WH-01', 40, 40, 'Initial Stock', '', 'Opening balance', NULL, '2026-11-02 08:00:00'),
  ('SKU-T001', 'WH-02', 5, 5, 'Initial Stock', '', 'Opening balance', NULL, '2026-11-02 08:00:00'),
  ('SKU-T002', 'WH-01', 24, 24, 'Initial Stock', '', 'Opening balance', NULL, '2026-11-02 08:00:00'),
  ('SKU-T002', 'WH-02', 6, 6, 'Initial Stock', '', 'Opening balance', NULL, '2026-11-02 08:00:00'),
  ('SKU-T003', 'WH-01', 4, 4, 'Initial Stock', '', 'Opening balance', NULL, '2026-11-02 08:00:00'),
  ('SKU-T004', 'WH-01', 60, 60, 'Initial Stock', '', 'Opening balance', NULL, '2026-11-02 08:00:00'),
  ('SKU-T004', 'WH-02', 20, 20, 'Initial Stock', '', 'Opening balance', NULL, '2026-11-02 08:00:00'),
  ('SKU-T005', 'WH-01', 55, 55, 'Initial Stock', '', 'Opening balance', NULL, '2026-11-02 08:00:00'),
  ('SKU-T006', 'WH-01', 7, 7, 'Initial Stock', '', 'Opening balance', NULL, '2026-11-02 08:00:00'),
  ('SKU-T007', 'WH-01', 28, 28, 'Initial Stock', '', 'Opening balance', NULL, '2026-11-02 08:00:00'),
  ('SKU-T009', 'WH-01', 120, 120, 'Initial Stock', '', 'Opening balance', NULL, '2026-11-02 08:00:00'),
  ('SKU-T010', 'WH-01', 80, 80, 'Initial Stock', '', 'Opening balance', NULL, '2026-11-02 08:00:00'),
  ('SKU-T010', 'WH-02', 15, 15, 'Initial Stock', '', 'Opening balance', NULL, '2026-11-02 08:00:00'),
  ('SKU-T011', 'WH-01', 38, 38, 'Initial Stock', '', 'Opening balance', NULL, '2026-11-02 08:00:00'),
  ('SKU-T012', 'WH-01', 22, 22, 'Initial Stock', '', 'Opening balance', NULL, '2026-11-02 08:00:00'),
  ('SKU-T013', 'WH-01', 4, 4, 'Initial Stock', '', 'Opening balance', NULL, '2026-11-02 08:00:00'),
  ('SKU-T014', 'WH-01', 170, 170, 'Initial Stock', '', 'Opening balance', NULL, '2026-11-02 08:00:00'),
  ('SKU-T014', 'WH-02', 30, 30, 'Initial Stock', '', 'Opening balance', NULL, '2026-11-02 08:00:00'),
  ('SKU-T015', 'WH-01', 150, 150, 'Initial Stock', '', 'Opening balance', NULL, '2026-11-02 08:00:00'),
  ('SKU-T016', 'WH-01', 8, 8, 'Initial Stock', '', 'Opening balance', NULL, '2026-11-02 08:00:00'),
  ('SKU-T018', 'WH-01', 75, 75, 'Initial Stock', '', 'Opening balance', NULL, '2026-11-02 08:00:00'),
  ('SKU-T009', 'WH-01', -10, 110, 'Transfer Out', 'TRF-00001', 'To PCS Computers Legazpi', NULL, '2026-11-18 09:30:00'),
  ('SKU-T009', 'WH-02', 10, 10, 'Transfer In', 'TRF-00001', 'From PCS Computers Naga', NULL, '2026-11-18 14:10:00'),
  ('SKU-T011', 'WH-01', 12, 50, 'Stock In', '', 'Received from supplier: PO-2026-0441', NULL, '2026-11-19 10:00:00'),
  ('SKU-T010', 'WH-01', 1, 81, 'Return Restock', 'RET-00001', 'Unopened, box intact', NULL, '2026-11-20 11:00:00'),
  ('SKU-T013', 'WH-01', -1, 3, 'Disposal', 'DSP-00001', 'Damaged', NULL, '2026-11-21 15:00:00');

INSERT INTO suppliers (id, name, country, category, rating, orders, spend, on_time, status) VALUES
  ('SUP-001', 'Prime Tech Distributors', 'Philippines', 'Laptops', 4.8, 42, 2840000.0, 97, 'Active'),
  ('SUP-002', 'Luzon Gadget Supply Co.', 'Philippines', 'Smartphones', 4.2, 31, 3210000.0, 88, 'Active'),
  ('SUP-003', 'Metro Components Trading', 'Philippines', 'Components', 4.6, 27, 1180000.0, 94, 'Active'),
  ('SUP-004', 'Southwind Networking Supply', 'Philippines', 'Networking', 3.9, 18, 367000.0, 81, 'Under Review'),
  ('SUP-005', 'Bayview Peripherals Inc.', 'Philippines', 'Peripherals', 4.5, 22, 642000.0, 92, 'Active');

INSERT INTO purchase_orders (id, supplier, items, value, order_date, delivery_date, status) VALUES
  ('PO-2026-0441', 'Southwind Networking Supply', 12, 89988.0, '2026-11-05', '2026-11-19', 'Delivered'),
  ('PO-2026-0442', 'Prime Tech Distributors', 10, 429950.0, '2026-11-12', '2026-11-26', 'Confirmed'),
  ('PO-2026-0443', 'Metro Components Trading', 6, 251970.0, '2026-11-16', '2026-11-30', 'In Review'),
  ('PO-2026-0444', 'Luzon Gadget Supply Co.', 25, 374975.0, '2026-11-20', '2026-12-04', 'Pending'),
  ('PO-2026-0445', 'Bayview Peripherals Inc.', 40, 199800.0, '2026-11-21', '2026-12-05', 'Pending');

INSERT INTO shop_orders (id, shop, items, units, address, requested_at, status) VALUES
  ('SO-1048', 'Partner Reseller - Naga', 'MacBook Air M3 x2, MX Master 3S x4', 6, 'Naga City, Camarines Sur', '2026-11-24 09:15:00', 'Pending'),
  ('SO-1047', 'Partner Reseller - Pili', 'Galaxy S24 Ultra x3, ASUS VivoBook 15 x2', 5, 'Pili, Camarines Sur', '2026-11-24 08:40:00', 'Picking'),
  ('SO-1046', 'Partner Reseller - Iriga', 'iPad Air M2 x5', 5, 'Iriga City, Camarines Sur', '2026-11-23 16:20:00', 'Ready for Delivery'),
  ('SO-1045', 'Partner Reseller - Naga', 'MX Keys S x8, MX Master 3S x8', 16, 'Naga City, Camarines Sur', '2026-11-22 13:05:00', 'Completed');

INSERT INTO shipments (id, origin, destination, status, eta, carrier, items, weight, created_at, review_note) VALUES
  ('SHP-1001', 'PCS Computers Naga, Yashano Mall', 'SM City Naga, CBD II, Naga City', 'For Approval', '2026-11-28', 'In-house', 12, '35 kg', '2026-11-24 10:00:00', ''),
  ('SHP-1002', 'PCS Computers Naga, Yashano Mall', 'Iriga City Public Market, Iriga City', 'Approved', '2026-11-27', 'In-house', 6, '18 kg', '2026-11-23 14:00:00', ''),
  ('SHP-1003', 'PCS Computers Naga, Yashano Mall', 'Pili Town Center, Pili', 'Delivered', '2026-11-21', 'In-house', 8, '22 kg', '2026-11-19 09:00:00', '');

INSERT INTO vehicles (id, plate, type, active) VALUES
  ('V-01', '', 'Delivery van', 1),
  ('V-02', '', 'Motorcycle', 1),
  ('V-03', '', 'Pickup truck', 1);

INSERT INTO transfers (id, from_warehouse, to_warehouse, status, notes, review_note, requested_at, approved_at, dispatched_at, received_at) VALUES
  (1, 'WH-01', 'WH-02', 'Received', 'Restock the Legazpi store', '', '2026-11-17 15:00:00', '2026-11-18 08:30:00', '2026-11-18 09:30:00', '2026-11-18 14:10:00'),
  (2, 'WH-01', 'WH-02', 'Requested', 'Smartphones for the Legazpi store', '', '2026-11-24 11:20:00', NULL, NULL, NULL);

INSERT INTO transfer_items (transfer_id, item_id, qty) VALUES
  (1, 'SKU-T009', 10),
  (2, 'SKU-T004', 10),
  (2, 'SKU-T014', 20);

INSERT INTO item_returns (id, item_id, warehouse_id, qty, source, source_ref, reason, item_condition, status, disposition, supplier, notes, decision_note, created_at, decided_at) VALUES
  (1, 'SKU-T010', 'WH-01', 1, 'Shop', 'SO-1045', 'Excess or unsold stock', 'Good', 'Restocked', 'Restock', '', 'Shop ordered one extra mouse', 'Unopened, box intact', '2026-11-20 09:30:00', '2026-11-20 11:00:00'),
  (2, 'SKU-T002', 'WH-01', 1, 'Customer', 'Walk-in customer, OR No. 10234', 'Defective', 'Defective', 'Pending Inspection', NULL, '', 'Laptop does not power on after two days', '', '2026-11-24 13:45:00', NULL);

INSERT INTO disposals (id, item_id, warehouse_id, qty, reason, method, return_id, unit_cost, status, notes, review_note, reference, requested_at, approved_at, disposed_at) VALUES
  (1, 'SKU-T013', 'WH-01', 1, 'Damaged', 'Accredited e-waste recycler', NULL, 19990.0, 'Approved', 'Cracked panel found during stock count', '', '', '2026-11-21 10:00:00', '2026-11-21 15:00:00', NULL),
  (2, 'SKU-T006', 'WH-01', 1, 'Defective', 'Accredited e-waste recycler', NULL, 14999.0, 'Pending Approval', 'Dead on arrival, no warranty from supplier', '', '', '2026-11-24 16:00:00', NULL, NULL);

INSERT INTO tracking_logs (ref_type, ref_id, status, location, remarks, logged_at) VALUES
  ('shipment', 'SHP-1001', 'Created', 'PCS Computers Naga, Yashano Mall', 'Shipment to SM City Naga sent for warehouse approval', '2026-11-24 10:00:00'),
  ('shipment', 'SHP-1002', 'Created', 'PCS Computers Naga, Yashano Mall', 'Shipment to Iriga City Public Market sent for warehouse approval', '2026-11-23 14:00:00'),
  ('shipment', 'SHP-1002', 'Approved', 'PCS Computers Naga, Yashano Mall', 'Approved by the warehouse; waiting for a driver', '2026-11-23 16:30:00'),
  ('shipment', 'SHP-1003', 'Created', 'PCS Computers Naga, Yashano Mall', 'Shipment to Pili Town Center sent for warehouse approval', '2026-11-19 09:00:00'),
  ('shipment', 'SHP-1003', 'Approved', 'PCS Computers Naga, Yashano Mall', 'Approved by the warehouse; waiting for a driver', '2026-11-19 10:15:00'),
  ('shipment', 'SHP-1003', 'Out for Delivery', 'PCS Computers Naga, Yashano Mall', 'Accepted by the driver (V-01)', '2026-11-21 08:05:00'),
  ('shipment', 'SHP-1003', 'Arrived at checkpoint', 'Maharlika Highway, Pili', 'Light traffic', '2026-11-21 08:40:00'),
  ('shipment', 'SHP-1003', 'Delivered', 'Pili Town Center, Pili', 'Received by the store supervisor', '2026-11-21 09:10:00'),
  ('shop_order', 'SO-1047', 'Picking', 'PCS Computers Naga, Yashano Mall', 'Items are being picked', '2026-11-24 10:05:00'),
  ('shop_order', 'SO-1046', 'Picking', 'PCS Computers Naga, Yashano Mall', 'Items are being picked', '2026-11-23 16:40:00'),
  ('shop_order', 'SO-1046', 'Ready for Delivery', 'PCS Computers Naga, Yashano Mall', 'Packed and waiting for a driver', '2026-11-23 17:30:00'),
  ('transfer', 'TRF-00001', 'Requested', 'PCS Computers Naga', '1 product(s), 10 unit(s) to PCS Computers Legazpi', '2026-11-17 15:00:00'),
  ('transfer', 'TRF-00001', 'Approved', 'PCS Computers Naga', 'Approved for dispatch', '2026-11-18 08:30:00'),
  ('transfer', 'TRF-00001', 'Dispatched', 'PCS Computers Naga', 'Left the warehouse for PCS Computers Legazpi. Delivery van V-01', '2026-11-18 09:30:00'),
  ('transfer', 'TRF-00001', 'In Transit', 'Ligao City, Albay', 'On schedule', '2026-11-18 12:00:00'),
  ('transfer', 'TRF-00001', 'Received', 'PCS Computers Legazpi', 'All items received', '2026-11-18 14:10:00'),
  ('transfer', 'TRF-00002', 'Requested', 'PCS Computers Naga', '2 product(s), 30 unit(s) to PCS Computers Legazpi', '2026-11-24 11:20:00');

INSERT INTO warehouse_zones (zone, capacity, occupied, type, temp) VALUES
  ('Zone A', 1200, 640, 'Laptops and tablets', 'Climate'),
  ('Zone B', 1500, 980, 'Smartphones', 'Climate'),
  ('Zone C', 900, 310, 'Peripherals', 'Ambient'),
  ('Zone D', 1000, 720, 'Networking and storage', 'Ambient'),
  ('Zone E', 600, 590, 'Components', 'Controlled'),
  ('Zone F', 2000, 850, 'Bulk and returns area', 'Ambient');

