# LABSEKYU System Overview & Architecture

This document provides a comprehensive breakdown of the core modules we have developed for the LABSEKYU system, detailing the purpose of each feature and exactly which PHP files handle the underlying logic.

## 1. Authentication & Role Routing
**Purpose:** Serves as the secure entry point to the system. It handles user authentication, dynamically presents the appropriate login interface based on the selected role, and securely routes users to their respective dashboards.
**Key Files:**
*   [`auth/login.php`](file:///c:/xampp/htdocs/labsekyu2/auth/login.php)
    *   **Logic:** Contains the unified 3-tier login interface (Username, Password, Sign In). Handles the dynamic JavaScript animations (floating labels, role selection state, password toggle) and the backend PHP authentication using `password_verify()`. It prevents users from accessing portals they don't have privileges for.

## 2. Bi-Directional Alert & Ticketing System (Online Consultation)
**Purpose:** A real-time communication bridge between Teachers and Tech Support. This acts as the "online consultation" or ticketing system to ensure laboratory issues are reported and resolved efficiently.

### A. System Alerts (Tech Support → Teachers)
*   **Purpose:** Allows Tech Support to dispatch critical announcements (e.g., Network Outage, Maintenance) to specific rooms or globally to all rooms.
*   **Tech Support Side:** [`dashboards/tech_support/alerts.php`](file:///c:/xampp/htdocs/labsekyu2/dashboards/tech_support/alerts.php) (Contains the form to dispatch alerts and the "Delete All" management button).
*   **Teacher Side:** [`dashboards/teacher/teacher.php`](file:///c:/xampp/htdocs/labsekyu2/dashboards/teacher/teacher.php) and [`dashboards/teacher/schedule.php`](file:///c:/xampp/htdocs/labsekyu2/dashboards/teacher/schedule.php).
    *   **Logic:** The system queries the `system_alerts` table. If an alert is targeted at a room the teacher is currently assigned to (and was created after their last visit to the alerts page), it triggers a red glowing animation and badge on their sidebar. Visiting [`dashboards/teacher/alerts.php`](file:///c:/xampp/htdocs/labsekyu2/dashboards/teacher/alerts.php) marks the alerts as read and stops the glow.

### B. Teacher Reports (Teachers → Tech Support)
*   **Purpose:** Allows Teachers to report specific issues (e.g., broken PC, faulty aircon) in their assigned rooms directly to Tech Support.
*   **Teacher Side:** [`dashboards/teacher/alerts.php`](file:///c:/xampp/htdocs/labsekyu2/dashboards/teacher/alerts.php) (Contains the ticketing form and a "My Reports" feed to track the status of their submitted issues).
*   **Tech Support Side:** [`dashboards/tech_support/alerts.php`](file:///c:/xampp/htdocs/labsekyu2/dashboards/tech_support/alerts.php) and [`dashboards/tech_support/techsupport.php`](file:///c:/xampp/htdocs/labsekyu2/dashboards/tech_support/techsupport.php).
    *   **Logic:** Submitted issues are saved to the `teacher_alerts` table with a `pending` status. This triggers a global sidebar notification across all Tech Support pages, immediately alerting them to a new issue. Tech Support can click "Resolve" to update the ticket status, which immediately reflects on the Teacher's feed.

## 3. Student Registration & Onboarding
**Purpose:** Allows Tech Support or Admins to securely onboard new students into the system with mandatory facial recognition data for attendance purposes.
**Key Files:**
*   [`dashboards/tech_support/register_student.php`](file:///c:/xampp/htdocs/labsekyu2/dashboards/tech_support/register_student.php)
    *   **Logic:** A dynamic 1-Upper / 2-Lower grid layout. The top grid handles the standard student credentials (saving the Student ID as the username and hashing the password). The bottom grids handle facial data acquisition, allowing the admin to either use a live webcam (via `navigator.mediaDevices.getUserMedia`) to capture and retake photos, or upload an existing file. The system validates against duplicate Student IDs before saving.

## 4. Dynamic Scheduling System
**Purpose:** Manages the allocation of subjects, sections, and teachers to specific laboratory rooms.
**Key Files:**
*   [`dashboards/tech_support/schedule.php`](file:///c:/xampp/htdocs/labsekyu2/dashboards/tech_support/schedule.php)
    *   **Logic:** Tech Support can create, edit, and delete class schedules. The logic ensures that time conflicts are prevented and specific teachers are tied to specific rooms.
*   [`dashboards/teacher/schedule.php`](file:///c:/xampp/htdocs/labsekyu2/dashboards/teacher/schedule.php)
    *   **Logic:** A read-only view that queries the `class_schedules` table to display only the specific schedule and room assignments relevant to the logged-in teacher.

## 5. Global Styling & UI Engine
**Purpose:** Ensures a consistent, professional, and responsive aesthetic across all user roles.
**Key Files:**
*   [`assets/css/dashboard.css`](file:///c:/xampp/htdocs/labsekyu2/assets/css/dashboard.css)
    *   **Logic:** Contains all the CSS variables (e.g., `--bg-blue`, `--accent-yellow`), grid layouts, and advanced animations (like `@keyframes alertGlow` for notifications). Modifying this file globally updates the look and feel of every dashboard in the system.
