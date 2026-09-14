# Yala Sub-County Hospital &mdash; Laboratory Information Management System (LIMS)

[![PHP Version](https://img.shields.io/badge/PHP-8.2%2B-blue.svg)](https://www.php.net/)
[![MySQL](https://img.shields.io/badge/Database-MySQL%20%2F%20MariaDB-orange.svg)](https://www.mysql.com/)
[![License](https://img.shields.io/badge/License-Academic%20%2F%20Healthcare-green.svg)]()
[![Facility Level](https://img.shields.io/badge/Facility-Level%204%20Sub--County%20Hospital-red.svg)]()

A modern, web-based **Laboratory Information Management System (LIMS)** designed and engineered for **Yala Sub-County Hospital (Level 4, Gem Sub-County, Siaya County, Kenya)**.

> **Academic Fulfillment:** Final Year B.Tech in Information Technology Project  
> **Author / Developer:** **Owuor Collins**  
> **Registration Number:** `SCCI/01227/2022`  
> **Institution:** The Technical University of Kenya (TUK) &mdash; Faculty of Applied Sciences & Technology  
> **Academic Year:** 2025/2026  

---

## 🏥 Hospital & Operational Context

Yala Sub-County Hospital is a Ministry of Health (MOH) Level 4 public healthcare facility serving the people of Gem Sub-County, along the busy Kisumu–Busia highway corridor. The facility handles high-volume outpatient consultation, inpatient wards, maternity/child healthcare, emergency trauma, and specialized HIV/TB clinics.

This system digitizes, automates, and connects all hospital and laboratory operations under Kenya's Ministry of Health (MOH) guidelines, ISO 15189:2022 medical laboratory quality standards, and Public Finance Management (PFM) regulations.

---

## 🌟 Key System Modules & Features

### 1. 🗂️ Outpatient Registration & MOH 204 Routing
- **Strict Role-Based Access Control (RBAC):** Receptionists handle patient registration, triage vitals, and billing routing. Clinicians handle medical orders. Technologists handle analytical testing.
- **Automated OPD Sequential Identifiers:** Automatically generates unique hospital numbers (`OPD-2026-XXX`).
- **MOH 204 Consultation Routing Slip:** Generates printable A5 patient routing cards featuring Code128 scannable barcodes, vital signs, clinical allergy warnings (e.g. Penicillin anaphylaxis, Sickle Cell Disease), and assigned clinical consultation room.

### 2. 🧪 Clinical Workbench & Diagnostic Panels
- **Digital Order Requisition:** Doctors prescribe diagnostic investigations with clinical indications and urgency flags (*Routine*, *Urgent*, *STAT / Emergency*).
- **Automated Panic Value Engine:** Immediate clinical red-alert trigger when high-risk laboratory thresholds are breached (e.g., Hemoglobin $< 6.0\text{ g/dL}$, Malaria microscopy $\ge 3+$, Critical blood glucose).
- **Code128 Barcode Specimen Labeling:** Instant thermal tube label generation matching international phlebotomy standards.
- **Comprehensive Diagnostic Panels:**
  - Parasitology & Malaria Microscopy (BS for MPS)
  - Full Blood Count (FBC) & Complete Blood Picture
  - Urinalysis (Macro, Biochemical, Microscopic)
  - Blood Grouping & Rhesus Typing
  - Liver Function Tests (LFT) & Renal Function Panels
  - Widal & Salmonella Agglutination
  - Lipid Profiles & Random/Fasting Blood Glucose

### 3. 🩸 Inpatient Blood Bank & Transfusion Safety
- **ABO/Rh Inventory Grid:** Live inventory monitoring for all 8 blood groups ($O+, O-, A+, B+, AB+, AB-, etc.$) with universal donor ($O-$) deficit alerts.
- **Component Management:** Tracking Whole Blood, Packed Red Blood Cells (PRBC), Fresh Frozen Plasma (FFP), and Platelets.
- **TTI Screening:** Enforces Transfusion-Transmissible Infection screening (HIV, Hep B, Hep C, Syphilis) prior to release.
- **Inpatient Recipient Crossmatch Engine:** Technologists perform crossmatching for surgical, maternal (Post-Partum Hemorrhage), and emergency pediatric admissions with strict *Compatible* vs. *Incompatible* safety locks.

### 4. 🔬 Specimen Rejection & Phlebotomy QA (ISO 15189:2022)
- **Pre-Analytical Rejection Management:** 1-click specimen rejection directly from the testing workbench.
- **CAP/MOH Benchmark KPI:** Continuous dynamic tracking of the facility's Specimen Rejection Rate, benchmarked against the $< 2.0\%$ quality threshold.
- **Closed-Loop Redraw Protocol:** Automated dispatch of specimen redraw requests to ordering doctors and phlebotomy stations.

### 5. 💳 Kenya Government eCitizen Digital Cashless Gateway (Paybill 222222)
- **Compliance with Kenya Gazette Notice No. 16008 & PFM Act 2012:** Physical cash handling at hospital counters is strictly eliminated to prevent revenue leakage.
- **eCitizen Paybill 222222 Integration:** Direct settlement via Government Paybill `222222` with Account Number `YALA-[OPD_NO]`.
- **Integrated Payment Modes:**
  - eCitizen M-Pesa STK Push Simulation
  - eCitizen Direct PRN / Bank Slip Clearance
  - Social Health Authority (SHA) & Linda Mama Free Maternity Scheme
  - Statutory Fee Exemption (PFM Act Section 32 Indigent Waiver)
- **Official A5 eCitizen Revenue Receipts:** Automated PDF receipt generation bearing dual emblems (Hospital Seal & Republic of Kenya eCitizen Insignia).

### 6. 🔔 Real-Time Clinical Notification System
- **Severity-Tiered Alerts:** Four clinical levels &mdash; `Critical` (Crimson), `Warning` (Amber), `Success` (Emerald), `Info` (Blue).
- **Background AJAX Polling (12s interval):** Live unread counters without page reload.
- **In-Browser Panic Toasts:** High-contrast floating notifications and audio alerts for urgent clinical emergencies.
- **Notification Console:** Dedicated portal with category filtering tabs (*All*, *Unread*, *Critical*, *eCitizen & Claims*, *Orders & Results*, *QA & Blood Bank*).

### 7. 📊 Inventory & Equipment Calibration Logs
- **Reagent Inventory Tracker:** Automated minimum stock buffer monitoring and reorder alerts.
- **ISO 15189 Equipment QC Logs:** Morning/Evening temperature monitoring for Blood Bank fridges ($2-6^\circ\text{C}$), reagent fridges, deep freezers, and automated hematology analyzers.

### 8. 📈 MOH 706 Analytics & Automated Backup
- **Ministry of Health MOH 706 Laboratory Register:** Real-time statistics aggregation with 1-click CSV export.
- **Executive Management Dashboards:** Clinical turnaround time (TAT), disease surveillance, and financial reconciliation.
- **Automated Database Backups:** Integrated SQL dump engine with scheduled cron automation.

---

## 💻 Technology Stack

- **Backend:** PHP 8.2+
- **Database:** MySQL / MariaDB (InnoDB engine, relational schema with foreign key constraints)
- **Frontend:** HTML5, CSS3, JavaScript (ES6), Bootstrap 5, FontAwesome Icons
- **PDF Generation:** FPDF Engine (custom headers, hospital seals, barcodes)
- **Barcode Engine:** Code128 vector rendering
- **Real-Time Layer:** Asynchronous JavaScript & XML (AJAX) Polling
- **Web Server:** Apache (XAMPP / Linux LAMP)

---

## 🚀 Installation & Local Deployment

### Prerequisites
- [XAMPP](https://www.apachefriends.org/) (PHP 8.0 or higher, Apache, MySQL)
- Git

### Setup Steps

1. **Clone the Repository:**
   ```bash
   git clone https://github.com/noliptical-web/Yala-LIMS.git C:/xampp/htdocs/yala_lims
   ```

2. **Start Apache & MySQL:**
   - Open XAMPP Control Panel and start **Apache** and **MySQL**.

3. **Import the Database:**
   - Open phpMyAdmin (`http://localhost/phpmyadmin`).
   - Create a new database named:
     ```sql
     CREATE DATABASE yala_lims_db;
     ```
   - Import the database structure and dataset from `backups/` or execute the latest schema script.

4. **Verify Database Configuration:**
   - Ensure [`includes/db.php`](includes/db.php) matches your local environment:
     ```php
     $host = "localhost";
     $user = "root";
     $pass = "";
     $db   = "yala_lims_db";
     ```

5. **Launch the Application:**
   - Open your web browser and navigate to:
     ```
     http://localhost/yala_lims/
     ```

---

## 👥 Default User Roles & Credentials

| Role | Username | Department / Responsibilities |
| :--- | :--- | :--- |
| **System Administrator** | `admin` | System settings, user management, audit logs, backups |
| **Medical Doctor** | `doctor` | Consultations, test requisitions, panic review, clinical diagnosis |
| **Laboratory Technologist** | `labtech` | Phlebotomy, bench testing, QC calibration, blood bank |
| **Outpatient Receptionist** | `receptionist`| Patient registration, OPD routing, eCitizen cashiering |

---

## 📜 Regulatory & Standards Compliance

- **Kenya Ministry of Health (MOH 204 & MOH 706):** Outpatient registration and laboratory statistical reporting guidelines.
- **Kenya Gazette Notice No. 16008 of 2022 & Executive Order No. 2 of 2023:** Single Government eCitizen digital payment gateway (Paybill 222222).
- **Public Finance Management Act (PFM Act, 2012):** Direct remittance into County Revenue Fund (CRF), cashless hospital compliance, statutory fee exemptions.
- **ISO 15189:2022:** Medical laboratories &mdash; Requirements for quality and competence (pre-analytical specimen acceptability, analyzer calibration logs, panic result turnaround).

---

## 👨‍💻 Project Developer

**Owuor Collins**  
*Bachelor of Technology in Information Technology*  
Technical University of Kenya (TUK)  
GitHub: [@noliptical-web](https://github.com/noliptical-web)
