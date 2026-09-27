import React, { useEffect } from 'react';
import { Link, useLocation } from 'react-router-dom';
import { Home, Calendar, Monitor, BookOpen, GraduationCap, Users, LogOut, X } from 'lucide-react';

export default function MobileNavDrawer({ isOpen, onClose, onLogout }) {
  const location = useLocation();

  useEffect(() => {
    if (isOpen) {
      document.body.style.overflow = 'hidden';
      const handleEscape = (e) => {
        if (e.key === 'Escape') onClose();
      };
      window.addEventListener('keydown', handleEscape);
      return () => {
        document.body.style.overflow = '';
        window.removeEventListener('keydown', handleEscape);
      };
    }
  }, [isOpen, onClose]);

  const navItems = [
    { path: '/', label: 'Home', icon: Home },
    { path: '/schedule', label: 'Schedule', icon: Calendar },
    { path: '/announcements', label: 'Announcements', icon: Monitor },
    { path: '/library', label: 'Library', icon: BookOpen },
    { path: '/student-info', label: 'Student Information', icon: GraduationCap },
    { path: '/faculty', label: 'Faculty Directory', icon: Users },
  ];

  if (!isOpen) return null;

  return (
    <div className="fixed inset-0 z-[100] lg:hidden">
      {/* Backdrop */}
      <div 
        className="fixed inset-0 bg-black/50 backdrop-blur-sm anim-fade-in" 
        onClick={onClose}
        aria-hidden="true"
      />

      {/* Drawer */}
      <div
        className="lib-drawer fixed inset-y-0 left-0 w-[280px] max-w-[85vw] bg-[#80172B] text-white shadow-2xl flex flex-col anim-slide-in-left"
        role="dialog"
        aria-modal="true"
        aria-label="Navigation menu"
      >
        <div className="flex items-center justify-between p-5 border-b border-[#651020]">
          <div className="relative flex items-center">
            <span className="font-extrabold text-[32px] tracking-tighter text-white font-sans leading-none drop-shadow-sm">
              ABC
            </span>
            <span className="ml-1.5 px-1.5 py-[2px] bg-[#601020] border border-white/50 text-white text-[9px] font-bold tracking-wider rounded uppercase flex items-center shadow-inner self-start mt-1">
              SCHOOL
            </span>
          </div>
          <button 
            onClick={onClose}
            className="p-2 text-white/80 hover:text-white hover:bg-white/10 rounded-full transition-colors"
            aria-label="Close navigation menu"
          >
            <X className="w-6 h-6" aria-hidden="true" />
          </button>
        </div>

        <nav className="flex-1 overflow-y-auto py-4 space-y-1.5" aria-label="Main navigation">
          {navItems.map((item) => {
            const isActive = location.pathname === item.path;
            return (
              <div key={item.path} className="relative">
                <Link
                  to={item.path}
                  onClick={onClose}
                  aria-current={isActive ? 'page' : undefined}
                  className={`flex items-center py-4 pl-8 pr-4 space-x-4 transition-all duration-300 ${
                    isActive
                      ? 'bg-[#182848] text-white rounded-r-2xl shadow-lg relative z-10 w-[calc(100%-20px)]'
                      : 'text-white/80 hover:bg-white/10 hover:text-white rounded-r-2xl'
                  }`}
                >
                  <item.icon className={`w-5 h-5 flex-shrink-0 ${isActive ? 'text-white' : 'text-white/80'}`} />
                  <span className="font-bold text-[15px] tracking-wide">{item.label}</span>
                </Link>
              </div>
            );
          })}
        </nav>

        <div className="p-4 border-t border-[#651020] pb-8">
          <button
            onClick={() => {
              onClose();
              if(onLogout) onLogout();
            }}
            className="flex items-center w-full py-4 pl-8 pr-4 space-x-4 text-white/80 hover:bg-white/10 hover:text-white rounded-r-2xl transition-all duration-300"
          >
            <LogOut className="w-5 h-5 flex-shrink-0" />
            <span className="font-bold text-[15px] tracking-wide">Sign Out</span>
          </button>
        </div>
      </div>
    </div>
  );
}
