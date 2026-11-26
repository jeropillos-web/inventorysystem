# INVTrack: Inventory Management and Logistics System for PCS Computers

Web-based inventory and logistics system built with PHP 8, MySQL/MariaDB and Tailwind CSS.

For PCS Computers, Naga City, with two warehouses: the main store at Yashano Mall, Naga City, and the Legazpi store.

- **Repository:** https://github.com/jeropillos-web/inventorysystem
- **Live demo:** http://invtrack-pcs.gt.tc (free hosting on InfinityFree; login details are given to the instructor in Google Classroom)
- **Design Handoff Document:** submitted separately as a PDF
- **AI prompts used:** [`PROMPTS.md`](PROMPTS.md)

## Modules
| Module | Who uses it |
| --- | --- |
| Inventory Control (stock per warehouse, stock card, stock in / adjust) | Administrator, Staff, Warehouse / Delivery Staff (available products) |
| Product Categories | Administrator, Staff |
| Warehouse & Logistics (warehouses, zones, shipments) | Administrator |
| Stock Transfers (warehouse to warehouse) | All roles |
| Returns | All roles (Administrator decides) |
| Disposal (stock write-off) | All roles (Administrator approves) |
| Tracking Log (text tracking of shipments, shop orders and transfers) | All roles |
| Shop Orders | Administrator, Warehouse / Delivery Staff |
| Procurement & Suppliers | Administrator, Staff |
| Reports & Analytics | Administrator, Staff |
| Delivery App (`/driver`, phone-friendly) | Warehouse / Delivery Staff |

## Run it on XAMPP
1. Copy this folder to `C:\xampp\htdocs\invtrack`.
2. Start **Apache** and **MySQL** in the XAMPP Control Panel.
3. Open http://localhost/phpmyadmin, click **Import**, choose `sql/invtrack.sql`, click **Go**.
   This creates the `invtrack` database with sample data. **It resets existing INVTrack data.**
   To upgrade an older INVTrack database and keep its data, open http://localhost/invtrack/upgrade.php instead.
4. Open http://localhost/invtrack/register.php and create the first account as **Administrator**.
   After that, sign-up only offers Staff and Warehouse / Delivery Staff.

## Put it online
1. Create a MySQL database in your hosting panel.
2. In phpMyAdmin, select that database, delete the `CREATE DATABASE` and `USE` lines at the top of `sql/invtrack.sql`, then import it.
3. Create `config/db.local.php`:
   ```php
   <?php return ['host' => 'your-db-host', 'name' => 'your_db_name', 'user' => 'your_db_user', 'pass' => 'your_password'];
   ```
4. Upload all files. `config/db.local.php` is in `.gitignore`, so the password is not pushed to GitHub.

## Roles
- **Administrator**: everything; deletes records; approves transfers and disposals; decides returns; manages warehouses.
- **Staff**: inventory, categories, procurement, reports; records returns; requests transfers and disposals.
- **Warehouse / Delivery Staff**: available products, shop orders, shipment approval, dispatch and receive transfers, record returns, request disposals, tracking updates, delivery app.

## Project structure
```
invtrack/
├── index.php, register.php, logout.php   Sign-in, sign-up, sign-out
├── dashboard.php                          App shell: sidebar per role, task bell, module loader
├── modules/      One page per module (inventory, transfers, returns, disposals, tracking, ...)
├── api/          JSON endpoints used by the modules
├── driver/       Delivery App for phones (accept, post tracking updates, mark delivered)
├── config/       Database, roles and permissions, stock helpers, shared UI helpers
├── assets/       PCS Computers logo and icon, theme colors, shared JavaScript
├── sql/          invtrack.sql: the complete database (19 tables + sample data)
└── upgrade.php   Upgrades an older INVTrack database without losing data
```

## Changes after the capstone defense
As requested by the panel: added Returns, Disposal and warehouse-to-warehouse Stock Transfers; replaced the real-time GPS map with a text Tracking Log; removed the barcode scanner.
