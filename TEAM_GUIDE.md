# ABC School Student Portal - Team Guide

This repository contains the master template for the ABC School Student Portal, including the global navigation (Sidebar and Topbar) and foundational routing.

This guide outlines the local environment setup procedure and group file assignments.

---

## Step 1: Initial Setup

The application is fully containerized using Docker to ensure consistent environments across all machines. You do not need to install Node.js, PHP, or NPM locally.

### Prerequisites:
1. Install Docker Desktop and ensure the daemon is running.
2. Install Git and clone this repository to your local machine.

### Starting the Application:
Open your terminal (Command Prompt, PowerShell, or Git Bash) in the project root directory and execute:

```bash
docker compose up -d
```

**Execution Details:**
- Downloads necessary base images (Node, MySQL, PHP).
- Automatically executes `npm install` inside the container to install React and Tailwind CSS.
- Starts the frontend Vite server on port `5173`.
- Starts the database and backend services.

*(Note: The initial build may take several minutes to download images and dependencies.)*

### Accessing the Application:
Once the containers have started successfully, open your web browser and navigate to:
**http://localhost:5173**

To stop the servers, run `docker compose down`.

---

## Step 2: Project Architecture & Rules

The architecture separates the global UI components from the module-specific content.

### Restricted Files (Global UI):
The following files control the global layout, navigation, and routing. Do not modify these files unless authorized by Group 1 (Auth/Core):
*   `frontend/src/App.jsx` (Routing configuration)
*   `frontend/src/components/Layout.jsx` (Structural wrapper)
*   `frontend/src/components/Sidebar.jsx` (Left navigation)
*   `frontend/src/components/Topbar.jsx` (Top bar header)
*   `frontend/tailwind.config.js` and `frontend/postcss.config.js`

---

## Step 3: Group Assignments

When developing your assigned module, you should only edit the specific file assigned to your group located in the `frontend/src/modules/` directory.

The React Router is pre-configured. Any code written in your assigned file will automatically render in the main content area when the corresponding sidebar tab is clicked.

### Group 1: Auth & Dashboard
*   **Auth / Login Page:** `frontend/src/modules/auth/Login.jsx`
*   **Dashboard (Home):** `frontend/src/modules/home/Dashboard.jsx`

### Group 2: Library Module
*   **Main File:** `frontend/src/modules/library/LibraryPortal.jsx`

### Group 3: Class Schedule & Faculty Directory
*   **Schedule File:** `frontend/src/modules/schedule/ScheduleView.jsx`
*   **Faculty File:** `frontend/src/modules/faculty/FacultyList.jsx`

### Group 4: Announcements Module
*   **Main File:** `frontend/src/modules/announcements/AnnouncementList.jsx`

### Group 5: Student Information Module
*   **Main File:** `frontend/src/modules/student_info/StudentProfile.jsx`

---

## Development Guidelines

1. **Hot Reloading:** The frontend server supports hot module replacement (HMR). You do not need to restart Docker when saving a file; the browser will update automatically.
2. **File Structure:** You may create additional folders and files (e.g., components) strictly inside your assigned module directory. Import these new files into your main module file.
3. **Styling:** The project uses Tailwind CSS. Apply Tailwind utility classes directly within your JSX.

---

## Step 4: Version Control Workflow (Git & GitHub)

To maintain code stability and prevent conflicts, all teams must adhere to the following Git workflow.

### 1. Create a Feature Branch
Never commit directly to the `master` branch. When starting work, always create a new branch named after yourself or the specific feature you are building:
```bash
git checkout master
git pull origin master
git checkout -b yourname
```

### 2. Commit Your Changes
As you make progress, commit your changes locally with clear messages:
```bash
git add .
git commit -m "Add book checkout interface to library portal"
```

### 3. Push to GitHub
Push your local branch to the remote GitHub repository:
```bash
git push -u origin yourname
```

### 4. Create a Pull Request (PR)
1. Navigate to the project repository on GitHub.
2. Click "Compare & pull request" next to your recently pushed branch.
3. Review the file changes to ensure you only modified your group's assigned files.
4. Submit the Pull Request for review.
5. Once approved by a reviewer, the branch will be merged into the `master` branch.

### 5. Keep Your Environment Updated
Regularly pull the latest changes from the remote repository to ensure your local environment is up to date with the rest of the teams:
```bash
git checkout master
git pull origin master
```
