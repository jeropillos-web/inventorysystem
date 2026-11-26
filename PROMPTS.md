# AI Prompts Documentation: INVTrack

This file lists the AI prompts used to build and document INVTrack, what each one produced, and what happened to the result.

**AI tools used:** Claude (Anthropic). _(Add any other tools you used.)_

Prompts are copied word for word from the chats, typos included. "Excerpt" means only the part of a long message that asked for the work is shown. Every AI output was reviewed and run on XAMPP (PHP 8.0.30) before it was kept. Design decisions, such as the user roles, the separate Delivery App and the changes requested by the defense panel, were made by the developer and the group.

---

## 1. Development prompts (system code)

| # | Date | Prompt | What it produced | Status in the current system |
|---|---|---|---|---|
| 1 | | _(paste the prompts used for the first version: inventory, categories, warehouse, procurement, shop orders, reports and the database)_ | First version of the modules and `sql/invtrack.sql` | Kept and extended |
| 2 | Nov 2026 | "check this and remove gmaps apo and build a gps tracker from scratch" | Removed the Google Maps tracker and built a live GPS tracker (`driver.php`, `api/gps.php`, `modules/gps.php`) with Leaflet and OpenStreetMap | Removed after the defense; replaced by the Tracking Log (prompt 7) |
| 3 | Nov 2026 | "create a separete web app for drivers to accept deliveries" | The Delivery App in `driver/`: available deliveries, accept with a vehicle, mark as delivered with the receiver's name, cancel with a reason, history | Kept; drivers now post tracking updates instead of sharing GPS |
| 4 | Nov 2026 | "check this out. /code-review:code-review" | Code review that found three security problems: anyone could register as Administrator, the APIs did not check permissions on the server, and one form had no CSRF protection | The first two are fixed: only the first account can be an Administrator, and every API checks the user's permission |
| 5 | Nov 2026 | "how i can run this on my LAPTOP?" | Step-by-step XAMPP setup and a fix for PHP 8.0 compatibility | Used for local testing |
| 6 | Nov 2026 | "check this out. is our website aligned to our systems?. check the papers carefully. our prof/panelist told us for revision of the papers. /deep-research" | Comparison of the capstone manuscript with the code, with a list of mismatches to revise | Used to revise the paper |
| 7 | Nov 2026 | _(excerpt)_ "our panelist has a suggestion for us. this is what i take noted. i will send the papers we presented and also the system. we need a return in the system like return items. dispose (i forgot what kind of dispose, but inside the system) we will removing the real time GPS and turn it into text log for tracking the parcel\items. warehouse to warehouse. (i forgot this one also) let's focus on this first. there's more but let's finish it first." | Returns, Disposal, Stock Transfers and Tracking Log modules, the `warehouses`, `stock_levels` and `stock_movements` tables, and removal of the live GPS map | Kept |
| 8 | Nov 2026 | "we will also removing the Scanner in the system." | Barcode Scanner module and its permission removed | Kept (scanner removed) |
| 9 | Nov 2026 | "yes, this is correct. For dispose: removing items that can no longer be sold (damaged, defective, obsolete or lost) from the inventory, with an approval and a record. For warehouse to warehouse: moving stock between your two locations." | Confirmed the meaning of Disposal and Stock Transfers used in the system | Kept |
| 10 | Nov 2026 | "can we use this in our system?. just change only the color themes. let's add also the logo" _(with an image of the PCS Computers design)_ | PCS Computers green theme (`assets/theme.js`), logo and app icon on every page | Kept |
| 11 | Nov 2026 | "did we met all this?" _(followed by the list of the panel's requests)_ | Check that returns, disposal, the tracking log and warehouse-to-warehouse transfers are all in the system and the paper | All four confirmed |

---

## 2. Documentation and submission prompts

| # | Date | Prompt | What it produced |
|---|---|---|---|
| 12 | Nov 22, 2026 | "can you help me to execute this SEMI-FINAL SUBMISSION OF APPLICATION?. if you need something just say it to me so that i can provide what you need." _(with a screenshot of the assignment)_ | First README, this prompts file and the first version of the Design Handoff Document |
| 13 | Nov 24, 2026 | "i already ask my prof about the semi-final application. i need to submit the INVTrack system code and Git Repository Link (containing the project files and documentation of the prompts used), Published / Deployed Link (if applicable), Design Handoff Documents" | Documents updated for the driver app |
| 14 | Nov 24, 2026 | "this is the code of our system." _(with the project zip)_ | Check of the zip before upload; an API key file was kept out of GitHub |
| 15 | Nov 24, 2026 | "i don't know what to do" | Steps to upload the code to GitHub and make the repository public |
| 16 | Nov 24, 2026 | "/deep-research create the Design Handoff Documents" | Research on handoff contents, HCI course expectations and WCAG 2.2; personas, design rationale, wireframe, interaction states, heuristics and accessibility sections added |
| 17 | Nov 24, 2026 | "/humanizer:humanizer" | Plainer wording in the Design Handoff Document, facts unchanged |
| 18 | Nov 24, 2026 | "how about this one? (containing the project files and documentation of the prompts used)" | This file reorganized into tables |
| 19 | Nov 26, 2026 | "this is the updated system" _(with the updated system zip)_ | README, this file and the Design Handoff Document updated for the system after the defense |

**How the results were checked:** the README and the Design Handoff Document were checked against the code in this repository, and the repository was opened in a private browser window to confirm that it is public.

---

## Reflection

_(2 to 4 sentences in your own words: how AI helped, what you had to fix yourself, and what you learned.)_
