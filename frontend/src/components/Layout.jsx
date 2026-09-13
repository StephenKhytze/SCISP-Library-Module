import React, { useState } from 'react';
import { Outlet, useNavigate } from 'react-router-dom';
import Sidebar from './Sidebar';
import Topbar from './Topbar';
import MobileNavDrawer from './MobileNavDrawer';
import { ToastProvider } from '../modules/library/ToastProvider';
import { ConfirmDialogProvider } from '../modules/library/ConfirmDialog';

export default function Layout() {
  const navigate = useNavigate();
  const userStr = localStorage.getItem('user');
  const user = userStr ? JSON.parse(userStr) : undefined;

  const [isDrawerOpen, setIsDrawerOpen] = useState(false);

  const handleLogout = () => {
    localStorage.removeItem('access_token');
    localStorage.removeItem('user');
    navigate('/auth');
  };

  const handleSelectUser = (u) => {
    localStorage.setItem('user', JSON.stringify(u));
    window.location.reload();
  };

  return (
    <ToastProvider>
      <ConfirmDialogProvider>
        {/*
          A fixed-height shell, not a min-height one.

          With `min-h-screen` the shell grew with its content, so the inner
          `overflow-hidden` never clipped anything and `main` was never height
          constrained — the window scrolled instead, taking the Topbar and
          Sidebar with it. On a phone that put the hamburger out of reach on any
          long page. `100dvh` (falling back to `100vh`) pins the shell to the
          viewport so only `main` scrolls.
        */}
        <div
          className="flex flex-col h-screen m-0 p-0 overflow-hidden bg-gray-100"
          style={{ height: '100dvh' }}
        >
          {/* Topbar spans the full width at the top and now stays put. */}
          <Topbar
            currentUser={user}
            onLogout={handleLogout}
            onSelectUser={handleSelectUser}
            onOpenMobileNav={() => setIsDrawerOpen(true)}
          />

          {/* Container for Sidebar and Main Content */}
          <div className="flex flex-1 min-h-0 overflow-hidden">
            <Sidebar />
            <main className="flex-1 min-w-0 overflow-y-auto overflow-x-hidden p-4 lg:p-8 bg-[#f8f9fa]">
              <Outlet />
            </main>
          </div>
        </div>

        <MobileNavDrawer
          isOpen={isDrawerOpen}
          onClose={() => setIsDrawerOpen(false)}
          onLogout={handleLogout}
        />
      </ConfirmDialogProvider>
    </ToastProvider>
  );
}
