import React, { useState } from 'react';
import { Bell, GraduationCap, Check, Shield, Crown, UserCheck, Laptop, LogOut } from 'lucide-react';

export default function Topbar({
  currentUser = { name: 'Juan Dela Cruz', role: 'Student', department: 'IT', idNumber: '12345' },
  users = [
    { name: 'Juan Dela Cruz', role: 'Student', department: 'IT', idNumber: '12345' },
    { name: 'Admin User', role: 'Admin', department: 'Administration', idNumber: '00001' }
  ],
  onSelectUser = () => {},
  onOpenTechSpec = () => {},
  onLogout = () => {},
}) {
  const [showNotifications, setShowNotifications] = useState(false);
  const [showUserDropdown, setShowUserDropdown] = useState(false);

  const notifications = [
    { id: '1', text: 'New announcement published by Registrar', time: '10m ago', unread: true },
    { id: '2', text: 'Midterm Exam permit is ready for download', time: '1h ago', unread: true },
    { id: '3', text: 'Library book "High-Performance MySQL" due soon', time: '1d ago', unread: false },
  ];

  return (
    <header className="h-[86px] bg-[#80172B] text-white flex items-center justify-between pr-8 select-none relative z-30 shadow-md border-b-2 border-[#651020]" style={{ paddingLeft: '32px' }}>
      {/* Left: ABC SCHOOL Brand Logo */}
      <div className="flex items-center space-x-3">
        <div className="flex items-center cursor-pointer group" onClick={() => window.location.reload()}>
          {/* ABC SCHOOL Emblem Replica matching template */}
          <div className="relative flex items-center">
            <span className="font-extrabold text-[40px] tracking-tighter text-white font-sans leading-none drop-shadow-sm">
              ABC
            </span>
            <span className="ml-1.5 px-1.5 py-[2px] bg-[#601020] border border-white/50 text-white text-[10px] font-bold tracking-wider rounded uppercase flex items-center shadow-inner self-start mt-2">
              SCHOOL
            </span>
          </div>
        </div>
      </div>

      {/* Right Controls: Notifications, Divider, Persona Profile */}
      <div className="flex items-center space-x-5 sm:space-x-6">

        {/* Notification Bell */}
        <div className="relative">
          <button
            onClick={() => {
              setShowNotifications(!showNotifications);
              setShowUserDropdown(false);
            }}
            className="p-2 hover:bg-white/10 rounded-full transition-colors relative cursor-pointer focus:outline-none focus:ring-2 focus:ring-white/30"
            aria-label="Notifications"
          >
            <Bell className="w-6 h-6 text-white fill-white" />
            {/* Notification gold badge matching image */}
            <span className="absolute top-1.5 right-1.5 w-3 h-3 bg-[#D4A373] border-2 border-[#80172B] rounded-full shadow-sm"></span>
          </button>

          {/* Notifications Dropdown */}
          {showNotifications && (
            <div className="absolute right-0 mt-2 w-80 bg-white text-gray-800 rounded-lg shadow-xl border border-gray-200 py-2 z-50 text-xs animate-in fade-in duration-150">
              <div className="px-3 py-2 border-b border-gray-100 flex items-center justify-between font-semibold text-gray-700">
                <span>Notifications</span>
                <span className="bg-[#80172B]/10 text-[#80172B] px-1.5 py-0.5 rounded text-[10px]">
                  2 New
                </span>
              </div>
              <div className="max-h-60 overflow-y-auto divide-y divide-gray-50">
                {notifications.map((n) => (
                  <div
                    key={n.id}
                    className={`p-3 hover:bg-gray-50 transition-colors flex items-start space-x-2.5 ${
                      n.unread ? 'bg-amber-50/40' : ''
                    }`}
                  >
                    <div className="w-2 h-2 rounded-full bg-[#80172B] mt-1.5 flex-shrink-0" />
                    <div className="flex-1">
                      <p className="text-gray-800 leading-snug">{n.text}</p>
                      <span className="text-[10px] text-gray-400 mt-1 block">{n.time}</span>
                    </div>
                  </div>
                ))}
              </div>
              <div className="p-2 border-t border-gray-100 text-center">
                <button
                  onClick={() => setShowNotifications(false)}
                  className="text-[11px] font-medium text-[#80172B] hover:underline"
                >
                  Mark all as read
                </button>
              </div>
            </div>
          )}
        </div>

        {/* Vertical Divider */}
        <div className="h-7 w-[1px] bg-white/20" />

        {/* User Persona Profile (Exact Match to Template Image) */}
        <div className="relative">
          <button
            onClick={() => {
              setShowUserDropdown(!showUserDropdown);
              setShowNotifications(false);
            }}
            className="flex items-center space-x-4 group hover:opacity-95 transition-opacity focus:outline-none"
            title="Switch User Role / View Profile"
          >
            {/* Persona Name & Role */}
            <div className="text-right flex flex-col justify-center leading-tight">
              <span className="font-bold text-base tracking-wide text-white group-hover:text-amber-100 transition-colors">
                {currentUser.name}
              </span>
              <span className="text-[12px] text-white/80 font-normal">
                {currentUser.role}
              </span>
            </div>

            {/* Circle Avatar with Graduation Cap Icon */}
            <div className="w-11 h-11 rounded-full bg-white flex items-center justify-center shadow-md text-[#182848] border border-white/80 flex-shrink-0 group-hover:scale-105 transition-transform">
              <GraduationCap className="w-6 h-6 text-[#182848]" />
            </div>
          </button>

          {/* User Profile & Role Switcher Popup */}
          {showUserDropdown && (
            <div className="absolute right-0 mt-2 w-64 bg-white text-gray-800 rounded-lg shadow-xl border border-gray-200 py-2 z-50 animate-in fade-in duration-150">
              <div className="px-4 py-2.5 border-b border-gray-100 bg-gray-50/80">
                <p className="text-xs font-semibold text-gray-900">{currentUser.name}</p>
                <p className="text-[11px] text-gray-500">{currentUser.department}</p>
                <p className="text-[10px] text-[#80172B] font-mono mt-0.5">ID: {currentUser.idNumber}</p>
              </div>

              <div className="px-3 py-1.5 text-[10px] uppercase font-bold text-gray-400 tracking-wider">
                Switch Role / Persona Mockup
              </div>

              {users.map((u) => (
                <button
                  key={u.idNumber}
                  onClick={() => {
                    onSelectUser(u);
                    setShowUserDropdown(false);
                  }}
                  className={`w-full text-left px-4 py-2.5 text-xs flex items-center justify-between hover:bg-gray-100 transition-colors ${
                    currentUser.idNumber === u.idNumber ? 'bg-amber-50 font-bold text-[#80172B]' : 'text-gray-700'
                  }`}
                >
                  <div className="flex items-center space-x-2.5">
                    {u.role === 'Student' && <GraduationCap className="w-4 h-4 text-[#80172B]" />}
                    {u.role === 'Teacher' && <UserCheck className="w-4 h-4 text-blue-700" />}
                    {u.role === 'Admin' && <Shield className="w-4 h-4 text-amber-700" />}
                    {u.role === 'Super Admin' && <Crown className="w-4 h-4 text-slate-800" />}
                    <div>
                      <p className="leading-tight font-semibold">{u.name}</p>
                      <p className="text-[10px] text-gray-500 font-normal">{u.role}</p>
                    </div>
                  </div>
                  {currentUser.idNumber === u.idNumber && <Check className="w-3.5 h-3.5 text-[#80172B]" />}
                </button>
              ))}

              <div className="border-t border-gray-100 mt-1 pt-1 px-2 space-y-0.5">
                <button
                  onClick={() => {
                    setShowUserDropdown(false);
                    onOpenTechSpec();
                  }}
                  className="w-full text-left px-3 py-1.5 text-xs text-gray-700 font-medium flex items-center space-x-2 hover:bg-gray-100 rounded transition-colors"
                >
                  <Laptop className="w-3.5 h-3.5 text-gray-500" />
                  <span>View Tech Architecture</span>
                </button>

                {onLogout && (
                  <button
                    onClick={() => {
                      setShowUserDropdown(false);
                      onLogout();
                    }}
                    className="w-full text-left px-3 py-1.5 text-xs text-rose-700 font-bold flex items-center space-x-2 hover:bg-rose-50 rounded transition-colors"
                  >
                    <LogOut className="w-3.5 h-3.5 text-rose-600" />
                    <span>Log Out / Switch Role Portal</span>
                  </button>
                )}
              </div>
            </div>
          )}
        </div>
      </div>
    </header>
  );
}
