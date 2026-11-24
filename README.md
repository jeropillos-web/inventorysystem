# INVTrack — Inventory & Delivery Management System

INVTrack is a web-based inventory, warehouse, procurement and delivery-tracking system built for **PCS Computers, Naga City**. It replaces manual stock monitoring with a role-based dashboard, barcode scanning, and a self-built GPS tracker for delivery vehicles.

> IT Capstone Project — Jero Pillos, BSIT 4th Year

**Live demo:** _<add your deployed link here>_
**Design Handoff Document:** _<add link or see `docs/`>_
**AI prompts used:** see [`PROMPTS.md`](PROMPTS.md)

---

## Features

| Module | What it does | Roles |
|---|---|---|
| Inventory Control | Add, edit, delete and search SKUs; automatic In Stock / Low Stock / Out of Stock status based on reorder level | Admin, Staff, Warehouse (view) |
| Product Categories | Manage categories with color labels | Admin, Staff |
| Warehouse & Logistics | Zone capacity/utilization and shipment tracker | Admin |
| Procurement & Suppliers | Purchase orders and supplier directory with ratings and on-time % | Admin, Staff |
| Reports & Analytics | KPIs, monthly flow (₱), shipment status, supplier performance | Admin, Staff |
| GPS Delivery Tracker | Live fleet map (Leaflet + OpenStreetMap) showing each vehicle as Moving / Idle / Offline, with route trail | Admin |
| Barcode Scanner | Camera-based scanning (browser `BarcodeDetector`) for stock check-in / check-out | Admin |
| Shop Orders | Process shop requests: Pending → Picking → Ready for Delivery → Completed | Warehouse |
| Driver App (`driver/`) | Mobile web app (installable) for delivery staff: view available deliveries, accept one, start the trip, mark it delivered with recipient name, or cancel with a reason; shares live GPS while delivering; shows delivery history | Warehouse / Delivery Staff |
| Location sharing (`driver.php`) | Simple page that only shares a vehicle's live GPS location | Admin, Warehouse |

### User roles
- **Administrator** — full access to all modules
- **Staff** — inventory, categories, procurement, reports
- **Warehouse / Delivery Staff** — available products, shop orders, and the driver location page

---

## Tech stack

- **Backend:** PHP 8 (PDO, prepared statements, sessions, `password_hash`)
- **Database:** MySQL / MariaDB (`sql/invtrack.sql`)
- **Frontend:** Tailwind CSS (CDN), vanilla JavaScript, Inter + DM Mono fonts
- **Maps:** Leaflet with OpenStreetMap tiles (no Google Maps API key needed)
- **Local server:** XAMPP (Apache + MySQL), PHP 8.0.30

---

## Project structure

```
invtrack/
├── index.php          # Sign-in page
├── register.php       # Account registration (choose role)
├── dashboard.php      # Main layout: sidebar + role-based module loader
├── driver.php         # Simple page for sharing a vehicle's GPS location
├── upgrade.php        # Updates an older local database to the current tables (localhost only)
├── driver/            # Driver web app for deliveries
│   ├── index.php      # Driver sign-in
│   ├── app.php        # Current delivery, available deliveries, history + mini map
│   ├── api.php        # accept / start / deliver / cancel actions
│   ├── style.css, manifest.webmanifest
│   └── _boot.php, logout.php
├── logout.php
├── api/               # JSON endpoints used by the modules
│   ├── _base.php      # Shared helpers (json_ok / json_err / input)
│   ├── inventory.php, categories.php, suppliers.php,
│   ├── purchase_orders.php, shipments.php, warehouse_zones.php
│   └── gps.php        # Receives driver pings, returns fleet status & trails
├── config/
│   ├── db.php         # Database connection (PDO)
│   ├── auth.php       # Login, registration, roles & permissions
│   ├── fallback.php   # Sample data shown when the database is offline
│   └── maps.php       # Legacy Google Maps key loader (no longer used)
├── modules/           # One PHP file per dashboard module
└── sql/
    └── invtrack.sql   # Complete database: 12 tables + sample data
```

---

## Running locally (XAMPP)

1. Install [XAMPP](https://www.apachefriends.org/) and start **Apache** and **MySQL**.
2. Copy the `invtrack` folder into `C:\xampp\htdocs\`.
3. Open **phpMyAdmin** (`http://localhost/phpmyadmin`) → **Import** → choose `sql/invtrack.sql` → **Go**. This creates the `invtrack` database with sample data.
4. Visit `http://localhost/invtrack/`.
5. Click **Create account**, register one account for each role you want to test (Administrator, Staff, Warehouse / Delivery Staff), then sign in.

> No default accounts are included on purpose — create your own on the registration page.

Database settings are in `config/db.php` (default: `localhost`, user `root`, no password).

If you already had an older `invtrack` database, open `http://localhost/invtrack/upgrade.php` once to add the new tables instead of re-importing.

### Testing the driver app and GPS tracker
Browsers only allow location access on **HTTPS** (or `localhost`). On a phone, open the deployed link + `/driver/`, sign in with a Warehouse / Delivery Staff account, accept a delivery from **Available deliveries**, start it, and keep the page open. In another browser, sign in as Admin and open **GPS Tracker** to watch the vehicle move. Finish with **Mark as delivered**.

The driver app's mini map uses OpenStreetMap by default. A Mapbox map is used only if you add a token in `config/mapbox.php` (ignored by Git).

---

## Deployment

To deploy on a PHP + MySQL host (e.g. InfinityFree):

1. Create a MySQL database in the hosting control panel.
2. Open `sql/invtrack.sql`, delete the first two lines (`CREATE DATABASE ...` and `USE invtrack;`), then import it in the host's phpMyAdmin.
3. Create `config/db.local.php` on the server with the host's credentials:
   ```php
   <?php
   define('DB_HOST', 'your-mysql-host');
   define('DB_NAME', 'your_database_name');
   define('DB_USER', 'your_database_user');
   define('DB_PASS', 'your_database_password');
   ```
   This file is ignored by Git so passwords never reach the repository.
4. Upload all project files to `htdocs/` and enable the free SSL certificate so the driver GPS page works.

---

## Documentation

- [`PROMPTS.md`](PROMPTS.md) — AI prompts used during development
- `docs/` — Design Handoff Document (PDF)
