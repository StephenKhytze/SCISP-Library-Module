# Project Setup Guide

## Laravel Backend + React Frontend

This guide explains how to set up the project on a **new computer** by downloading the project from GitHub and properly initializing all required dependencies, configuration files, environment variables, authentication settings, and database components.

This guide is written so that a developer who has **little experience with Laravel, React, Git, or command-line tools** can follow it step by step.

For a better reading experience, press Ctrl+Shift+V

---

# Table of Contents

1. [What This Project Contains](#1-what-this-project-contains)
2. [How the Project Works](#2-how-the-project-works)
3. [Requirements](#3-requirements)
4. [Before Cloning the Repository](#4-before-cloning-the-repository)
5. [Clone the GitHub Repository](#5-clone-the-github-repository)
6. [Verify the Project Structure](#6-verify-the-project-structure)
7. [Set Up the Laravel Backend](#7-set-up-the-laravel-backend)
8. [Install Backend Dependencies](#8-install-backend-dependencies)
9. [Create the Backend Environment File](#9-create-the-backend-environment-file)
10. [Configure the Database](#10-configure-the-database)
11. [Generate the Laravel Application Key](#11-generate-the-laravel-application-key)
12. [Initialize JWT Authentication](#12-initialize-jwt-authentication)
13. [Initialize Laravel Storage](#13-initialize-laravel-storage)
14. [Initialize the Database](#14-initialize-the-database)
15. [Clear Laravel Cache](#15-clear-laravel-cache)
16. [Start and Test the Backend](#16-start-and-test-the-backend)
17. [Set Up the React Frontend](#17-set-up-the-react-frontend)
18. [Install Frontend Dependencies](#18-install-frontend-dependencies)
19. [Create the Frontend Environment File](#19-create-the-frontend-environment-file)
20. [Start and Test the Frontend](#20-start-and-test-the-frontend)
21. [Test the Login Module](#21-test-the-login-module)
22. [Understanding Git-Ignored Files](#22-understanding-git-ignored-files)
23. [What Should and Should Not Be Committed](#23-what-should-and-should-not-be-committed)
24. [Daily Development Procedure](#24-daily-development-procedure)
25. [Common Problems and Solutions](#25-common-problems-and-solutions)
26. [Complete Setup Checklist](#26-complete-setup-checklist)

---

# 1. What This Project Contains

The repository contains the project's main application components.

The project is divided into two major parts:

```text
PROJECT/
├── backend/
└── frontend/
```

### Backend

The `backend` folder contains the Laravel application.

It is responsible for:

- User authentication
- JWT authentication
- API endpoints
- Database communication
- User information
- Server-side application logic
- Database migrations
- Other backend functionality

### Frontend

The `frontend` folder contains the React application.

It is responsible for:

- Login interface
- Dashboard/template interface
- Header
- Sidebar
- User interface
- Communicating with the Laravel API

The frontend communicates with the backend through HTTP API requests.

---

# 2. How the Project Works

The application follows this general structure:

```text
                    USER
                     │
                     ▼
              React Frontend
              localhost:5173
                     │
                     │ API Requests
                     ▼
              Laravel Backend
              localhost:8000
                     │
                     ▼
                  MySQL
```

For example, when a user logs in:

```text
User enters username/password
             │
             ▼
       React Login Page
             │
             ▼
      POST /auth/login
             │
             ▼
      Laravel Backend
             │
             ▼
     Validate credentials
             │
       ┌─────┴─────┐
       │           │
    Invalid      Valid
       │           │
       ▼           ▼
     Error      JWT Token
                   │
                   ▼
              React stores
              access token
                   │
                   ▼
             GET /auth/me
                   │
                   ▼
                User
                   │
                   ▼
              Dashboard
```

Both the frontend and backend therefore need to be running during development.

---

# 3. Requirements

Before starting, install the following software.

## 3.1 Git

Git is used to download the project from GitHub and synchronize changes.

Download and install Git from the official Git website.

After installation, open PowerShell and run:

```powershell
git --version
```

You should see a version number.

Example:

```text
git version 2.x.x
```

If PowerShell says:

```text
git is not recognized
```

Git is either not installed or was not added to your system PATH.

---

# 3.2 PHP

Laravel requires PHP.

Check whether PHP is installed:

```powershell
php --version
```

You should see a PHP version.

The required PHP version should match the version specified by the project's Laravel/composer configuration.

If PHP is being provided through XAMPP, make sure the XAMPP PHP executable is available to the command line.

---

# 3.3 Composer

Composer manages PHP dependencies for Laravel.

Check:

```powershell
composer --version
```

You should receive a Composer version.

If Composer is not recognized, install Composer and make sure it can be accessed from PowerShell.

---

# 3.4 Node.js and npm

The React frontend uses Node.js and npm.

Check:

```powershell
node --version
npm --version
```

Both commands should return version numbers.

npm is installed together with Node.js.

---

# 3.5 XAMPP

XAMPP is used to provide the local MySQL/MariaDB database environment.

Open XAMPP Control Panel.

Make sure MySQL can be started.

For this project, MySQL is required because the Laravel backend needs to communicate with the project's database.

Apache is not necessarily required when Laravel is being run using:

```powershell
php artisan serve
```

---

# 4. Before Cloning the Repository

Before downloading the project, decide where you want to store it.

For example:

```text
C:\Users\<YourUsername>\Desktop\
```

You do **not** need to put the project inside:

```text
C:\xampp\htdocs
```

when using Laravel's development server.

The project can be located anywhere convenient.

For example:

```text
C:\Users\John\Desktop\SchoolProject\
```

---

# 5. Clone the GitHub Repository

## Step 1 — Open PowerShell

Open Windows PowerShell.

Navigate to the directory where you want to store the project.

Example:

```powershell
cd "C:\Users\YourName\Desktop"
```

Replace `YourName` with your Windows username.

---

## Step 2 — Clone the repository

Run:

```powershell
git clone <REPOSITORY_URL>
```

Replace `<REPOSITORY_URL>` with the actual GitHub repository URL.

For example:

```powershell
git clone https://github.com/example/project.git
```

---

## Step 3 — Enter the project

After cloning:

```powershell
cd <REPOSITORY_NAME>
```

For example:

```powershell
cd project
```

---

# 6. Verify the Project Structure

Before doing anything else, check that the project contains both folders:

```text
PROJECT/
├── backend/
└── frontend/
```

The repository should also contain the appropriate Laravel and React files.

A simplified structure should look similar to:

```text
PROJECT/
│
├── backend/
│   ├── app/
│   ├── bootstrap/
│   ├── config/
│   ├── database/
│   ├── public/
│   ├── resources/
│   ├── routes/
│   ├── composer.json
│   ├── composer.lock
│   └── .env.example
│
└── frontend/
    ├── public/
    ├── src/
    ├── package.json
    ├── package-lock.json
    ├── vite.config.js
    └── .env.example
```

Do not worry if you cannot see:

```text
vendor/
node_modules/
.env
```

Those files/folders may intentionally be excluded from Git.

They will be created during setup.

---

# 7. Set Up the Laravel Backend

Open PowerShell and navigate into the backend folder:

```powershell
cd backend
```

You should now be inside:

```text
PROJECT/backend
```

---

# 8. Install Backend Dependencies

Run:

```powershell
composer install
```

Composer will read:

```text
composer.json
composer.lock
```

and download the required PHP packages.

This will create:

```text
backend/vendor/
```

### Important

Do **not** download or copy the `vendor` folder from another developer's computer.

Every developer should run:

```powershell
composer install
```

on their own machine.

The `vendor` folder is normally ignored by Git.

### If Composer reports an error

Do not immediately delete files or reinstall PHP.

Read the error carefully.

Common causes include:

- PHP version incompatibility
- Missing PHP extensions
- Internet connection problems
- Composer not installed correctly
- Incorrect project directory

---

# 9. Create the Backend Environment File

Laravel uses a `.env` file for machine-specific configuration.

This file is normally **not committed to Git**.

The repository should instead contain:

```text
.env.example
```

Use it as the template for your local `.env`.

From the `backend` directory, run this command using Powershell:

```powershell
Copy-Item .env.example .env
```

You should now have:

```text
backend/
├── .env
└── .env.example
```

### Important distinction

`.env.example`

```text
SHOULD be committed to Git.
```

`.env`

```text
SHOULD NOT be committed to Git.
```

The `.env` file may contain:

- Database passwords
- Application keys
- JWT secrets
- API keys
- Other private configuration

---

# 10. Configure the Database

## Step 1 — Start MySQL

Open XAMPP Control Panel.

Start:

```text
MySQL
```

Make sure it successfully starts.

---

## Step 2 — Create the database

Open phpMyAdmin or another MySQL management tool.

Create the database required by the project.

For example:

```text
auth_db
```

Use the actual database name specified by the project documentation or `.env.example`.

---

## Step 3 — Open `.env`

Open:

```text
backend/.env
```

Look for the database configuration.

It should look similar to:

```env
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=<module name>_db // If using Docker Desktop, leave the DB_DATABASE as "laravel"
DB_USERNAME=root
DB_PASSWORD=
```

Adjust these values according to your local MySQL configuration.

### Example

If your database is named:

```text
auth_db
```

and your MySQL username is:

```text
root
```

with no password:

```env
DB_DATABASE=auth_db
DB_USERNAME=root
DB_PASSWORD=
```

Do not blindly copy another developer's database password.

---

# 11. Generate the Laravel Application Key

From:

```text
backend/
```

run:

```powershell
php artisan key:generate
```

Laravel should report that the application key was successfully generated.

This modifies your local:

```text
.env
```

Do not commit the resulting `.env`.

---

# 12. Initialize JWT Authentication

The Login Module uses JWT (JSON Web Token) authentication using the RS256 asymmetric cryptographic algorithm. This implementation is native to the application (defined in `App\Services\JwtService`) and does not rely on third-party PHP packages or external configuration files.

To support token signing and verification, you must generate a private/public RSA key pair in the `backend/storage/` directory.

---

## Step 1 — Verify key directory

From the `backend` directory, make sure the `storage` directory exists. If it does not exist, run:

```powershell
mkdir storage
```

---

## Step 2 — Generate the JWT RSA keys

Run the following commands in your terminal (using Git Bash, WSL, or any environment with `openssl` installed) to generate the key pair:

1. **Generate the private key:**
   ```bash
   openssl genpkey -algorithm RSA -pkeyopt rsa_keygen_bits:2048 -out storage/jwt-private.pem
   ```

2. **Generate the public key from the private key:**
   ```bash
   openssl pkey -in storage/jwt-private.pem -pubout -out storage/jwt-public.pem
   ```

After running these commands, verify that the key files are present:

```text
backend/
└── storage/
    ├── jwt-private.pem
    └── jwt-public.pem
```

---

## Step 3 — Secure your keys

* **Important:** The private key (`jwt-private.pem`) must **never** be committed to GitHub or shared publicly. It is automatically ignored in the project's `.gitignore` file.
* Each developer must generate their own local key pair during local environment setup. Do not share or reuse keys between different environments.

Do not copy another developer's private key into your local environment unless the project specifically requires a shared development key.

---

# 13. Initialize the Database

The Laravel project contains database migrations.

Migrations create the required tables.

For a completely new development database, run:

```powershell
docker compose exec backend php artisan migrate --seed
```

This will:

1. Connect Laravel to MySQL.
2. Run the database migrations.
3. Create the required database tables.
4. Run database seeders if the project provides them.

Make sure to run:

```powershell
docker compose up -d
```

For the command to work.

---

## Important: `migrate:fresh`

You may encounter instructions telling you to use:

```powershell
php artisan migrate:fresh --seed
```

This command is different.

It **deletes the existing database tables before recreating them**.

Therefore:

```text
New/empty development database
→ migrate --seed

Existing database containing important data
→ DO NOT use migrate:fresh without confirmation
```

Only use:

```powershell
php artisan migrate:fresh --seed
```

when you intentionally want to completely rebuild the development database.

---

# 14. Clear Laravel Cache

After creating or changing `.env`, run:

```powershell
docker compose exec backend php artisan optimize:clear
```

This clears Laravel's cached configuration and other cached application data.

This is especially useful after setting up the project on a new computer.

---

# 15. Start and Test the Backend

From:

```text
backend/
```

run:

```powershell
php artisan serve
```

You should see something similar to:

```text
INFO  Server running on [http://127.0.0.1:8000].
```

Keep this PowerShell window open.

Do not close it while testing the frontend.

The backend is now running at:

```text
http://127.0.0.1:8000
```

Press Ctrl+C to stop the server as it is already being run in Docker Desktop.

---

# 16. Set Up the React Frontend

Open a **second PowerShell window**.

Do not stop the Laravel server.

Navigate to the project's frontend:

```powershell
cd "C:\Path\To\Project\frontend"
```

For example:

```powershell
cd "C:\Users\YourName\Desktop\project\frontend"
```

---

# 17. Install Frontend Dependencies

Run:

```powershell
npm install
```

npm will read:

```text
package.json
package-lock.json
```

and install the required packages.

This creates:

```text
frontend/node_modules/
```

### Important

Do not copy `node_modules` from another developer.

Do not commit `node_modules` to Git.

If it does not exist after cloning, that is normal.

Run:

```powershell
npm install
```

instead.

---

# 18. Configure the Backend URL

The React frontend needs to know where the Laravel API is located.

Check:

```text
frontend/src/services/
```

and locate the API configuration file.

It may use a Vite environment variable such as:

```env
baseURL: "http://127.0.0.1:8000/api"
```

The exact environment variable name must match what the project's API service expects.

Do not rename the variable unless you also change the code that reads it.

---

# 19. Start and Test the Frontend

From:

```text
frontend/
```

run:

```powershell
npm run dev
```

Vite should display a local address similar to:

```text
Local: http://localhost:5173/
```

Run Ctrl+C to stop npm run dev if running Docker Desktop.

Open the address in your browser.

You should see the application's login page.

---

# NOTE: THE NEXT SECTION IS NOT NEEDED WHEN USING DOCKER DESKTOP, ONLY RUN THESE COMMANDS WHEN DOCKER DESKTOP IS NOT RUNNING. OTHERWISE IGNORE THESE. PROCEED IF YOU EXPERIENCE ANY TECHNICAL ISSUES.

# 20. Test the Login Module

At this point, both servers should be running.

### Terminal 1 — Laravel

```powershell
cd backend
php artisan serve
```

Running at:

```text
http://127.0.0.1:8000
```

### Terminal 2 — React/Vite

```powershell
cd frontend
npm run dev
```

Running at:

```text
http://localhost:5173
```

---

## Test 1 — Login Page

Open:

```text
http://localhost:5173
```

The Login Module should appear.

---

## Test 2 — Invalid Login

Enter an intentionally incorrect username/password.

The application should display an authentication error.

If the page remains stuck on:

```text
Authenticating...
```

check the browser's developer console and the Laravel terminal for errors.

---

## Test 3 — Valid Login

Use a valid account created by the project's database seeders or an account already available in the development database.

The expected process is:

```text
Login
 ↓
POST /auth/login
 ↓
Laravel validates credentials
 ↓
JWT access token generated
 ↓
React stores access token
 ↓
GET /auth/me
 ↓
User information returned
 ↓
Dashboard displayed
```

After successful authentication, the blank/template dashboard should appear.

---

# 21. Understanding Git-Ignored Files

One of the most important concepts when setting up this project is understanding `.gitignore`.

Git intentionally does not track certain files.

This is normal.

For example:

```text
backend/vendor/
frontend/node_modules/
backend/.env
frontend/.env
```

may not exist immediately after cloning.

This does **not** mean the repository is broken.

These files are generated locally.

---

## Backend

After cloning:

```text
backend/
├── app/
├── config/
├── database/
├── routes/
├── composer.json
├── composer.lock
└── .env.example
```

After running the setup:

```text
backend/
├── app/
├── config/
├── database/
├── routes/
├── vendor/          ← generated by composer install
├── .env             ← created from .env.example
├── .env.example
└── ...
```

---

## Frontend

After cloning:

```text
frontend/
├── src/
├── public/
├── package.json
├── package-lock.json
└── ...
```

After running:

```powershell
npm install
```

you get:

```text
frontend/
├── src/
├── public/
├── node_modules/    ← generated by npm install
├── package.json
├── package-lock.json
└── ...
```

Again, this is expected.

---

# 22. What Should and Should Not Be Committed

## Generally commit these

```text
app/
config/
database/
routes/
resources/
src/
public/
composer.json
composer.lock
package.json
package-lock.json
vite.config.js
eslint.config.js
.env.example
```

The exact list depends on the repository's `.gitignore`.

---

## Generally DO NOT commit these

```text
.env
vendor/
node_modules/
dist/
```

Also do not commit:

```text
JWT secrets
API keys
database passwords
private credentials
```

---

# 23. Daily Development Procedure

Once the project has been completely configured, developers **do not need to repeat the entire setup every day**.

Normally, they only need two terminals.

### Terminal 1

```powershell
cd backend
php artisan serve
```

### Terminal 2

```powershell
cd frontend
npm run dev
```

Then open:

```text
http://localhost:5173
```

---

# 24. Common Problems and Solutions

## Problem: `git` is not recognized

Example:

```text
'git' is not recognized as an internal or external command
```

### Solution

Install Git and restart PowerShell.

---

## Problem: `composer` is not recognized

Example:

```text
'composer' is not recognized
```

### Solution

Install Composer and make sure it is added to the system PATH.

Restart PowerShell afterward.

---

## Problem: `php` is not recognized

Example:

```text
'php' is not recognized
```

### Solution

PHP is either not installed or its executable is not available through PATH.

If using XAMPP, verify that the PHP executable exists in the XAMPP installation.

---

## Problem: `npm` is not recognized

Example:

```text
'npm' is not recognized
```

### Solution

Install Node.js.

Restart PowerShell after installation.

---

## Problem: `vendor` does not exist

This is usually normal after cloning.

Run:

```powershell
composer install
```

---

## Problem: `node_modules` does not exist

This is normal after cloning.

Run:

```powershell
npm install
```

---

## Problem: `.env` does not exist

This is usually normal.

For Laravel:

```powershell
cd backend
Copy-Item .env.example .env
```

For the frontend, if `.env.example` exists:

```powershell
cd frontend
Copy-Item .env.example .env
```

---

## Problem: Laravel says the application key is missing

Run:

```powershell
php artisan key:generate
```

---

## Problem: Laravel cannot connect to MySQL

Check:

1. Is MySQL running in XAMPP?
2. Is the database name correct?
3. Is `DB_HOST` correct?
4. Is `DB_PORT` correct?
5. Is the username correct?
6. Is the password correct?

For a typical local MySQL configuration:

```env
DB_HOST=127.0.0.1
DB_PORT=3306
```

---

## Problem: `SQLSTATE` database errors

First verify the database configuration in:

```text
backend/.env
```

Then run:

```powershell
php artisan optimize:clear
```

Try the migration again:

```powershell
php artisan migrate
```

---

## Problem: The frontend displays a white screen

Open the browser developer tools:

```text
F12
```

Then select:

```text
Console
```

Look for the first red error.

Do not immediately start changing multiple files.

The first error is usually the most useful one.

Also check the Vite terminal for compilation errors.

---

## Problem: The frontend loads but the CSS is broken

Check whether the frontend dependencies were installed:

```powershell
npm install
```

Then restart Vite:

```powershell
npm run dev
```

If Tailwind or Vite configuration was recently changed, completely stop the Vite server and start it again.

---

## Problem: The frontend says `Login is not defined`

Check the import in `App.jsx`.

The import should correspond to the actual file and export.

For example:

```javascript
import Login from "./components/views/LoginView";
```

Also verify that the file actually exists:

```text
frontend/
└── src/
    └── components/
        └── views/
            └── LoginView.tsx
```

---

## Problem: Vite seems to be using old code

Stop the Vite development server:

```text
Ctrl + C
```

Then start it again:

```powershell
npm run dev
```

A Vite server restart can resolve situations where recently changed frontend files appear not to be reflected correctly.

---

## Problem: The login stays on "Authenticating..."

Check:

### Browser console

Press:

```text
F12
```

and select:

```text
Console
```

### Network tab

Select:

```text
Network
```

Then attempt the login again.

Look for:

```text
/auth/login
```

Check:

- Request URL
- Request method
- Status code
- Response
- Whether the request was actually sent

### Laravel terminal

Look at the terminal running:

```powershell
php artisan serve
```

If Laravel receives the request, there should generally be activity associated with the request.

---

## Problem: Changes to `.env` do not appear to work

After changing Laravel `.env` configuration, run:

```powershell
php artisan optimize:clear
```

Then restart Laravel if necessary.

For Vite, restart the development server after changing frontend environment variables:

```text
Ctrl + C
```

then:

```powershell
npm run dev
```

---

## Problem: Incompatible PHP version during `composer install`

If you run `composer install` and get an error stating that your PHP version is incompatible with the project's requirements.

### Solution

You need to download and install a newer version of PHP that meets the requirements specified in `composer.json`.
1. Download a newer PHP thread-safe version from the official [PHP for Windows download page](https://windows.php.net/download/).
2. Update your system's Environment Variables (`PATH`) to point to the new PHP directory.
3. Restart your PowerShell terminal and run `php -v` to verify the update.

---

## Problem: Docker and XAMPP port conflict (same port used)

When starting Docker containers, you get an error that a port (like `3306` for MySQL) is already in use by another application (e.g., XAMPP).

### Solution

1. **Option A (Recommended):** Stop the conflicting service in XAMPP. For example, open the XAMPP Control Panel and stop **MySQL** before running `docker compose up -d`.
2. **Option B:** If you want to connect your local environment to your XAMPP MySQL database instead of Docker's database, add or modify the following line in your `backend/.env`:
   ```env
   DB_HOST=host.docker.internal
   ```
3. **Option C:** If you want to run Docker's MySQL on a different host port (e.g., `3307`), you can update the host port in `docker-compose.yml` under the `db` service ports from `"3306:3306"` to `"3307:3306"`, and then add/update the database port in your `backend/.env`:
   ```env
   DB_PORT=3307
   ```

---

## Problem: Apache fails to start or errors in XAMPP after updating PHP

### Solution

This is a common initialization quirk with XAMPP's Apache service after updating your PHP files or paths. Try the following:
1. Toggle the **Apache** service **Start** and **Stop** buttons in the XAMPP Control Panel a few times (around 3 times).
2. If it still fails, close XAMPP completely, right-click the XAMPP Control Panel icon, select **Run as administrator**, and try toggling Apache again.
3. Check the Apache Error Log in XAMPP to ensure there are no syntax errors in the updated PHP configurations.

---

## Problem: WSL installation is stuck or frozen

When installing WSL, the process remains stuck indefinitely on the message `Installing: Windows Subsystem for Linux...`.

### Solution

1. Restart your computer.
2. Open PowerShell as an Administrator.
3. Run the installation command again:
   ```powershell
   wsl --install
   ```
4. If it gets stuck again, make sure that **Virtualization** is enabled in your system's BIOS settings and that the **Virtual Machine Platform** Windows feature is enabled.

---

## Problem: Vite options/shortcuts appear in the terminal, or terminal is blocked

This happens when you run `docker compose up` without the `-d` (detached) flag, causing all container output and Vite's interactive menu to run in the foreground.

### Solution

1. Press `Ctrl + C` in the terminal to stop the foreground processes.
2. Start the containers in the background (detached mode) by running:
   ```powershell
   docker compose up -d
   ```
3. Once running in detached mode, you can safely close the terminal tab or window.

---

# 25. Complete Setup Checklist

Use this checklist when setting up the project on a new computer.

## Software

- [ ] Git installed
- [ ] PHP installed
- [ ] Composer installed
- [ ] Node.js installed
- [ ] npm installed
- [ ] XAMPP installed
- [ ] MySQL available

---

## Repository

- [ ] Repository cloned from GitHub
- [ ] `backend/` exists
- [ ] `frontend/` exists

---

## Backend

- [ ] Entered `backend/`
- [ ] Ran `composer install`
- [ ] Created `.env`
- [ ] Configured database credentials
- [ ] Created database
- [ ] Ran `php artisan key:generate`
- [ ] Initialized JWT secret if required
- [ ] Ran `php artisan storage:link` if required
- [ ] Ran migrations
- [ ] Ran seeders if required
- [ ] Ran `php artisan optimize:clear`
- [ ] Backend starts with `php artisan serve`

---

## Frontend

- [ ] Entered `frontend/`
- [ ] Ran `npm install`
- [ ] Created `.env` if required
- [ ] Configured API URL if required
- [ ] Frontend starts with `npm run dev`

---

## Authentication

- [ ] Login page appears
- [ ] Invalid credentials produce an error
- [ ] Valid credentials reach `/auth/login`
- [ ] JWT access token is returned
- [ ] `/auth/me` successfully returns the user
- [ ] Dashboard/template page appears
- [ ] Logout removes the local authentication token
- [ ] Logging out returns to the Login page

---

# Final Expected Project

After completing the setup, the local project should conceptually look like:

```text
PROJECT/
│
├── backend/
│   ├── app/
│   ├── bootstrap/
│   ├── config/
│   ├── database/
│   ├── public/
│   ├── resources/
│   ├── routes/
│   ├── storage/
│   ├── vendor/              ← generated locally
│   ├── .env                 ← generated locally
│   ├── .env.example         ← from GitHub
│   ├── artisan
│   ├── composer.json
│   └── composer.lock
│
└── frontend/
    ├── public/
    ├── src/
    ├── node_modules/        ← generated locally
    ├── .env                 ← generated locally, if required
    ├── .env.example         ← from GitHub, if provided
    ├── package.json
    ├── package-lock.json
    ├── vite.config.js
    └── eslint.config.js
```

The two applications should then run simultaneously:

```text
Laravel Backend
http://127.0.0.1:8000

React Frontend
http://localhost:5173
```

The frontend communicates with the backend, and the backend communicates with MySQL.

Once this initial setup is complete, developers normally only need to start the Laravel and Vite development servers when working on the project.
