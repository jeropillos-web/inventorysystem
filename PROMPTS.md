# AI Prompts Documentation — INVTrack

This file records the AI prompts used while building INVTrack, what each one was for, and what was done with the output. AI tool used: **Claude (Anthropic)**.

All AI-generated code was reviewed, tested on XAMPP, and edited by the developer before being kept in the project.

---

## How to read this log

Each entry has:
- **Goal** — what I needed at that stage
- **Prompt** — the exact prompt I typed
- **Result** — what the AI produced
- **What I changed / kept** — my own review, fixes, and decisions

---

## 1. Project setup & database

### 1.1 Single SQL file for the whole repository
- **Goal:** Combine all table definitions and sample data into one importable file.
- **Prompt:**
  > _(paste your exact prompt here)_
- **Result:** `sql/invtrack.sql` containing users, categories, inventory, suppliers, purchase_orders, shop_orders, shipments, warehouse_zones, vehicles and vehicle_positions.
- **What I changed / kept:** _(e.g. removed demo accounts, adjusted sample products to PCS Computers items)_

---

## 2. Authentication & roles

### 2.1 Login, registration and role-based access
- **Goal:** Separate access for Administrator, Staff, and Warehouse / Delivery Staff.
- **Prompt:**
  > _(paste your exact prompt here)_
- **Result:** `config/auth.php` (login, register, `role_can()` permissions), `index.php`, `register.php`, role-based sidebar in `dashboard.php`.
- **What I changed / kept:** _(fill in)_

---

## 3. Dashboard modules

### 3.1 Inventory Control
- **Prompt:**
  > _(paste your exact prompt here)_
- **Result:** `modules/inventory.php`, `api/inventory.php`
- **What I changed / kept:** _(fill in)_

### 3.2 Product Categories
- **Prompt:**
  > _(paste)_
- **Result:** `modules/categories.php`, `api/categories.php`

### 3.3 Warehouse & Logistics
- **Prompt:**
  > _(paste)_
- **Result:** `modules/warehouse.php`, `api/shipments.php`, `api/warehouse_zones.php`

### 3.4 Procurement & Suppliers
- **Prompt:**
  > _(paste)_
- **Result:** `modules/procurement.php`, `api/purchase_orders.php`, `api/suppliers.php`

### 3.5 Reports & Analytics
- **Prompt:**
  > _(paste)_
- **Result:** `modules/reports.php`

### 3.6 Barcode Scanner
- **Prompt:**
  > _(paste)_
- **Result:** `modules/scanner.php` (camera scanning with the browser BarcodeDetector API, check-in / check-out)

### 3.7 Shop Orders (warehouse role)
- **Prompt:**
  > _(paste)_
- **Result:** `modules/orders.php` (Pending → Picking → Ready for Delivery → Completed)

---

## 4. GPS Delivery Tracker

### 4.1 Replacing Google Maps with a self-built GPS tracker
- **Goal:** Track delivery vehicles without needing a paid Google Maps API key.
- **Prompt:**
  > _(paste your exact prompt here)_
- **Result:** `modules/gps.php` (Leaflet + OpenStreetMap live fleet map), `api/gps.php` (stores pings, computes Moving / Idle / Offline, returns route trails), `vehicles` and `vehicle_positions` tables.
- **What I changed / kept:** _(fill in)_

### 4.2 Location sharing page
- **Goal:** Let drivers share their phone's location while delivering.
- **Prompt:**
  > _(paste your exact prompt here)_
- **Result:** `driver.php` (mobile page, start/stop sharing, sends location every 5 seconds, keeps screen awake)
- **What I changed / kept:** _(fill in)_

### 4.3 Driver app for accepting deliveries
- **Goal:** A separate web app where delivery staff accept and complete deliveries.
- **Prompt:**
  > _(paste your exact prompt here)_
- **Result:** `driver/` folder (sign-in, available deliveries, current delivery with mini map, history; accept / start / mark delivered / cancel with reason), `deliveries` and `delivery_cancellations` tables, `upgrade.php` for older databases.
- **What I changed / kept:** _(fill in)_

---

## 5. Submission preparation

### 5.1 Semi-final submission documents
- **Goal:** Prepare the Git repository, documentation and design handoff for the semi-final submission.
- **Prompt:**
  > can you help me to execute this SEMI-FINAL SUBMISSION OF APPLICATION?. if you need something just say it to me so that i can provide what you need.
  >
  > _(attached: screenshot of the assignment — Git Repository Link, Published / Deployed Link, Design Handoff Documents)_
- **Result:** `README.md`, `PROMPTS.md` (this file), `.gitignore`, optional `config/db.local.php` support for deployment, and the Design Handoff Document.
- **What I changed / kept:** _(fill in)_

---

## Reflection

_(2–4 sentences: how AI helped, what you had to fix yourself, and what you learned.)_
