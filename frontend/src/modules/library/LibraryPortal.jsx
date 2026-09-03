import React, { useState } from 'react';

const mockBooks = [
  {
    id: 1,
    title: "Clean Architecture & Software Design",
    author: "Robert C. Martin",
    isbn: "978-0134494166",
    location: "2nd Floor - Shelf 4B (CS Section)",
    categories: ["COMPUTER SCIENCE", "COURSE RESERVE"],
    available: 2,
    total: 3,
    image: "https://images.unsplash.com/photo-1555066931-4365d14bab8c?auto=format&fit=crop&q=80&w=200&h=200"
  },
  {
    id: 2,
    title: "Modern Web Architectures with React",
    author: "Alex Morgan",
    isbn: "978-1491950357",
    location: "3rd Floor - Shelf 2A (Web Lab)",
    categories: ["WEB ENGINEERING"],
    available: 2,
    total: 2,
    image: "https://images.unsplash.com/photo-1633356122544-f134324a6cee?auto=format&fit=crop&q=80&w=200&h=200"
  },
  {
    id: 3,
    title: "Database Management Systems 4th Ed.",
    author: "Raghu Ramakrishnan",
    isbn: "978-0072465631",
    location: "2nd Floor - Shelf 5C (DB Section)",
    categories: ["DATABASES", "COURSE RESERVE"],
    available: 1,
    total: 2,
    image: "https://images.unsplash.com/photo-1518770660439-4636190af475?auto=format&fit=crop&q=80&w=200&h=200"
  },
  {
    id: 4,
    title: "Data Structures and Algorithms in C++",
    author: "Michael T. Goodrich",
    isbn: "978-1118806771",
    location: "2nd Floor - Shelf 1A (CS Stacks)",
    categories: ["PROGRAMMING"],
    available: 1,
    total: 2,
    image: "https://images.unsplash.com/photo-1526379095098-d400fd0bfce8?auto=format&fit=crop&q=80&w=200&h=200"
  },
  {
    id: 5,
    title: "The C Programming Language 2nd Ed.",
    author: "Brian W. Kernighan & Dennis M. Ritchie",
    isbn: "978-0131103627",
    location: "1st Floor - Special Collections",
    categories: ["PROGRAMMING", "COURSE RESERVE"],
    available: 1,
    total: 1,
    image: "https://images.unsplash.com/photo-1550439062-609e1531270e?auto=format&fit=crop&q=80&w=200&h=200"
  }
];

const mockCirculation = [
  {
    id: 1,
    title: "Database Management Systems 4th Ed.",
    copyId: "CPY-DB301-01",
    author: "Raghu Ramakrishnan",
    borrower: "Juan Dela Cruz",
    borrowerId: "2024-00123-ST",
    overdueDate: "2026-08-10"
  },
  {
    id: 2,
    title: "Data Structures and Algorithms in C++",
    copyId: "CPY-DSA101-02",
    author: "Michael T. Goodrich",
    borrower: "Maria Santos",
    borrowerId: "2023-00912",
    overdueDate: "2026-07-24"
  }
];

const mockReserves = [
  {
    id: 1,
    course: "IT 311",
    status: "Active Reserve",
    title: "Database Management Systems 4th Ed.",
    type: "2-Hour In-Library Desk Reference",
    requester: "Juan Dela Cruz",
    role: "Student",
    date: "2026-08-01",
    note: "Required reference textbook for Midterm SQL Lab Exam."
  },
  {
    id: 2,
    course: "CS 102",
    status: "Active Reserve",
    title: "The C Programming Language 2nd Ed.",
    type: "Overnight Checkout Reserve",
    requester: "Prof. Maria Santos",
    role: "Teacher",
    date: "2026-07-28",
    note: "Core textbook for System Programming Lab."
  }
];

export default function LibraryPortal() {
  const [activeTab, setActiveTab] = useState('catalog');

  const userStr = localStorage.getItem('user');
  const user = userStr ? JSON.parse(userStr) : null;
  const isSuperAdmin = user?.role === 'Super Admin' || user?.role === 'super_admin' || user?.role === 'Admin' || user?.role === 'admin';

  return (
    <div className="w-full max-w-md lg:max-w-7xl mx-auto p-4 flex flex-col lg:flex-row gap-6 lg:gap-8 font-sans text-slate-800 pb-20">
      
      {/* Sidebar Area (Info, Stats, Nav) */}
      <div className="flex flex-col gap-4 w-full lg:w-[340px] xl:w-[380px] lg:shrink-0">
      
      {/* 1. Main Info Card */}
      <div className="bg-white rounded-[2rem] p-6 shadow-sm border border-gray-100">
        <div className="flex flex-wrap gap-2 mb-4">
          <div className="inline-block bg-[#8B1A24] text-white text-[10px] font-black tracking-widest uppercase px-3 py-1 rounded-md">
            Library & Circulation System
          </div>
        </div>
        
        <h1 className="text-[28px] leading-[1.1] font-extrabold text-[#0f172a] mb-3 tracking-tight">
          Library Catalog &<br/> Student Borrowing Portal
        </h1>
        
        <p className="text-gray-500 text-[13px] leading-relaxed mb-6 font-medium">
          Real-time physical copy tracking, online book renewal, hold requests, and course reserve allocations.
        </p>

        {/* Borrowing Rule Inner Card */}
        <div className="border border-gray-100 shadow-sm rounded-2xl p-5 relative overflow-hidden bg-white">
          <div className="absolute right-6 top-4 bottom-4 w-px bg-gray-100"></div>
          
          <div className="mb-4">
            <h3 className="text-[10px] font-bold text-gray-400 tracking-wider uppercase mb-1">Your Borrowing Rule</h3>
            <span className="inline-block bg-yellow-100/80 text-[#926522] text-[11px] font-extrabold px-2.5 py-0.5 rounded uppercase">
              {user?.role === 'Teacher' ? 'FACULTY' : user?.role === 'Student' ? 'STUDENT' : 'ADMIN'}
            </span>
          </div>

          <div className="mb-4">
            <h3 className="text-[10px] font-bold text-gray-400 tracking-wider uppercase mb-1">Loan Privilege Limit</h3>
            <p className="font-extrabold text-[#0f172a] text-[15px]">
              {user?.role === 'Teacher' ? 'Max 10 Books (30-Day Loan)' : user?.role === 'Student' ? 'Max 3 Books (7-Day Loan)' : 'Max 20 Books (60-Day Loan)'}
            </p>
          </div>

          <div>
            <h3 className="text-[10px] font-bold text-gray-400 tracking-wider uppercase mb-1">Overdue Late Fine</h3>
            <p className="font-extrabold text-[#0f172a] text-[15px]">
              {user?.role === 'Teacher' ? 'Exempt (Faculty/Admin)' : user?.role === 'Student' ? '₱10.00 / Day' : 'Exempt (Faculty/Admin)'}
            </p>
          </div>
        </div>
      </div>

      {/* 2. Stats Dashboard */}
      <div className="flex flex-col gap-3">
        {/* Total Titles */}
        <div className="bg-white rounded-[1.25rem] p-4 flex items-center gap-4 shadow-sm border border-gray-100">
          <div className="w-12 h-12 rounded-xl bg-gray-100 flex items-center justify-center text-gray-500">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" strokeWidth={2} stroke="currentColor" className="w-6 h-6">
              <path strokeLinecap="round" strokeLinejoin="round" d="M12 6.042A8.967 8.967 0 006 3.75c-1.052 0-2.062.18-3 .512v14.25A8.987 8.987 0 016 18c2.305 0 4.408.867 6 2.292m0-14.25a8.966 8.966 0 016-2.292c1.052 0 2.062.18 3 .512v14.25A8.987 8.987 0 0018 18a8.967 8.967 0 00-6 2.292m0-14.25v14.25" />
            </svg>
          </div>
          <div>
            <div className="text-[10px] font-bold text-gray-400 tracking-wider uppercase">Total Titles</div>
            <div className="text-xl font-extrabold text-[#0f172a]">5</div>
          </div>
        </div>

        {/* Available */}
        <div className="bg-white rounded-[1.25rem] p-4 flex items-center gap-4 shadow-sm border border-gray-100">
          <div className="w-12 h-12 rounded-xl bg-green-50 border border-green-100 flex items-center justify-center text-green-500">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" strokeWidth={2} stroke="currentColor" className="w-6 h-6">
              <path strokeLinecap="round" strokeLinejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
            </svg>
          </div>
          <div>
            <div className="text-[10px] font-bold text-gray-400 tracking-wider uppercase">Available</div>
            <div className="text-xl font-extrabold text-green-600">7 <span className="text-sm text-gray-400 font-bold">/ 10</span></div>
          </div>
        </div>

        {/* Checked Out */}
        <div className="bg-white rounded-[1.25rem] p-4 flex items-center gap-4 shadow-sm border border-gray-100">
          <div className="w-12 h-12 rounded-xl bg-orange-50 border border-orange-100 flex items-center justify-center text-orange-400">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" strokeWidth={2} stroke="currentColor" className="w-6 h-6">
              <path strokeLinecap="round" strokeLinejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z" />
            </svg>
          </div>
          <div>
            <div className="text-[10px] font-bold text-gray-400 tracking-wider uppercase">Checked Out</div>
            <div className="text-xl font-extrabold text-orange-500">2</div>
          </div>
        </div>

        {/* Overdue */}
        {isSuperAdmin && (
          <div className="bg-white rounded-[1.25rem] p-4 flex items-center gap-4 shadow-sm border border-gray-100">
            <div className="w-12 h-12 rounded-xl bg-red-50 border border-red-100 flex items-center justify-center text-red-500">
              <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" strokeWidth={2} stroke="currentColor" className="w-6 h-6">
                <path strokeLinecap="round" strokeLinejoin="round" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
              </svg>
            </div>
            <div>
              <div className="text-[10px] font-bold text-gray-400 tracking-wider uppercase">Overdue Copies</div>
              <div className="text-xl font-extrabold text-red-600">2</div>
            </div>
          </div>
        )}

        {/* Total Fines */}
        {isSuperAdmin && (
          <div className="bg-white rounded-[1.25rem] p-4 flex items-center gap-4 shadow-sm border border-gray-100">
            <div className="w-12 h-12 rounded-xl bg-red-50 border border-red-100 flex items-center justify-center text-red-500">
              <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" strokeWidth={2} stroke="currentColor" className="w-6 h-6">
                <path strokeLinecap="round" strokeLinejoin="round" d="M12 6v12m-3-2.818l.879.659c1.171.879 3.07.879 4.242 0 1.172-.879 1.172-2.303 0-3.182C13.536 12.219 12.768 12 12 12c-.725 0-1.45-.22-2.003-.659-1.106-.879-1.106-2.303 0-3.182s2.9-.879 4.006 0l.415.33M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
              </svg>
            </div>
            <div>
              <div className="text-[10px] font-bold text-gray-400 tracking-wider uppercase">Total System Fines</div>
              <div className="text-xl font-extrabold text-[#8B1A24]">₱150.00</div>
            </div>
          </div>
        )}
      </div>

      {/* 3. Navigation Buttons & Admin Quick Actions Card */}
      {/* 3. Navigation Buttons & Admin Quick Actions Card */}
      <div className="bg-white rounded-[1.5rem] p-3.5 shadow-sm border border-gray-100 flex flex-col gap-3.5">
        <div className="flex gap-2.5">
          <button 
            onClick={() => setActiveTab('catalog')}
            className={`flex-1 flex flex-col items-center justify-center py-3.5 px-2 rounded-[1.25rem] transition-all duration-200 ${activeTab === 'catalog' ? 'bg-[#1e293b] text-white shadow-md scale-[1.02]' : 'bg-transparent text-[#1e293b] hover:bg-gray-50 border border-gray-100'}`}
          >
            <div className="flex items-center gap-1.5 mb-2">
              <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" strokeWidth={2.5} stroke="currentColor" className="w-[18px] h-[18px]">
                <path strokeLinecap="round" strokeLinejoin="round" d="M21 21l-5.197-5.197m0 0A7.5 7.5 0 105.196 5.196a7.5 7.5 0 0010.607 10.607z" />
              </svg>
              <span className={`text-[10px] font-bold w-4 h-4 flex items-center justify-center rounded-full ${activeTab === 'catalog' ? 'bg-white/20 text-white' : 'bg-gray-100 text-gray-500'}`}>5</span>
            </div>
            <span className="text-[12px] font-extrabold text-center leading-tight">Catalog<br/>Search</span>
          </button>

          <button 
            onClick={() => setActiveTab('borrowing')}
            className={`flex-1 flex flex-col items-center justify-center py-3.5 px-2 rounded-[1.25rem] transition-all duration-200 ${activeTab === 'borrowing' ? 'bg-[#1e293b] text-white shadow-md scale-[1.02]' : 'bg-transparent text-[#1e293b] hover:bg-gray-50 border border-gray-100'}`}
          >
            <div className="flex items-center gap-1.5 mb-2">
              <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" strokeWidth={2.5} stroke="currentColor" className={`w-[18px] h-[18px] ${activeTab === 'borrowing' ? 'text-white' : 'text-orange-400'}`}>
                <path strokeLinecap="round" strokeLinejoin="round" d="M17.593 3.322c1.1.128 1.907 1.077 1.907 2.185V21L12 17.25 4.5 21V5.507c0-1.108.806-2.057 1.907-2.185a48.507 48.507 0 0111.186 0z" />
              </svg>
              <span className={`text-[10px] font-bold w-4 h-4 flex items-center justify-center rounded-full ${activeTab === 'borrowing' ? 'bg-white/20 text-white' : 'bg-gray-100 text-gray-700'}`}>0</span>
            </div>
            <span className="text-[12px] font-extrabold text-center leading-tight">My<br/>Borrowing</span>
          </button>

          <button 
            onClick={() => setActiveTab('reserves')}
            className={`flex-1 flex flex-col items-center justify-center py-3.5 px-2 rounded-[1.25rem] transition-all duration-200 ${activeTab === 'reserves' ? 'bg-[#1e293b] text-white shadow-md scale-[1.02]' : 'bg-transparent text-[#1e293b] hover:bg-gray-50 border border-gray-100'}`}
          >
            <div className="flex items-center gap-1.5 mb-2">
              <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" strokeWidth={2.5} stroke="currentColor" className={`w-[18px] h-[18px] ${activeTab === 'reserves' ? 'text-white' : 'text-blue-400'}`}>
                <path strokeLinecap="round" strokeLinejoin="round" d="M12 2.25L2.25 7.5l9.75 5.25L21.75 7.5 12 2.25zM2.25 12l9.75 5.25L21.75 12M2.25 16.5l9.75 5.25L21.75 16.5" />
              </svg>
              <span className={`text-[10px] font-bold w-4 h-4 flex items-center justify-center rounded-full ${activeTab === 'reserves' ? 'bg-white/20 text-white' : 'bg-gray-100 text-gray-700'}`}>2</span>
            </div>
            <span className="text-[12px] font-extrabold text-center leading-tight">Course<br/>Reserves</span>
          </button>
        </div>
        
        {/* Dashed Divider */}
        {isSuperAdmin && (
          <>
            <div className="w-full border-t-2 border-dashed border-gray-100"></div>

            <div className="flex gap-2.5">
          <button 
            onClick={() => setActiveTab('circulation')}
            className={`flex-1 flex flex-col items-center justify-center py-3.5 px-2 rounded-[1.25rem] transition-all duration-200 ${activeTab === 'circulation' ? 'bg-[#1e293b] text-white shadow-md scale-[1.02]' : 'bg-transparent text-[#1e293b] hover:bg-gray-50 border border-gray-100'}`}
          >
            <div className="flex items-center gap-1.5 mb-2">
              <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" strokeWidth={2.5} stroke="currentColor" className={`w-[18px] h-[18px] ${activeTab === 'circulation' ? 'text-white' : 'text-green-500'}`}>
                <path strokeLinecap="round" strokeLinejoin="round" d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0l3.181 3.183a8.25 8.25 0 0013.803-3.7M4.031 9.865a8.25 8.25 0 0113.803-3.7l3.181 3.182m0-4.991v4.99" />
              </svg>
              <span className={`text-[10px] font-bold w-4 h-4 flex items-center justify-center rounded-full ${activeTab === 'circulation' ? 'bg-white/20 text-white' : 'bg-gray-100 text-gray-500'}`}>2</span>
            </div>
            <span className="text-[11px] font-extrabold text-center leading-tight">Circulation<br/>Desk</span>
          </button>

          <button 
            onClick={() => setActiveTab('fines')}
            className={`flex-1 flex flex-col items-center justify-center py-3.5 px-2 rounded-[1.25rem] transition-all duration-200 ${activeTab === 'fines' ? 'bg-[#1e293b] text-white shadow-md scale-[1.02]' : 'bg-transparent text-[#1e293b] hover:bg-gray-50 border border-gray-100'}`}
          >
            <div className="flex items-center gap-1.5 mb-2">
              <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" strokeWidth={2.5} stroke="currentColor" className={`w-[18px] h-[18px] ${activeTab === 'fines' ? 'text-white' : 'text-red-500'}`}>
                <path strokeLinecap="round" strokeLinejoin="round" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
              </svg>
              <span className={`text-[10px] font-bold w-4 h-4 flex items-center justify-center rounded-full ${activeTab === 'fines' ? 'bg-white/20 text-white' : 'bg-gray-100 text-gray-500'}`}>2</span>
            </div>
            <span className="text-[11px] font-extrabold text-center leading-tight">Admin Fines<br/>& Queue</span>
          </button>

          <button 
            onClick={() => setActiveTab('add_title')}
            className={`flex-1 flex flex-col items-center justify-center py-3.5 px-1 rounded-[1.25rem] transition-all duration-200 ${activeTab === 'add_title' ? 'bg-[#1e293b] text-white shadow-md scale-[1.02]' : 'bg-transparent text-[#1e293b] hover:bg-gray-50 border border-gray-100'}`}
          >
            <div className="flex items-center gap-1.5 mb-2">
              <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" strokeWidth={2.5} stroke="currentColor" className={`w-[18px] h-[18px] ${activeTab === 'add_title' ? 'text-white' : 'text-yellow-500'}`}>
                <path strokeLinecap="round" strokeLinejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
              </svg>
            </div>
            <span className="text-[11px] font-extrabold text-center leading-tight">Add Title<br/>& Copies</span>
          </button>
            </div>
          </>
        )}
      </div>
      </div>

      {/* Main Content Area (Active Tab Content) */}
      <div className="flex flex-col gap-4 w-full lg:flex-1">

      {activeTab === 'catalog' && (
        <>
          {/* 5. Search & Filter Section */}
          <div className="bg-white rounded-[2rem] p-5 shadow-sm border border-gray-100 mt-2">
        <div className="flex items-center gap-2 mb-4 border-b border-gray-100 pb-3">
          <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" strokeWidth={2.5} stroke="currentColor" className="w-4 h-4 text-[#8B1A24]">
            <path strokeLinecap="round" strokeLinejoin="round" d="M21 21l-5.197-5.197m0 0A7.5 7.5 0 105.196 5.196a7.5 7.5 0 0010.607 10.607z" />
          </svg>
          <h2 className="text-[11px] font-extrabold text-[#1e293b] tracking-wider uppercase">Library Catalog Search & Disco...</h2>
        </div>

        <div className="mb-4">
          <label className="block text-[10px] font-extrabold text-[#1e293b] tracking-wider uppercase mb-2">
            Search Book Titles, Authors, ISBNs, or Call Numbers
          </label>
          <div className="relative">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" strokeWidth={2} stroke="currentColor" className="w-4 h-4 text-gray-400 absolute left-3 top-1/2 -translate-y-1/2">
              <path strokeLinecap="round" strokeLinejoin="round" d="M21 21l-5.197-5.197m0 0A7.5 7.5 0 105.196 5.196a7.5 7.5 0 0010.607 10.607z" />
            </svg>
            <input 
              type="text" 
              placeholder="Search title, author, ISBN, locatio..."
              className="w-full bg-gray-50 border border-gray-200 rounded-xl py-2.5 pl-9 pr-4 text-sm focus:outline-none focus:ring-2 focus:ring-gray-200 text-gray-700"
            />
          </div>
        </div>

        <div>
          <label className="block text-[10px] font-extrabold text-[#1e293b] tracking-wider uppercase mb-2">
            Filter by Category:
          </label>
          <div className="flex flex-wrap gap-2">
            <button className="bg-[#8B1A24] text-white text-[10px] font-extrabold px-3 py-1.5 rounded-full">All</button>
            <button className="bg-gray-100 text-gray-600 hover:bg-gray-200 text-[10px] font-extrabold px-3 py-1.5 rounded-full">IT & Computer Science</button>
            <button className="bg-gray-100 text-gray-600 hover:bg-gray-200 text-[10px] font-extrabold px-3 py-1.5 rounded-full">Mathematics & Sciences</button>
            <button className="bg-gray-100 text-gray-600 hover:bg-gray-200 text-[10px] font-extrabold px-3 py-1.5 rounded-full">General Education</button>
            <button className="bg-gray-100 text-gray-600 hover:bg-gray-200 text-[10px] font-extrabold px-3 py-1.5 rounded-full">Literature & Arts</button>
            <button className="bg-gray-100 text-gray-600 hover:bg-gray-200 text-[10px] font-extrabold px-3 py-1.5 rounded-full">Research & Journals</button>
          </div>
        </div>
      </div>

      {/* 6. Book List */}
      <div className="flex flex-col gap-3 mt-2">
        {mockBooks.map(book => (
          <div key={book.id} className="bg-white rounded-[1.5rem] p-4 shadow-sm border border-gray-100 flex flex-col gap-4">
            {/* Top row: Image & Info */}
            <div className="flex gap-4">
              <div className="w-[88px] h-[104px] shrink-0 rounded-xl overflow-hidden shadow-sm border border-gray-100">
                <img src={book.image} alt={book.title} className="w-full h-full object-cover" />
              </div>
              <div className="flex flex-col flex-1">
                <div className="flex flex-wrap gap-1.5 mb-2">
                  {book.categories.map((cat, i) => (
                    <span key={i} className={`text-[8px] font-extrabold uppercase px-1.5 py-0.5 rounded ${cat === 'COURSE RESERVE' ? 'bg-yellow-100 text-yellow-800' : 'bg-blue-50 text-blue-700'}`}>
                      {cat}
                    </span>
                  ))}
                </div>
                <h3 className="font-extrabold text-[13px] text-[#0f172a] leading-tight mb-1">{book.title}</h3>
                <p className="text-[11px] text-gray-500 font-medium mb-1">by {book.author}</p>
                <p className="text-[10px] text-gray-400 font-medium mb-2">ISBN: {book.isbn}</p>
                <div className="flex items-start gap-1 text-gray-500 mt-auto">
                  <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" strokeWidth={2} stroke="currentColor" className="w-3 h-3 shrink-0 mt-0.5 text-[#8B1A24]">
                    <path strokeLinecap="round" strokeLinejoin="round" d="M15 10.5a3 3 0 11-6 0 3 3 0 016 0z" />
                    <path strokeLinecap="round" strokeLinejoin="round" d="M19.5 10.5c0 7.142-7.5 11.25-7.5 11.25S4.5 17.642 4.5 10.5a7.5 7.5 0 1115 0z" />
                  </svg>
                  <span className="text-[10px] font-bold leading-tight text-[#1e293b]">{book.location}</span>
                </div>
              </div>
            </div>

            {/* Bottom Row: Availability & Button */}
            <div className="flex items-center justify-between border-t border-gray-100 pt-3">
              <div className="bg-green-50 text-green-700 border border-green-100 px-2.5 py-1 rounded-full flex items-center gap-1.5">
                <div className="w-1.5 h-1.5 bg-green-500 rounded-full"></div>
                <span className="text-[10px] font-extrabold">{book.available} of {book.total} Copies Available</span>
              </div>
              <button className="bg-[#1e293b] text-white text-[11px] font-extrabold px-3 py-1.5 rounded-full flex items-center gap-1 hover:bg-[#0f172a] transition-colors">
                View Copies
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" strokeWidth={3} stroke="currentColor" className="w-3 h-3">
                  <path strokeLinecap="round" strokeLinejoin="round" d="M13.5 4.5L21 12m0 0l-7.5 7.5M21 12H3" />
                </svg>
              </button>
            </div>
          </div>
        ))}
      </div>
        </>
      )}

      {activeTab === 'circulation' && (
        <div className="bg-white rounded-[2rem] p-6 shadow-sm border border-gray-100 mt-2">
          <h2 className="text-xl font-extrabold text-[#0f172a] mb-2 tracking-tight">Circulation Log & Active Loans</h2>
          <p className="text-gray-500 text-[12px] leading-relaxed mb-4 font-medium">
            Live barcode tracking of physical copies checked out to students and faculty.
          </p>
          <div className="flex justify-center mb-6">
            <span className="bg-gray-100 text-gray-600 text-[10px] font-extrabold px-3 py-1.5 rounded-md uppercase tracking-wider">
              Circulation Desk #01
            </span>
          </div>

          <div className="flex flex-col gap-4">
            {mockCirculation.map(item => (
              <div key={item.id} className="border border-gray-200 rounded-[1.25rem] p-4 flex flex-col gap-3">
                <div className="flex justify-between items-start gap-2">
                  <div>
                    <h3 className="font-extrabold text-[13px] text-[#0f172a] leading-tight mb-1">{item.title}</h3>
                    <p className="text-[11px] text-gray-500 font-medium">by {item.author}</p>
                  </div>
                  <span className="border border-gray-200 bg-gray-50 text-gray-600 text-[9px] font-extrabold px-2 py-1 rounded shrink-0 font-mono">
                    {item.copyId}
                  </span>
                </div>
                
                <div className="w-full border-t border-gray-100 my-1"></div>

                <div className="flex flex-col gap-1.5">
                  <p className="text-[11px] text-gray-600 font-medium">
                    Borrower: <span className="font-extrabold text-[#0f172a]">{item.borrower}</span> <span className="text-gray-500">(ID: {item.borrowerId})</span>
                  </p>
                  <p className="text-[11px] font-extrabold text-[#e11d48]">
                    OVERDUE: {item.overdueDate}
                  </p>
                </div>

                <button className="w-full mt-1 bg-[#047857] text-white text-[12px] font-extrabold py-2.5 rounded-xl hover:bg-[#065f46] transition-colors">
                  Check-In Copy
                </button>
              </div>
            ))}
          </div>
        </div>
      )}

      {activeTab === 'reserves' && (
        <>
          <div className="bg-white rounded-[2rem] p-6 shadow-sm border border-gray-100 mt-2">
            <div className="flex items-start gap-2 mb-2">
              <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" strokeWidth={2.5} stroke="currentColor" className="w-5 h-5 shrink-0 mt-0.5 text-[#0284c7]">
                <path strokeLinecap="round" strokeLinejoin="round" d="M12 2.25L2.25 7.5l9.75 5.25L21.75 7.5 12 2.25zM2.25 12l9.75 5.25L21.75 12M2.25 16.5l9.75 5.25L21.75 16.5" />
              </svg>
              <h2 className="text-[17px] font-extrabold text-[#0f172a] leading-tight">Course Reserve Books & Syllabus References</h2>
            </div>
            <p className="text-gray-500 text-[12px] leading-relaxed mb-5 font-medium">
              Textbooks set aside by professors for 2-Hour In-Library Desk Reference or Overnight study.
            </p>
            <div className="flex justify-center">
              <button className="bg-[#8B1A24] text-white text-[12px] font-extrabold py-2.5 px-5 rounded-xl hover:bg-[#6b141c] transition-colors flex items-center gap-1.5">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" strokeWidth={3} stroke="currentColor" className="w-3.5 h-3.5">
                  <path strokeLinecap="round" strokeLinejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                </svg>
                Request Course Reserve
              </button>
            </div>
          </div>

          <div className="bg-white rounded-[2rem] p-5 shadow-sm border border-gray-100 mt-3">
            <h3 className="font-extrabold text-[#0f172a] text-[13px] mb-3 leading-tight">Registered Course Reserves & Professor Allocations (2)</h3>
            <div className="w-full border-t border-gray-100 mb-4"></div>
            <div className="flex flex-col gap-3">
              {mockReserves.map(reserve => (
                <div key={reserve.id} className="border border-[#bae6fd] rounded-[1.25rem] p-4 bg-white shadow-sm flex flex-col gap-2">
                  <div className="flex gap-2 mb-1">
                    <span className="bg-[#0369a1] text-white text-[9px] font-extrabold px-2 py-0.5 rounded-full uppercase tracking-wider">
                      COURSE: {reserve.course}
                    </span>
                    <span className="border border-green-300 bg-green-50 text-green-700 text-[9px] font-extrabold px-2 py-0.5 rounded-full">
                      {reserve.status}
                    </span>
                  </div>
                  <h4 className="font-extrabold text-[13px] text-[#0f172a] leading-tight">{reserve.title}</h4>
                  
                  <div className="text-[11px] text-gray-500 mt-1 flex flex-col gap-1">
                    <p>Reserve Type: <span className="font-extrabold text-[#0f172a]">{reserve.type}</span></p>
                    <p>Requested by: <span className="font-extrabold text-[#0f172a]">{reserve.requester}</span> <span className="text-gray-400">({reserve.role}) on {reserve.date}</span></p>
                  </div>

                  <div className="border border-gray-100 rounded-xl p-3 text-[11px] text-gray-500 italic mt-2 bg-gray-50">
                    "{reserve.note}"
                  </div>
                </div>
              ))}
            </div>
          </div>
        </>
      )}

      {activeTab === 'borrowing' && (
        <div className="flex flex-col gap-3 mt-2">
          {/* Card 1: Active Loans */}
          <div className="bg-white rounded-[2rem] p-6 shadow-sm border border-gray-100 text-center">
            <div className="flex items-center justify-center gap-2 mb-2">
              <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" strokeWidth={2.5} stroke="currentColor" className="w-5 h-5 text-[#8B1A24]">
                <path strokeLinecap="round" strokeLinejoin="round" d="M17.593 3.322c1.1.128 1.907 1.077 1.907 2.185V21L12 17.25 4.5 21V5.507c0-1.108.806-2.057 1.907-2.185a48.507 48.507 0 0111.186 0z" />
              </svg>
              <h2 className="text-[17px] font-extrabold text-[#0f172a] leading-tight">Your Active Book Loans (0)</h2>
            </div>
            <p className="text-gray-500 text-[11px] leading-relaxed mb-4 font-medium">
              Books currently checked out under your student profile (2012-00000-SYS).
            </p>
            <div className="inline-block bg-gray-100 text-[#0f172a] text-[10px] font-extrabold px-3 py-1.5 rounded-lg mb-5">
              Max Allowed: 0 / 20 Books
            </div>

            <div className="border border-gray-100 rounded-[1.25rem] p-6 flex flex-col items-center justify-center bg-gray-50/30">
              <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" strokeWidth={1.5} stroke="currentColor" className="w-8 h-8 text-gray-400 mb-3">
                <path strokeLinecap="round" strokeLinejoin="round" d="M12 6.042A8.967 8.967 0 006 3.75c-1.052 0-2.062.18-3 .512v14.25A8.987 8.987 0 016 18c2.305 0 4.408.867 6 2.292m0-14.25a8.966 8.966 0 016-2.292c1.052 0 2.062.18 3 .512v14.25A8.987 8.987 0 0018 18a8.967 8.967 0 00-6 2.292m0-14.25v14.25" />
              </svg>
              <h3 className="font-extrabold text-[#0f172a] text-[13px] mb-1">No active book loans at this time.</h3>
              <p className="text-gray-500 text-[11px] font-medium leading-relaxed max-w-[250px]">
                Browse the catalog to find available physical books for research or coursework.
              </p>
            </div>
          </div>

          {/* Card 2: Fines Ledger */}
          <div className="bg-white rounded-[2rem] p-6 shadow-sm border border-gray-100 text-center">
            <div className="flex items-center justify-center gap-1.5 mb-2">
              <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" strokeWidth={2.5} stroke="currentColor" className="w-5 h-5 text-[#8B1A24]">
                <path strokeLinecap="round" strokeLinejoin="round" d="M12 6v12m-3-2.818l.879.659c1.171.879 3.07.879 4.242 0 1.172-.879 1.172-2.303 0-3.182C13.536 12.219 12.768 12 12 12c-.725 0-1.45-.22-2.003-.659-1.106-.879-1.106-2.303 0-3.182s2.9-.879 4.006 0l.415.33M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
              </svg>
              <h2 className="text-[17px] font-extrabold text-[#0f172a] leading-tight">Library Fines & Clearance Ledger</h2>
            </div>
            <div className="inline-block bg-[#d1fae5] text-[#065f46] text-[10px] font-extrabold px-3 py-1 rounded-md mb-4 uppercase tracking-wider">
              Clear Account
            </div>
            
            <div className="w-full border-t border-gray-100 mb-4"></div>

            <div className="border border-gray-100 rounded-[1.25rem] p-5 text-left bg-white mb-3 shadow-sm">
              <div className="flex justify-between items-center mb-3">
                <span className="text-[12px] font-extrabold text-gray-500">Unpaid Library Fines Balance:</span>
                <span className="text-[18px] font-extrabold text-[#8B1A24]">₱0.00</span>
              </div>
              <p className="text-gray-500 text-[10px] font-medium leading-relaxed">
                Calculated @ ₱10.00 / overdue calendar day per unreturned title. Fines must be cleared for graduation & semestral clearance.
              </p>
            </div>

            <div className="border border-[#bae6fd] bg-[#f0f9ff] rounded-[1rem] p-4 text-left flex gap-3 items-start">
              <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" strokeWidth={2} stroke="currentColor" className="w-5 h-5 shrink-0 text-[#0284c7]">
                <path strokeLinecap="round" strokeLinejoin="round" d="M11.25 11.25l.041-.02a.75.75 0 011.063.852l-.708 2.836a.75.75 0 001.063.853l.041-.021M21 12a9 9 0 11-18 0 9 9 0 0118 0zm-9-3.75h.008v.008H12V8.25z" />
              </svg>
              <p className="text-[10px] text-[#0369a1] font-medium leading-relaxed">
                Payments can be settled at the University Cashier (Ground Floor Main Admin) or through online GCash/Bank Deposit clearance verification.
              </p>
            </div>
          </div>

          {/* Card 3: Hold Requests */}
          <div className="bg-white rounded-[2rem] p-6 shadow-sm border border-gray-100 text-center">
            <div className="flex items-center justify-center gap-2 mb-2">
              <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" strokeWidth={2.5} stroke="currentColor" className="w-5 h-5 text-orange-500">
                <path strokeLinecap="round" strokeLinejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z" />
              </svg>
              <h2 className="text-[17px] font-extrabold text-[#0f172a] leading-tight">Your Book Hold Requests (0)</h2>
            </div>
            <p className="text-gray-500 text-[10px] font-extrabold uppercase tracking-wider mb-4">
              Queue Status
            </p>
            
            <div className="w-full border-t border-gray-100 mb-4"></div>

            <div className="border border-gray-100 rounded-[1.25rem] p-6 flex flex-col items-center justify-center bg-gray-50/30 mb-3">
              <h3 className="font-extrabold text-[#0f172a] text-[13px] mb-1">No active book hold requests.</h3>
              <p className="text-gray-500 text-[11px] font-medium leading-relaxed">
                When a book is out of stock, click "View Copies" to join the hold queue.
              </p>
            </div>

            <div className="border border-yellow-200 bg-[#fffbeb] rounded-[1rem] p-4 text-left flex gap-3 items-start">
              <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" strokeWidth={2} stroke="currentColor" className="w-5 h-5 shrink-0 text-[#b45309]">
                <path strokeLinecap="round" strokeLinejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12c0 1.268-.63 2.39-1.593 3.068a3.745 3.745 0 01-1.043 3.296 3.745 3.745 0 01-3.296 1.043A3.745 3.745 0 0112 21c-1.268 0-2.39-.63-3.068-1.593a3.746 3.746 0 01-3.296-1.043 3.745 3.745 0 01-1.043-3.296A3.745 3.745 0 013 12c0-1.268.63-2.39 1.593-3.068a3.745 3.745 0 011.043-3.296 3.746 3.746 0 013.296-1.043A3.746 3.746 0 0112 3c1.268 0 2.39.63 3.068 1.593a3.746 3.746 0 013.296 1.043 3.746 3.746 0 011.043 3.296A3.745 3.745 0 0121 12z" />
              </svg>
              <p className="text-[10px] text-[#92400e] font-medium leading-relaxed">
                Books held under "Ready for Pickup" are reserved at the Circulation Desk for 48 hours before proceeding to the next student in queue.
              </p>
            </div>
          </div>
        </div>
      )}

      {activeTab === 'fines' && (
        <div className="flex flex-col gap-3 mt-2">
          {/* Hold Request Queue Card */}
          <div className="bg-white rounded-[2rem] p-6 shadow-sm border border-gray-100">
            <div className="flex justify-between items-start mb-5">
              <div>
                <h2 className="text-[17px] font-extrabold text-[#0f172a] leading-tight mb-1">Hold Request Queue</h2>
                <p className="text-gray-500 text-[11px] font-medium leading-relaxed max-w-[200px]">
                  Reserves queue placement when stock is 0.
                </p>
              </div>
              <div className="border border-yellow-300 bg-[#fffbeb] text-[#92400e] px-3 py-1.5 rounded-lg flex flex-col items-center justify-center shrink-0">
                <span className="text-[13px] font-extrabold leading-none mb-0.5">2</span>
                <span className="text-[10px] font-extrabold leading-none">Requests</span>
              </div>
            </div>

            <div className="flex flex-col gap-3">
              {/* Request 1 */}
              <div className="border border-gray-200 rounded-[1.25rem] p-4 bg-white flex flex-col gap-3 shadow-sm">
                <div className="flex gap-3">
                  <div className="bg-[#8B1A24] text-white rounded-lg w-[42px] h-[42px] flex flex-col items-center justify-center shrink-0">
                    <span className="text-[9px] font-extrabold uppercase leading-tight">POS</span>
                    <span className="text-[13px] font-extrabold leading-tight">#1</span>
                  </div>
                  <div>
                    <h3 className="font-extrabold text-[13px] text-[#0f172a] leading-tight mb-1">Clean Code: A Handbook of Agile Software Craftsmanship</h3>
                    <p className="text-[11px] text-gray-500 font-medium">
                      Requested by: <span className="font-extrabold text-[#0f172a]">Juan Dela Cruz</span> (Student) on 2026-08-01
                    </p>
                  </div>
                </div>
                <div className="flex justify-center mt-1">
                  <button className="bg-gray-100 text-[#1e293b] text-[11px] font-extrabold px-5 py-2 rounded-xl hover:bg-gray-200 transition-colors">
                    Cancel Hold
                  </button>
                </div>
              </div>

              {/* Request 2 */}
              <div className="border border-gray-200 rounded-[1.25rem] p-4 bg-white flex flex-col gap-3 shadow-sm">
                <div className="flex gap-3">
                  <div className="bg-[#8B1A24] text-white rounded-lg w-[42px] h-[42px] flex flex-col items-center justify-center shrink-0">
                    <span className="text-[9px] font-extrabold uppercase leading-tight">POS</span>
                    <span className="text-[13px] font-extrabold leading-tight">#2</span>
                  </div>
                  <div>
                    <h3 className="font-extrabold text-[13px] text-[#0f172a] leading-tight mb-1">Data Structures and Algorithms in C++</h3>
                    <p className="text-[11px] text-gray-500 font-medium">
                      Requested by: <span className="font-extrabold text-[#0f172a]">Prof. Maria Santos</span> (Teacher) on 2026-08-02
                    </p>
                  </div>
                </div>
                <div className="flex justify-center mt-1">
                  <button className="bg-gray-100 text-[#1e293b] text-[11px] font-extrabold px-5 py-2 rounded-xl hover:bg-gray-200 transition-colors">
                    Cancel Hold
                  </button>
                </div>
              </div>
            </div>
          </div>

          {/* Unpaid Library Fines Card */}
          <div className="bg-white rounded-[2rem] p-6 shadow-sm border border-gray-100">
            <h2 className="text-[17px] font-extrabold text-[#0f172a] leading-tight mb-1">Unpaid Library Fines</h2>
            <p className="text-gray-500 text-[11px] font-medium leading-relaxed mb-4">
              Late return fees calculated @ ₱10.00/day for students.
            </p>
            <div className="flex justify-center mb-5">
              <span className="border border-pink-200 bg-pink-50 text-[#be123c] text-[10px] font-extrabold px-4 py-1.5 rounded-lg tracking-wider">
                ₱10.00 / Overdue Day
              </span>
            </div>

            <div className="flex flex-col gap-3">
              {/* Fine 1 */}
              <div className="border border-gray-200 rounded-[1.25rem] p-4 bg-white shadow-sm flex flex-col gap-2">
                <div className="flex justify-between items-start gap-3">
                  <h3 className="font-extrabold text-[13px] text-[#0f172a] leading-tight flex-1">Data Structures and Algorithms in C++</h3>
                  <span className="font-extrabold text-[#8B1A24] text-[13px] shrink-0">₱100.00</span>
                </div>
                <p className="text-[11px] text-gray-500 font-medium">
                  Borrower: <span className="font-extrabold text-[#0f172a]">Maria Santos</span>
                </p>
                <div className="flex justify-between items-center mt-3 pt-3 border-t border-gray-100">
                  <span className="text-[10px] text-gray-400 font-bold font-mono">Ref #FINE-101</span>
                  <span className="text-[10px] font-extrabold text-[#8B1A24]">10 Days Overdue</span>
                </div>
              </div>

              {/* Fine 2 */}
              <div className="border border-gray-200 rounded-[1.25rem] p-4 bg-white shadow-sm flex flex-col gap-2">
                <div className="flex justify-between items-start gap-3">
                  <h3 className="font-extrabold text-[13px] text-[#0f172a] leading-tight flex-1">Introduction to Operating Systems</h3>
                  <span className="font-extrabold text-[#8B1A24] text-[13px] shrink-0">₱50.00</span>
                </div>
                <p className="text-[11px] text-gray-500 font-medium">
                  Borrower: <span className="font-extrabold text-[#0f172a]">Juan Dela Cruz</span>
                </p>
                <div className="flex justify-between items-center mt-3 pt-3 border-t border-gray-100">
                  <span className="text-[10px] text-gray-400 font-bold font-mono">Ref #FINE-102</span>
                  <span className="text-[10px] font-extrabold text-[#8B1A24]">5 Days Overdue</span>
                </div>
              </div>
            </div>
          </div>
        </div>
      )}

      {activeTab === 'add_title' && (
        <div className="bg-white rounded-[2rem] p-6 shadow-sm border border-gray-100 mt-2">
          <div className="mb-6">
            <span className="inline-block bg-[#fef3c7] text-[#92400e] text-[9px] font-extrabold uppercase px-2 py-1 rounded tracking-widest mb-3">
              Library Inventory Management
            </span>
            <h2 className="text-[20px] font-extrabold text-[#0f172a] leading-tight tracking-tight">Register New Title & Physical Copies</h2>
          </div>

          <div className="flex flex-col gap-4">
            {/* Book Title */}
            <div>
              <label className="block text-[11px] font-extrabold text-[#0f172a] mb-1.5">Book Title *</label>
              <input 
                type="text" 
                placeholder="e.g. Operating System Concepts 10th Ed."
                className="w-full border border-gray-200 rounded-xl py-2.5 px-3 text-[13px] focus:outline-none focus:ring-2 focus:ring-[#8B1A24]/20 focus:border-[#8B1A24] text-[#0f172a] placeholder-gray-400 font-medium"
              />
            </div>

            <div className="flex gap-4">
              {/* Author */}
              <div className="flex-1">
                <label className="block text-[11px] font-extrabold text-[#0f172a] mb-1.5">Author *</label>
                <input 
                  type="text" 
                  placeholder="e.g. Abraham Silberschatz"
                  className="w-full border border-gray-200 rounded-xl py-2.5 px-3 text-[13px] focus:outline-none focus:ring-2 focus:ring-[#8B1A24]/20 focus:border-[#8B1A24] text-[#0f172a] placeholder-gray-400 font-medium"
                />
              </div>

              {/* ISBN-13 */}
              <div className="flex-1">
                <label className="block text-[11px] font-extrabold text-[#0f172a] mb-1.5">ISBN-13 *</label>
                <input 
                  type="text" 
                  placeholder="e.g. 978-1118063330"
                  className="w-full border border-gray-200 rounded-xl py-2.5 px-3 text-[13px] focus:outline-none focus:ring-2 focus:ring-[#8B1A24]/20 focus:border-[#8B1A24] text-[#0f172a] placeholder-gray-400 font-medium"
                />
              </div>
            </div>

            <div className="flex gap-4">
              {/* Category */}
              <div className="flex-1">
                <label className="block text-[11px] font-extrabold text-[#0f172a] mb-1.5">Category</label>
                <div className="relative">
                  <select className="w-full border border-gray-200 rounded-xl py-2.5 px-3 text-[13px] focus:outline-none focus:ring-2 focus:ring-[#8B1A24]/20 focus:border-[#8B1A24] text-[#0f172a] font-extrabold appearance-none bg-white cursor-pointer">
                    <option>IT & Computer Science</option>
                    <option>Mathematics & Sciences</option>
                    <option>General Education</option>
                  </select>
                  <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" strokeWidth={3} stroke="currentColor" className="w-4 h-4 absolute right-3 top-1/2 -translate-y-1/2 text-[#0f172a] pointer-events-none">
                    <path strokeLinecap="round" strokeLinejoin="round" d="M19.5 8.25l-7.5 7.5-7.5-7.5" />
                  </svg>
                </div>
              </div>

              {/* Copy Quantity */}
              <div className="flex-1">
                <label className="block text-[11px] font-extrabold text-[#0f172a] mb-1.5">Copy Quantity</label>
                <input 
                  type="number" 
                  defaultValue={2}
                  className="w-full border border-gray-200 rounded-xl py-2.5 px-3 text-[13px] focus:outline-none focus:ring-2 focus:ring-[#8B1A24]/20 focus:border-[#8B1A24] text-[#0f172a] font-extrabold"
                />
              </div>
            </div>

            {/* Shelf Location */}
            <div>
              <label className="block text-[11px] font-extrabold text-[#0f172a] mb-1.5">Shelf Location</label>
              <input 
                type="text" 
                defaultValue="Floor 2 - Shelf CS-101"
                className="w-full border border-gray-200 rounded-xl py-2.5 px-3 text-[13px] focus:outline-none focus:ring-2 focus:ring-[#8B1A24]/20 focus:border-[#8B1A24] text-[#0f172a] font-medium"
              />
            </div>
          </div>

          <div className="flex justify-end gap-3 mt-6">
            <button className="px-5 py-2.5 border border-gray-200 rounded-xl text-[#0f172a] text-[11px] font-extrabold hover:bg-gray-50 transition-colors">
              Cancel
            </button>
            <button className="px-5 py-2.5 bg-[#8B1A24] text-white rounded-xl text-[11px] font-extrabold hover:bg-[#6b141c] transition-colors shadow-sm">
              Save Title & Register Copies
            </button>
          </div>
        </div>
      )}
      
      </div>
    </div>
  );
}
