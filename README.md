# Iseki Oiler - Production Line Oiling Station System

## Overview

**Iseki Oiler** is a specialized station management system designed to track and validate the oiling process in a production line. It acts as an intermediary station that ensures correct process sequencing by integrating with the central **Podium** production planning system and hardware sensors (NodeMCU).

The system ensures that tractors are processed in the right order and that all preceding assembly steps are completed before the oiling process can begin.

## Key Features

### 1. Sequential Scan & Validation
*   **Sequence Scanning**: Interface for operators to scan tractor sequence numbers.
*   **Cross-System Validation**: Integrates with the **Podium** database to:
    *   Verify the existence of the production plan for a specific sequence.
    *   Enforce production rules (checks if previous steps like assembly or parcom are completed).
*   **Incomplete Process Prevention**: Prevents starting a new scan if a previous tractor hasn't triggered the oil detection sensor.

### 2. Hardware Integration (NodeMCU)
*   **Automated Detection**: Supports API calls from NodeMCU-based sensors.
*   **Real-time Updates**: Automatically records `Detect_Time_Record` when the physical oiling process is confirmed by hardware.
*   **Process Completion**: Once the oiling step is detected, the system updates the central Podium plan. If all defined rules for a model are met, it automatically marks the plan as `done`.

### 3. Monitoring & Reporting
*   **Record Listing**: A dashboard powered by **Yajra DataTables** for browsing scan history.
*   **Status Tracking**: View real-time scan times and detection status for every unit in the production line.

## Technology Stack

### Backend
*   **Framework**: [Laravel 12.x](https://laravel.com)
*   **Language**: PHP ^8.2
*   **Database**: SQLite (Local records) & MySQL/MariaDB (Podium integration)
*   **Data Grids**: `yajra/laravel-datatables` ^12.0

### Frontend
*   **Build Tool**: [Vite](https://vitejs.dev)
*   **Styling**: [Tailwind CSS v4.0](https://tailwindcss.com)
*   **HTTP Client**: Axios

## Installation & Setup

1.  **Clone the Repository**
    ```bash
    git clone <repository-url>
    cd iseki_oiler
    ```

2.  **Install PHP Dependencies**
    ```bash
    composer install
    ```

3.  **Install Node Dependencies**
    ```bash
    npm install
    ```

4.  **Environment Configuration**
    *   Copy the example environment file:
        ```bash
        cp .env.example .env
        ```
    *   **CRITICAL**: Configure the `DB_CONNECTION_PODIUM` settings in `.env` to connect to the central Podium database.

5.  **Initialize Application**
    ```bash
    php artisan key:generate
    php artisan migrate
    ```

6.  **Build Frontend**
    ```bash
    npm run build
    ```

7.  **Run Development Server**
    ```bash
    php artisan serve
    ```

## API Endpoints for Integration

*   **`POST /scan`**: Used by the operator terminal to start a process.
*   **`GET /api/nodemcu/status` (example logic)**: Endpoint called by NodeMCU to confirm oil detection.

## License

This project is proprietary.
