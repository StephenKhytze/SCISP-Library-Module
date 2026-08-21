import React, { useState } from 'react';
import { Link, useLocation } from 'react-router-dom';
import { Home, Calendar, Monitor, BookOpen, GraduationCap, Users, LogOut, ArrowLeft, ArrowRight } from 'lucide-react';

export default function Sidebar() {
  const location = useLocation();
  const [isCollapsed, setIsCollapsed] = useState(false);

  const navItems = [
    { path: '/', label: 'Home', icon: Home },
    { path: '/schedule', label: 'Schedule', icon: Calendar },
    { path: '/announcements', label: 'Announcements', icon: Monitor },
    { path: '/library', label: 'Library', icon: BookOpen },
    { path: '/student-info', label: 'Student Information', icon: GraduationCap },
    { path: '/faculty', label: 'Faculty Directory', icon: Users },
  ];

  return (
    <aside 
      className={`${isCollapsed ? 'w-[100px]' : 'w-[280px]'} bg-[#80172B] text-white flex flex-col min-h-[calc(100vh-86px)] shrink-0 transition-all duration-300 ease-in-out`}
    >
      {/* Collapse arrow at the top right of sidebar */}
      <div className={`flex ${isCollapsed ? 'justify-center' : 'justify-end'} p-5 transition-all duration-300`}>
        <button 
          onClick={() => setIsCollapsed(!isCollapsed)}
          className="text-white hover:bg-white/10 p-1.5 rounded transition-colors focus:outline-none"
          title={isCollapsed ? "Expand Sidebar" : "Collapse Sidebar"}
        >
          {isCollapsed ? <ArrowRight className="w-6 h-6 text-white/80" /> : <ArrowLeft className="w-6 h-6 text-white/80" />}
        </button>
      </div>

      {/* Navigation Links */}
      <nav className="flex-1 flex flex-col space-y-1.5 mt-2">
        {navItems.map((item) => {
          const isActive = location.pathname === item.path;
          return (
            <div key={item.path} className="relative">
              <Link
                to={item.path}
                className={`flex items-center py-4 transition-all duration-300 ${
                  isActive
                    ? 'bg-[#182848] text-white rounded-r-2xl shadow-[6px_0_15px_rgba(0,0,0,0.25)] w-[calc(100%+20px)] relative z-10'
                    : 'text-white/80 hover:bg-white/10 hover:text-white rounded-r-2xl w-full pr-4'
                } ${isCollapsed ? 'justify-center' : 'space-x-4'}`}
                style={{ paddingLeft: isCollapsed ? '0' : '32px' }}
                title={isCollapsed ? item.label : undefined}
              >
                <item.icon className={`w-5 h-5 flex-shrink-0 ${isActive ? 'text-white' : 'text-white/80'}`} />
                {!isCollapsed && (
                  <span className="font-bold text-[15px] tracking-wide whitespace-nowrap overflow-hidden transition-all duration-300">
                    {item.label}
                  </span>
                )}
              </Link>
            </div>
          );
        })}
      </nav>

      {/* Sign Out Button at the bottom */}
      <div className="mt-auto border-t border-[#651020] pt-4 pb-6">
        <Link
          to="/auth"
          onClick={() => {
            localStorage.removeItem('access_token');
            localStorage.removeItem('user');
          }}
          className={`flex items-center py-4 text-white/80 hover:bg-white/10 hover:text-white rounded-r-2xl w-full transition-all duration-300 ${
            isCollapsed ? 'justify-center pr-0' : 'space-x-4 pr-4'
          }`}
          style={{ paddingLeft: isCollapsed ? '0' : '32px' }}
          title={isCollapsed ? "Sign Out" : undefined}
        >
          <LogOut className="w-5 h-5 flex-shrink-0 text-white/80" />
          {!isCollapsed && (
            <span className="font-bold text-[15px] tracking-wide whitespace-nowrap overflow-hidden transition-all duration-300">
              Sign Out
            </span>
          )}
        </Link>
      </div>
    </aside>
  );
}
