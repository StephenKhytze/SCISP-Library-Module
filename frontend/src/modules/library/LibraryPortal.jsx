import React, { useState, useMemo } from 'react';

const initialBooks = [
  {
    id: 1,
    title: "Clean Architecture & Software Design",
    author: "Robert C. Martin",
    isbn: "978-0134494166",
    location: "2nd Floor - Shelf 4B (CS Section)",
    categories: ["COMPUTER SCIENCE", "COURSE RESERVE"],
    available: 2,
    total: 3,
    image: "https://images.unsplash.com/photo-1555066931-4365d14bab8c?auto=format&fit=crop&q=80&w=300&h=300"
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
    image: "https://images.unsplash.com/photo-1544716278-ca5e3f4abd8c?auto=format&fit=crop&q=80&w=300&h=300"
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
    image: "https://images.unsplash.com/photo-1526374965328-7f61d4dc18c5?auto=format&fit=crop&q=80&w=300&h=300"
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
    image: "https://images.unsplash.com/photo-1515879218367-8466d910aaa4?auto=format&fit=crop&q=80&w=300&h=300"
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
    image: "https://images.unsplash.com/photo-1532012164546-f432f2e3777a?auto=format&fit=crop&q=80&w=300&h=300"
  }
];

const mockCirculation = [
  {
    id: 1,
    title: "Database Management Systems 4th Ed.",
    copyId: "CPY-DB301-01",
    barcode: "BC: 8839203001",
    author: "Raghu Ramakrishnan",
    borrower: "Juan Dela Cruz",
    borrowerId: "2024-00123-ST",
    overdueDate: "2026-08-10"
  },
  {
    id: 2,
    title: "Data Structures and Algorithms in C++",
    copyId: "CPY-DSA101-02",
    barcode: "BC: 8839204002",
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

const mockHoldRequests = [
  {
    id: 1,
    pos: "#1",
    bookTitle: "Clean Code: A Handbook of Agile Software Craftsmanship",
    requester: "Juan Dela Cruz",
    role: "Student",
    date: "2026-08-01"
  },
  {
    id: 2,
    pos: "#2",
    bookTitle: "Data Structures and Algorithms in C++",
    requester: "Prof. Maria Santos",
    role: "Teacher",
    date: "2026-08-02"
  }
];

const mockFines = [
  {
    id: 1,
    refId: "FINE-101",
    borrowerName: "Maria Santos",
    bookTitle: "Data Structures and Algorithms in C++",
    daysLate: "10 Days",
    amount: "₱100.00"
  },
  {
    id: 2,
    refId: "FINE-102",
    borrowerName: "Juan Dela Cruz",
    bookTitle: "Introduction to Operating Systems",
    daysLate: "5 Days",
    amount: "₱50.00"
  }
];

export default function LibraryPortal() {
  const [activeTab, setActiveTab] = useState('catalog');
  const [searchQuery, setSearchQuery] = useState('');
  const [selectedCategory, setSelectedCategory] = useState('All');
  const [books, setBooks] = useState(initialBooks);

  // Modals
  const [selectedBook, setSelectedBook] = useState(null);
  const [showReserveModal, setShowReserveModal] = useState(false);
  const [showAddBookModal, setShowAddBookModal] = useState(false);

  // New Book Form State
  const [newBook, setNewBook] = useState({
    title: '',
    author: '',
    isbn: '',
    category: 'IT & Computer Science',
    copies: 2,
    location: 'Floor 2 - Shelf CS-101',
    isCourseReserve: false
  });

  const userStr = localStorage.getItem('user');
  const user = userStr ? JSON.parse(userStr) : null;
  const isSuperAdmin = !user || user?.role === 'Super Admin' || user?.role === 'super_admin' || user?.role === 'Admin' || user?.role === 'admin';

  // Filtered books logic
  const filteredBooks = useMemo(() => {
    return books.filter(book => {
      const q = searchQuery.toLowerCase();
      const matchesSearch =
        book.title.toLowerCase().includes(q) ||
        book.author.toLowerCase().includes(q) ||
        book.isbn.toLowerCase().includes(q) ||
        book.location.toLowerCase().includes(q);

      if (!matchesSearch) return false;

      if (selectedCategory === 'All') return true;
      if (selectedCategory === 'IT & Computer Science') {
        return book.categories.some(c => ['COMPUTER SCIENCE', 'WEB ENGINEERING', 'PROGRAMMING', 'DATABASES'].includes(c));
      }
      if (selectedCategory === 'General Education') {
        return book.categories.some(c => c === 'GENERAL EDUCATION' || c === 'COURSE RESERVE');
      }
      return book.categories.some(c => c.toUpperCase() === selectedCategory.toUpperCase());
    });
  }, [books, searchQuery, selectedCategory]);

  const handleAddBook = (e) => {
    e.preventDefault();
    if (!newBook.title || !newBook.author || !newBook.isbn) {
      alert('Please fill in required fields (Title, Author, ISBN).');
      return;
    }

    const categories = [newBook.category.toUpperCase()];
    if (newBook.isCourseReserve) categories.push('COURSE RESERVE');

    const created = {
      id: Date.now(),
      title: newBook.title,
      author: newBook.author,
      isbn: newBook.isbn,
      location: newBook.location,
      categories,
      available: parseInt(newBook.copies, 10) || 1,
      total: parseInt(newBook.copies, 10) || 1,
      image: "https://images.unsplash.com/photo-1544716278-ca5e3f4abd8c?auto=format&fit=crop&q=80&w=300&h=300"
    };

    setBooks([created, ...books]);
    setNewBook({
      title: '',
      author: '',
      isbn: '',
      category: 'IT & Computer Science',
      copies: 2,
      location: 'Floor 2 - Shelf CS-101',
      isCourseReserve: false
    });
    setShowAddBookModal(false);
    alert(`Book "${created.title}" successfully added to the catalog!`);
    setActiveTab('catalog');
  };

  const categoriesList = [
    'All',
    'IT & Computer Science',
    'Mathematics & Sciences',
    'General Education',
    'Literature & Arts',
    'Research & Journals'
  ];

  return (
    <div className="w-full font-sans text-slate-800 pb-20">

      {/* ========================================================================= */}
      {/* 📱 MOBILE VIEW (< lg): Retains Exact Original Mobile Layout               */}
      {/* ========================================================================= */}
      <div className="flex flex-col gap-6 w-full max-w-md mx-auto p-4 lg:hidden">

        {/* 1. Main Info Card */}
        <div className="bg-white rounded-[2rem] p-6 shadow-sm border border-gray-100">
          <div className="flex flex-wrap gap-2 mb-4">
            <div className="inline-block bg-[#8B1A24] text-white text-[10px] font-black tracking-widest uppercase px-3 py-1 rounded-md">
              Library & Circulation System
            </div>
          </div>

          <h1 className="text-[28px] leading-[1.1] font-extrabold text-[#0f172a] mb-3 tracking-tight">
            Library Catalog &<br /> Student Borrowing Portal
          </h1>

          <p className="text-gray-500 text-[13px] leading-relaxed mb-6 font-medium">
            Real-time physical copy tracking, online book renewal, hold requests, and course reserve allocations.
          </p>

          {/* Borrowing Rule Inner Card */}
          <div className="border border-gray-100 shadow-sm rounded-2xl p-5 relative overflow-hidden bg-white">
            <div className="absolute right-6 top-4 bottom-4 w-px bg-gray-100"></div>

            <div className="mb-4">
              <h3 className="text-[10px] font-bold text-gray-400 tracking-wider uppercase mb-1">Your Borrowing Rule</h3>
              {/* Neutral background - NO yellow background */}
              <span className="inline-block bg-slate-100 text-slate-800 border border-slate-200 text-[11px] font-extrabold px-2.5 py-0.5 rounded uppercase">
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

        {/* 2. Stats Dashboard (Vertical Stack for Mobile) */}
        <div className="flex flex-col gap-3">
          {/* Total Titles */}
          <div className="bg-white rounded-[1.25rem] p-4 flex items-center gap-4 shadow-sm border border-gray-100">
            <div className="w-12 h-12 rounded-xl bg-gray-100 flex items-center justify-center text-gray-500 shrink-0">
              <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" strokeWidth={2} stroke="currentColor" className="w-6 h-6">
                <path strokeLinecap="round" strokeLinejoin="round" d="M12 6.042A8.967 8.967 0 006 3.75c-1.052 0-2.062.18-3 .512v14.25A8.987 8.987 0 016 18c2.305 0 4.408.867 6 2.292m0-14.25a8.966 8.966 0 016-2.292c1.052 0 2.062.18 3 .512v14.25A8.987 8.987 0 0018 18a8.967 8.967 0 00-6 2.292m0-14.25v14.25" />
              </svg>
            </div>
            <div>
              <div className="text-[10px] font-bold text-gray-400 tracking-wider uppercase">Total Titles</div>
              <div className="text-xl font-extrabold text-[#0f172a]">{books.length}</div>
            </div>
          </div>

          {/* Available */}
          <div className="bg-white rounded-[1.25rem] p-4 flex items-center gap-4 shadow-sm border border-gray-100">
            <div className="w-12 h-12 rounded-xl bg-green-50 border border-green-100 flex items-center justify-center text-green-500 shrink-0">
              <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" strokeWidth={2} stroke="currentColor" className="w-6 h-6">
                <path strokeLinecap="round" strokeLinejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
              </svg>
            </div>
            <div>
              <div className="text-[10px] font-bold text-gray-400 tracking-wider uppercase">Available</div>
              <div className="text-xl font-extrabold text-green-600">
                {books.reduce((acc, b) => acc + b.available, 0)} <span className="text-sm text-gray-400 font-bold">/ {books.reduce((acc, b) => acc + b.total, 0)}</span>
              </div>
            </div>
          </div>

          {/* Checked Out */}
          <div className="bg-white rounded-[1.25rem] p-4 flex items-center gap-4 shadow-sm border border-gray-100">
            <div className="w-12 h-12 rounded-xl bg-orange-50 border border-orange-100 flex items-center justify-center text-orange-400 shrink-0">
              <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" strokeWidth={2} stroke="currentColor" className="w-6 h-6">
                <path strokeLinecap="round" strokeLinejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z" />
              </svg>
            </div>
            <div>
              <div className="text-[10px] font-bold text-gray-400 tracking-wider uppercase">Checked Out</div>
              <div className="text-xl font-extrabold text-orange-500">2</div>
            </div>
          </div>

          {/* Overdue Copies (Only on SuperAdmin and Admin) */}
          {isSuperAdmin && (
            <div className="bg-white rounded-[1.25rem] p-4 flex items-center gap-4 shadow-sm border border-gray-100">
              <div className="w-12 h-12 rounded-xl bg-red-50 border border-red-100 flex items-center justify-center text-red-500 shrink-0">
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

          {/* Total System Fines (Only on SuperAdmin and Admin) */}
          {isSuperAdmin && (
            <div className="bg-white rounded-[1.25rem] p-4 flex items-center gap-4 shadow-sm border border-gray-100">
              <div className="w-12 h-12 rounded-xl bg-red-50 border border-red-100 flex items-center justify-center text-[#8B1A24] shrink-0 font-extrabold text-lg">
                ₱
              </div>
              <div>
                <div className="text-[10px] font-bold text-gray-400 tracking-wider uppercase">Total System Fines</div>
                <div className="text-xl font-extrabold text-[#8B1A24]">₱150.00</div>
              </div>
            </div>
          )}
        </div>

        {/* 3. Navigation Buttons & Admin Quick Actions Card (Mobile) */}
        <div className="bg-white rounded-[1.5rem] p-3.5 shadow-sm border border-gray-100 flex flex-col gap-3.5">
          {/* Row 1: 3 Core Tabs for Everyone */}
          <div className="flex gap-2.5">
            <button
              onClick={() => setActiveTab('catalog')}
              className={`flex-1 flex flex-col items-center justify-center py-3.5 px-2 rounded-[1.25rem] transition-all duration-200 cursor-pointer ${activeTab === 'catalog'
                  ? 'bg-[#1e293b] text-white shadow-md scale-[1.02]'
                  : 'bg-transparent text-[#1e293b] hover:bg-gray-50 border border-gray-100'
                }`}
            >
              <div className="flex items-center gap-1.5 mb-2">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" strokeWidth={2.5} stroke="currentColor" className="w-[18px] h-[18px]">
                  <path strokeLinecap="round" strokeLinejoin="round" d="M21 21l-5.197-5.197m0 0A7.5 7.5 0 105.196 5.196a7.5 7.5 0 0010.607 10.607z" />
                </svg>
                <span className={`text-[10px] font-bold w-4 h-4 flex items-center justify-center rounded-full ${activeTab === 'catalog' ? 'bg-white/20 text-white' : 'bg-gray-100 text-gray-500'}`}>
                  {books.length}
                </span>
              </div>
              <span className="text-[12px] font-extrabold text-center leading-tight">Catalog<br />Search</span>
            </button>

            <button
              onClick={() => setActiveTab('borrowing')}
              className={`flex-1 flex flex-col items-center justify-center py-3.5 px-2 rounded-[1.25rem] transition-all duration-200 cursor-pointer ${activeTab === 'borrowing'
                  ? 'bg-[#1e293b] text-white shadow-md scale-[1.02]'
                  : 'bg-transparent text-[#1e293b] hover:bg-gray-50 border border-gray-100'
                }`}
            >
              <div className="flex items-center gap-1.5 mb-2">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" strokeWidth={2.5} stroke="currentColor" className={`w-[18px] h-[18px] ${activeTab === 'borrowing' ? 'text-white' : 'text-orange-400'}`}>
                  <path strokeLinecap="round" strokeLinejoin="round" d="M17.593 3.322c1.1.128 1.907 1.077 1.907 2.185V21L12 17.25 4.5 21V5.507c0-1.108.806-2.057 1.907-2.185a48.507 48.507 0 0111.186 0z" />
                </svg>
                <span className={`text-[10px] font-bold w-4 h-4 flex items-center justify-center rounded-full ${activeTab === 'borrowing' ? 'bg-white/20 text-white' : 'bg-gray-100 text-gray-700'}`}>0</span>
              </div>
              <span className="text-[12px] font-extrabold text-center leading-tight">My<br />Borrowing</span>
            </button>

            <button
              onClick={() => setActiveTab('reserves')}
              className={`flex-1 flex flex-col items-center justify-center py-3.5 px-2 rounded-[1.25rem] transition-all duration-200 cursor-pointer ${activeTab === 'reserves'
                  ? 'bg-[#1e293b] text-white shadow-md scale-[1.02]'
                  : 'bg-transparent text-[#1e293b] hover:bg-gray-50 border border-gray-100'
                }`}
            >
              <div className="flex items-center gap-1.5 mb-2">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" strokeWidth={2.5} stroke="currentColor" className={`w-[18px] h-[18px] ${activeTab === 'reserves' ? 'text-white' : 'text-blue-400'}`}>
                  <path strokeLinecap="round" strokeLinejoin="round" d="M12 2.25L2.25 7.5l9.75 5.25L21.75 7.5 12 2.25zM2.25 12l9.75 5.25L21.75 12M2.25 16.5l9.75 5.25L21.75 16.5" />
                </svg>
                <span className={`text-[10px] font-bold w-4 h-4 flex items-center justify-center rounded-full ${activeTab === 'reserves' ? 'bg-white/20 text-white' : 'bg-gray-100 text-gray-700'}`}>2</span>
              </div>
              <span className="text-[12px] font-extrabold text-center leading-tight">Course<br />Reserves</span>
            </button>
          </div>

          {/* Row 2: Admin Quick Actions (ONLY on SuperAdmin and Admin, NO yellow background) */}
          {isSuperAdmin && (
            <>
              <div className="w-full border-t-2 border-dashed border-gray-100"></div>

              <div className="flex gap-2.5">
                <button
                  onClick={() => setActiveTab('circulation')}
                  className={`flex-1 flex flex-col items-center justify-center py-3.5 px-2 rounded-[1.25rem] transition-all duration-200 cursor-pointer ${activeTab === 'circulation'
                      ? 'bg-[#1e293b] text-white shadow-md scale-[1.02]'
                      : 'bg-transparent text-[#1e293b] hover:bg-gray-50 border border-gray-100'
                    }`}
                >
                  <div className="flex items-center gap-1.5 mb-2">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" strokeWidth={2.5} stroke="currentColor" className={`w-[18px] h-[18px] ${activeTab === 'circulation' ? 'text-white' : 'text-green-500'}`}>
                      <path strokeLinecap="round" strokeLinejoin="round" d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0l3.181 3.183a8.25 8.25 0 0013.803-3.7M4.031 9.865a8.25 8.25 0 0113.803-3.7l3.181 3.182m0-4.991v4.99" />
                    </svg>
                    <span className={`text-[10px] font-bold w-4 h-4 flex items-center justify-center rounded-full ${activeTab === 'circulation' ? 'bg-white/20 text-white' : 'bg-gray-100 text-gray-500'}`}>2</span>
                  </div>
                  <span className="text-[11px] font-extrabold text-center leading-tight">Circulation<br />Desk</span>
                </button>

                <button
                  onClick={() => setActiveTab('fines')}
                  className={`flex-1 flex flex-col items-center justify-center py-3.5 px-2 rounded-[1.25rem] transition-all duration-200 cursor-pointer ${activeTab === 'fines'
                      ? 'bg-[#1e293b] text-white shadow-md scale-[1.02]'
                      : 'bg-transparent text-[#1e293b] hover:bg-gray-50 border border-gray-100'
                    }`}
                >
                  <div className="flex items-center gap-1.5 mb-2">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" strokeWidth={2.5} stroke="currentColor" className={`w-[18px] h-[18px] ${activeTab === 'fines' ? 'text-white' : 'text-red-500'}`}>
                      <path strokeLinecap="round" strokeLinejoin="round" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                    </svg>
                    <span className={`text-[10px] font-bold w-4 h-4 flex items-center justify-center rounded-full ${activeTab === 'fines' ? 'bg-white/20 text-white' : 'bg-gray-100 text-gray-500'}`}>2</span>
                  </div>
                  <span className="text-[11px] font-extrabold text-center leading-tight">Admin Fines<br />& Queue</span>
                </button>

                {/* Add Title Button (Positioned beside Circulation Desk & Admin Fines, NO yellow background) */}
                <button
                  type="button"
                  onClick={() => setShowAddBookModal(true)}
                  className="flex-1 flex flex-col items-center justify-center py-3.5 px-1 rounded-[1.25rem] transition-all duration-200 cursor-pointer bg-transparent text-[#1e293b] hover:bg-gray-50 border border-gray-100"
                >
                  <div className="flex items-center gap-1.5 mb-2">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" strokeWidth={2.5} stroke="currentColor" className="w-[18px] h-[18px] text-slate-700">
                      <path strokeLinecap="round" strokeLinejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                    </svg>
                  </div>
                  <span className="text-[11px] font-extrabold text-center leading-tight">Add Title<br />& Copies</span>
                </button>
              </div>
            </>
          )}
        </div>

        {/* 4. Active Tab Content for Mobile */}
        <div className="flex flex-col gap-4 w-full">
          {activeTab === 'catalog' && (
            <>
              {/* 5. Search & Filter Section (Mobile) */}
              <div className="bg-white rounded-[2rem] p-5 shadow-sm border border-gray-100 mt-2">
                <div className="flex items-center gap-2 mb-4 border-b border-gray-100 pb-3">
                  <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" strokeWidth={2.5} stroke="currentColor" className="w-4 h-4 text-[#8B1A24]">
                    <path strokeLinecap="round" strokeLinejoin="round" d="M21 21l-5.197-5.197m0 0A7.5 7.5 0 105.196 5.196a7.5 7.5 0 0010.607 10.607z" />
                  </svg>
                  <h2 className="text-[11px] font-extrabold text-[#1e293b] tracking-wider uppercase">Library Catalog Search & Discovery</h2>
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
                      value={searchQuery}
                      onChange={(e) => setSearchQuery(e.target.value)}
                      placeholder="Search title, author, ISBN, location..."
                      className="w-full bg-gray-50 border border-gray-200 rounded-xl py-2.5 pl-9 pr-4 text-sm focus:outline-none focus:ring-2 focus:ring-gray-200 text-gray-700"
                    />
                  </div>
                </div>

                <div>
                  <label className="block text-[10px] font-extrabold text-[#1e293b] tracking-wider uppercase mb-2">
                    Filter by Category:
                  </label>
                  <div className="flex flex-wrap gap-2">
                    {categoriesList.map(cat => (
                      <button
                        key={cat}
                        onClick={() => setSelectedCategory(cat)}
                        className={`text-[10px] font-extrabold px-3 py-1.5 rounded-full cursor-pointer transition-colors ${selectedCategory === cat
                            ? 'bg-[#8B1A24] text-white shadow-xs'
                            : 'bg-gray-100 text-gray-600 hover:bg-gray-200'
                          }`}
                      >
                        {cat}
                      </button>
                    ))}
                  </div>
                </div>
              </div>

              {/* 6. Book List (Mobile Single Column) */}
              <div className="flex flex-col gap-3 mt-2">
                {filteredBooks.map(book => (
                  <div key={book.id} className="bg-white rounded-[1.5rem] p-4 shadow-sm border border-gray-100 flex flex-col gap-4">
                    <div className="flex gap-4">
                      <div className="w-[88px] h-[104px] shrink-0 rounded-xl overflow-hidden shadow-sm border border-gray-100 bg-gray-100">
                        <img src={book.image} alt={book.title} className="w-full h-full object-cover" />
                      </div>
                      <div className="flex flex-col flex-1 min-w-0">
                        <div className="flex flex-wrap gap-1.5 mb-2">
                          {book.categories.map((cat, i) => (
                            <span key={i} className={`text-[8px] font-extrabold uppercase px-1.5 py-0.5 rounded ${cat === 'COURSE RESERVE' ? 'bg-amber-100 text-amber-800' : 'bg-blue-50 text-blue-700'}`}>
                              {cat}
                            </span>
                          ))}
                        </div>
                        <h3 className="font-extrabold text-[13px] text-[#0f172a] leading-tight mb-1">{book.title}</h3>
                        <p className="text-[11px] text-gray-500 font-medium mb-1 truncate">by {book.author}</p>
                        <p className="text-[10px] text-gray-400 font-medium mb-2 truncate">ISBN: {book.isbn}</p>
                        <div className="flex items-start gap-1 text-gray-500 mt-auto">
                          <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" strokeWidth={2} stroke="currentColor" className="w-3 h-3 shrink-0 mt-0.5 text-[#8B1A24]">
                            <path strokeLinecap="round" strokeLinejoin="round" d="M15 10.5a3 3 0 11-6 0 3 3 0 016 0z" />
                            <path strokeLinecap="round" strokeLinejoin="round" d="M19.5 10.5c0 7.142-7.5 11.25-7.5 11.25S4.5 17.642 4.5 10.5a7.5 7.5 0 1115 0z" />
                          </svg>
                          <span className="text-[10px] font-bold leading-tight text-[#1e293b]">{book.location}</span>
                        </div>
                      </div>
                    </div>

                    <div className="flex items-center justify-between border-t border-gray-100 pt-3">
                      <div className="bg-green-50 text-green-700 border border-green-100 px-2.5 py-1 rounded-full flex items-center gap-1.5">
                        <div className="w-1.5 h-1.5 bg-green-500 rounded-full"></div>
                        <span className="text-[10px] font-extrabold">{book.available} of {book.total} Copies Available</span>
                      </div>
                      <button
                        onClick={() => setSelectedBook(book)}
                        className="bg-[#1e293b] text-white text-[11px] font-extrabold px-3 py-1.5 rounded-full flex items-center gap-1 hover:bg-[#0f172a] transition-colors cursor-pointer"
                      >
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

          {activeTab === 'borrowing' && (
            <div className="flex flex-col gap-3 mt-2">
              <div className="bg-white rounded-[2rem] p-6 shadow-sm border border-gray-100 text-center">
                <div className="flex items-center justify-center gap-2 mb-2">
                  <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" strokeWidth={2.5} stroke="currentColor" className="w-5 h-5 text-[#8B1A24]">
                    <path strokeLinecap="round" strokeLinejoin="round" d="M17.593 3.322c1.1.128 1.907 1.077 1.907 2.185V21L12 17.25 4.5 21V5.507c0-1.108.806-2.057 1.907-2.185a48.507 48.507 0 0111.186 0z" />
                  </svg>
                  <h2 className="text-[17px] font-extrabold text-[#0f172a] leading-tight">Your Active Book Loans (0)</h2>
                </div>
                <p className="text-gray-500 text-[11px] leading-relaxed mb-4 font-medium">
                  Books currently checked out under your student profile ({user?.username || '2012-00000-SYS'}).
                </p>
                <div className="inline-block bg-gray-100 text-[#0f172a] text-[10px] font-extrabold px-3 py-1.5 rounded-lg mb-5">
                  Max Allowed: 0 / {user?.role === 'Teacher' ? '10' : user?.role === 'Student' ? '3' : '20'} Books
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

                <div className="border border-amber-200 bg-[#fffbeb] rounded-[1rem] p-4 text-left flex gap-3 items-start">
                  <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" strokeWidth={2} stroke="currentColor" className="w-5 h-5 shrink-0 text-[#b45309]">
                    <path strokeLinecap="round" strokeLinejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12c0 1.268-.63 2.39-1.593 3.068a3.745 3.745 0 01-1.043 3.296 3.745 3.745 0 01-3.296 1.043A3.745 3.745 0 0112 21c-1.268 0-2.39-.63-3.068-1.593a3.746 3.746 0 01-3.296-1.043 3.745 3.745 0 01-1.043-3.296A3.745 3.745 0 013 12c0-1.268.63-2.39 1.593-3.068a3.745 3.745 0 011.043-3.296 3.746 3.746 0 013.296-1.043A3.746 3.746 0 0112 3c1.268 0 2.39.63 3.068 1.593a3.746 3.746 0 013.296 1.043 3.746 3.746 0 0121 12z" />
                  </svg>
                  <p className="text-[10px] text-[#92400e] font-medium leading-relaxed">
                    Books held under "Ready for Pickup" are reserved at the Circulation Desk for 48 hours before proceeding to the next student in queue.
                  </p>
                </div>
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
                  <button
                    onClick={() => setShowReserveModal(true)}
                    className="bg-[#8B1A24] text-white text-[12px] font-extrabold py-2.5 px-5 rounded-xl hover:bg-[#6b141c] transition-colors flex items-center gap-1.5 cursor-pointer shadow-sm"
                  >
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

          {/* Circulation Desk (Mobile) - Only on SuperAdmin and Admin */}
          {activeTab === 'circulation' && isSuperAdmin && (
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
                  <div key={item.id} className="border border-gray-200 rounded-[1.25rem] p-4 flex flex-col gap-3 bg-white">
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

                    <button
                      onClick={() => alert(`Checked-in copy ${item.copyId} successfully!`)}
                      className="w-full mt-1 bg-[#047857] text-white text-[12px] font-extrabold py-2.5 rounded-xl hover:bg-[#065f46] transition-colors cursor-pointer"
                    >
                      Check-In Copy
                    </button>
                  </div>
                ))}
              </div>
            </div>
          )}

          {/* Admin Fines & Queue (Mobile) - Only on SuperAdmin and Admin */}
          {activeTab === 'fines' && isSuperAdmin && (
            <div className="flex flex-col gap-3 mt-2">
              <div className="bg-white rounded-[2rem] p-6 shadow-sm border border-gray-100">
                <div className="flex justify-between items-start mb-5">
                  <div>
                    <h2 className="text-[17px] font-extrabold text-[#0f172a] leading-tight mb-1">Hold Request Queue</h2>
                    <p className="text-gray-500 text-[11px] font-medium leading-relaxed max-w-[200px]">
                      Reserves queue placement when stock is 0.
                    </p>
                  </div>
                  <div className="border border-slate-200 bg-slate-50 text-slate-700 px-3 py-1.5 rounded-lg flex flex-col items-center justify-center shrink-0">
                    <span className="text-[13px] font-extrabold leading-none mb-0.5">2</span>
                    <span className="text-[10px] font-extrabold leading-none">Requests</span>
                  </div>
                </div>

                <div className="flex flex-col gap-3">
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
                      <button
                        onClick={() => alert('Cancelled hold request.')}
                        className="bg-gray-100 text-[#1e293b] text-[11px] font-extrabold px-5 py-2 rounded-xl hover:bg-gray-200 transition-colors cursor-pointer"
                      >
                        Cancel Hold
                      </button>
                    </div>
                  </div>

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
                      <button
                        onClick={() => alert('Cancelled hold request.')}
                        className="bg-gray-100 text-[#1e293b] text-[11px] font-extrabold px-5 py-2 rounded-xl hover:bg-gray-200 transition-colors cursor-pointer"
                      >
                        Cancel Hold
                      </button>
                    </div>
                  </div>
                </div>
              </div>

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

          {/* Add Title & Physical Copies Tab (Mobile) - Only on SuperAdmin and Admin */}
          {activeTab === 'add_title' && isSuperAdmin && (
            <div className="bg-white rounded-[2rem] p-6 shadow-sm border border-gray-100 mt-2">
              <div className="mb-6">
                {/* Neutral badge - NO yellow background */}
                <span className="inline-block bg-slate-100 text-slate-800 border border-slate-200 text-[9px] font-extrabold uppercase px-2 py-1 rounded tracking-widest mb-3">
                  Library Inventory Management
                </span>
                <h2 className="text-[20px] font-extrabold text-[#0f172a] leading-tight tracking-tight">Register New Title & Physical Copies</h2>
              </div>

              <form onSubmit={handleAddBook} className="flex flex-col gap-4">
                <div>
                  <label className="block text-[11px] font-extrabold text-[#0f172a] mb-1.5">Book Title *</label>
                  <input
                    type="text"
                    required
                    value={newBook.title}
                    onChange={(e) => setNewBook({ ...newBook, title: e.target.value })}
                    placeholder="e.g. Operating System Concepts 10th Ed."
                    className="w-full border border-gray-200 rounded-xl py-2.5 px-3 text-[13px] focus:outline-none focus:ring-2 focus:ring-[#8B1A24]/20 focus:border-[#8B1A24] text-[#0f172a] placeholder-gray-400 font-medium"
                  />
                </div>

                <div className="flex flex-col sm:flex-row gap-4">
                  <div className="flex-1">
                    <label className="block text-[11px] font-extrabold text-[#0f172a] mb-1.5">Author *</label>
                    <input
                      type="text"
                      required
                      value={newBook.author}
                      onChange={(e) => setNewBook({ ...newBook, author: e.target.value })}
                      placeholder="e.g. Abraham Silberschatz"
                      className="w-full border border-gray-200 rounded-xl py-2.5 px-3 text-[13px] focus:outline-none focus:ring-2 focus:ring-[#8B1A24]/20 focus:border-[#8B1A24] text-[#0f172a] placeholder-gray-400 font-medium"
                    />
                  </div>

                  <div className="flex-1">
                    <label className="block text-[11px] font-extrabold text-[#0f172a] mb-1.5">ISBN-13 *</label>
                    <input
                      type="text"
                      required
                      value={newBook.isbn}
                      onChange={(e) => setNewBook({ ...newBook, isbn: e.target.value })}
                      placeholder="e.g. 978-1118063330"
                      className="w-full border border-gray-200 rounded-xl py-2.5 px-3 text-[13px] focus:outline-none focus:ring-2 focus:ring-[#8B1A24]/20 focus:border-[#8B1A24] text-[#0f172a] placeholder-gray-400 font-medium"
                    />
                  </div>
                </div>

                <div className="flex flex-col sm:flex-row gap-4">
                  <div className="flex-1">
                    <label className="block text-[11px] font-extrabold text-[#0f172a] mb-1.5">Category</label>
                    <select
                      value={newBook.category}
                      onChange={(e) => setNewBook({ ...newBook, category: e.target.value })}
                      className="w-full border border-gray-200 rounded-xl py-2.5 px-3 text-[13px] focus:outline-none focus:ring-2 focus:ring-[#8B1A24]/20 focus:border-[#8B1A24] text-[#0f172a] font-extrabold bg-white cursor-pointer"
                    >
                      <option value="IT & Computer Science">IT & Computer Science</option>
                      <option value="Mathematics & Sciences">Mathematics & Sciences</option>
                      <option value="General Education">General Education</option>
                      <option value="Literature & Arts">Literature & Arts</option>
                      <option value="Research & Journals">Research & Journals</option>
                    </select>
                  </div>

                  <div className="flex-1">
                    <label className="block text-[11px] font-extrabold text-[#0f172a] mb-1.5">Copy Quantity</label>
                    <input
                      type="number"
                      min="1"
                      max="50"
                      value={newBook.copies}
                      onChange={(e) => setNewBook({ ...newBook, copies: e.target.value })}
                      className="w-full border border-gray-200 rounded-xl py-2.5 px-3 text-[13px] focus:outline-none focus:ring-2 focus:ring-[#8B1A24]/20 focus:border-[#8B1A24] text-[#0f172a] font-extrabold"
                    />
                  </div>
                </div>

                <div>
                  <label className="block text-[11px] font-extrabold text-[#0f172a] mb-1.5">Shelf Location</label>
                  <input
                    type="text"
                    value={newBook.location}
                    onChange={(e) => setNewBook({ ...newBook, location: e.target.value })}
                    className="w-full border border-gray-200 rounded-xl py-2.5 px-3 text-[13px] focus:outline-none focus:ring-2 focus:ring-[#8B1A24]/20 focus:border-[#8B1A24] text-[#0f172a] font-medium"
                  />
                </div>

                <div className="flex items-center gap-2 mt-1">
                  <input
                    type="checkbox"
                    id="mobileCourseReserveCheck"
                    checked={newBook.isCourseReserve}
                    onChange={(e) => setNewBook({ ...newBook, isCourseReserve: e.target.checked })}
                    className="w-4 h-4 rounded text-[#8B1A24] focus:ring-[#8B1A24] cursor-pointer"
                  />
                  <label htmlFor="mobileCourseReserveCheck" className="text-[12px] font-bold text-[#0f172a] cursor-pointer">
                    Mark as Course Reserve (In-Library Reference Only)
                  </label>
                </div>

                <div className="flex justify-end gap-3 mt-4 pt-3 border-t border-gray-100">
                  <button
                    type="button"
                    onClick={() => setActiveTab('catalog')}
                    className="px-5 py-2.5 border border-gray-200 rounded-xl text-[#0f172a] text-[11px] font-extrabold hover:bg-gray-50 transition-colors cursor-pointer"
                  >
                    Cancel
                  </button>
                  <button
                    type="submit"
                    className="px-5 py-2.5 bg-[#8B1A24] text-white rounded-xl text-[11px] font-extrabold hover:bg-[#6b141c] transition-colors shadow-sm cursor-pointer"
                  >
                    Save Title & Register Copies
                  </button>
                </div>
              </form>
            </div>
          )}
        </div>
      </div>

      {/* ========================================================================= */}
      {/* 🖥️ DESKTOP VIEW (lg:): Dashboard Mode Exactly Matching User Mockup       */}
      {/* ========================================================================= */}
      <div className="hidden lg:flex lg:flex-col gap-4.5 w-full max-w-7xl mx-auto p-4">

        {/* 1. Desktop Header Card */}
        <div className="bg-white rounded-[1.25rem] p-5 lg:p-6 shadow-xs border border-slate-200/70">
          <div className="flex items-center gap-2 mb-2.5">
            <span className="bg-[#8B1A24] text-white text-[9.5px] font-black tracking-wider uppercase px-2.5 py-1 rounded">
              LIBRARY & CIRCULATION SYSTEM
            </span>
            {isSuperAdmin && (
              <button 
                type="button"
                onClick={() => setShowAddBookModal(true)}
                className="bg-amber-400 hover:bg-amber-500 text-amber-950 font-black text-[9.5px] tracking-wider uppercase px-2.5 py-1 rounded flex items-center gap-1 transition-colors cursor-pointer shadow-2xs"
              >
                <span>+ ADD TITLE & COPIES</span>
              </button>
            )}
          </div>

          <h1 className="text-[24px] lg:text-[26px] font-extrabold text-[#0f172a] tracking-tight leading-tight mb-1">
            Library Catalog & Student Borrowing Portal
          </h1>

          <p className="text-slate-400 text-[11.5px] font-medium mb-4">
            Real-time physical copy tracking, online book renewal, hold requests, and course reserve allocations.
          </p>

          {/* Borrowing Rule Inner Box (3 Columns) */}
          <div className="border border-slate-200/80 rounded-xl p-4 bg-white grid grid-cols-1 md:grid-cols-3 gap-4 divide-y md:divide-y-0 md:divide-x divide-slate-100">
            <div className="md:pr-4">
              <h3 className="text-[9.5px] font-extrabold text-slate-400 tracking-wider uppercase mb-1">YOUR BORROWING RULE</h3>
              {/* Neutral pill - NO yellow background */}
              <span className="inline-block bg-slate-100 text-slate-800 border border-slate-200 text-[10.5px] font-extrabold px-2.5 py-0.5 rounded uppercase">
                {user?.role === 'Teacher' ? 'FACULTY' : user?.role === 'Student' ? 'STUDENT' : 'ADMIN'}
              </span>
            </div>

            <div className="pt-3 md:pt-0 md:px-5">
              <h3 className="text-[9.5px] font-extrabold text-slate-400 tracking-wider uppercase mb-1">LOAN PRIVILEGE LIMIT</h3>
              <p className="font-extrabold text-[#0f172a] text-[13px]">
                {user?.role === 'Teacher' ? 'Max 10 Books (30-Day Loan)' : user?.role === 'Student' ? 'Max 3 Books (7-Day Loan)' : 'Max 20 Books (60-Day Loan)'}
              </p>
            </div>

            <div className="pt-3 md:pt-0 md:pl-5">
              <h3 className="text-[9.5px] font-extrabold text-slate-400 tracking-wider uppercase mb-1">OVERDUE LATE FINE</h3>
              <p className="font-extrabold text-[#0f172a] text-[13px]">
                {user?.role === 'Teacher' ? 'Exempt (Faculty/Admin)' : user?.role === 'Student' ? '₱10.00 / Day' : 'Exempt (Faculty/Admin)'}
              </p>
            </div>
          </div>
        </div>

        {/* 2. Desktop Key Metric Stat Cards */}
        <div className="flex flex-col gap-3">
          <div className={`grid grid-cols-1 sm:grid-cols-2 ${isSuperAdmin ? 'lg:grid-cols-4' : 'lg:grid-cols-3'} gap-3.5`}>
            {/* Total Titles */}
            <div className="bg-white rounded-[1.25rem] p-4 flex items-center gap-3.5 shadow-xs border border-slate-200/70">
              <div className="w-10 h-10 rounded-xl bg-slate-50 border border-slate-100 flex items-center justify-center text-slate-400 shrink-0">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" strokeWidth={1.75} stroke="currentColor" className="w-5 h-5">
                  <path strokeLinecap="round" strokeLinejoin="round" d="M12 6.042A8.967 8.967 0 006 3.75c-1.052 0-2.062.18-3 .512v14.25A8.987 8.987 0 016 18c2.305 0 4.408.867 6 2.292m0-14.25a8.966 8.966 0 016-2.292c1.052 0 2.062.18 3 .512v14.25A8.987 8.987 0 0018 18a8.967 8.967 0 00-6 2.292m0-14.25v14.25" />
                </svg>
              </div>
              <div>
                <div className="text-[9px] font-extrabold text-slate-400 tracking-wider uppercase">TOTAL TITLES</div>
                <div className="text-[22px] font-black text-[#0f172a] leading-none mt-0.5">{books.length}</div>
              </div>
            </div>

            {/* Available */}
            <div className="bg-white rounded-[1.25rem] p-4 flex items-center gap-3.5 shadow-xs border border-slate-200/70">
              <div className="w-10 h-10 rounded-xl bg-emerald-50/80 border border-emerald-100 flex items-center justify-center text-emerald-500 shrink-0">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" strokeWidth={2} stroke="currentColor" className="w-5 h-5">
                  <path strokeLinecap="round" strokeLinejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                </svg>
              </div>
              <div>
                <div className="text-[9px] font-extrabold text-slate-400 tracking-wider uppercase">AVAILABLE</div>
                <div className="text-[22px] font-black text-emerald-600 leading-none mt-0.5">
                  {books.reduce((acc, b) => acc + b.available, 0)} <span className="text-xs text-slate-400 font-bold">/ {books.reduce((acc, b) => acc + b.total, 0)}</span>
                </div>
              </div>
            </div>

            {/* Checked Out */}
            <div className="bg-white rounded-[1.25rem] p-4 flex items-center gap-3.5 shadow-xs border border-slate-200/70">
              <div className="w-10 h-10 rounded-xl bg-amber-50/80 border border-amber-100 flex items-center justify-center text-amber-500 shrink-0">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" strokeWidth={2} stroke="currentColor" className="w-5 h-5">
                  <path strokeLinecap="round" strokeLinejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z" />
                </svg>
              </div>
              <div>
                <div className="text-[9px] font-extrabold text-slate-400 tracking-wider uppercase">CHECKED OUT</div>
                <div className="text-[22px] font-black text-amber-500 leading-none mt-0.5">2</div>
              </div>
            </div>

            {/* Overdue Copies (Only on SuperAdmin and Admin) */}
            {isSuperAdmin && (
              <div className="bg-white rounded-[1.25rem] p-4 flex items-center gap-3.5 shadow-xs border border-slate-200/70">
                <div className="w-10 h-10 rounded-xl bg-rose-50/80 border border-rose-100 flex items-center justify-center text-rose-500 shrink-0">
                  <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" strokeWidth={2} stroke="currentColor" className="w-5 h-5">
                    <path strokeLinecap="round" strokeLinejoin="round" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                  </svg>
                </div>
                <div>
                  <div className="text-[9px] font-extrabold text-slate-400 tracking-wider uppercase">OVERDUE COPIES</div>
                  <div className="text-[22px] font-black text-rose-600 leading-none mt-0.5">2</div>
                </div>
              </div>
            )}
          </div>

          {/* Row 2: Total System Fines (Only on SuperAdmin and Admin) */}
          {isSuperAdmin && (
            <div className="w-full sm:w-64">
              <div className="bg-white rounded-[1.25rem] p-4 flex items-center gap-3.5 shadow-xs border border-slate-200/70">
                <div className="w-10 h-10 rounded-xl bg-rose-50/80 border border-rose-100 flex items-center justify-center text-rose-600 shrink-0 font-black text-base">
                  ₱
                </div>
                <div>
                  <div className="text-[9px] font-extrabold text-slate-400 tracking-wider uppercase">TOTAL SYSTEM FINES</div>
                  <div className="text-[22px] font-black text-[#8B1A24] leading-none mt-0.5">₱150.00</div>
                </div>
              </div>
            </div>
          )}
        </div>

        {/* 3. Desktop Navigation Tabs Card */}
        <div className="bg-white rounded-[1.25rem] p-3 shadow-xs border border-slate-200/70 flex flex-col gap-2.5">
          {/* Row 1: 3 Main Tabs for Everyone */}
          <div className="grid grid-cols-1 sm:grid-cols-3 gap-2.5">
            {/* Catalog Search */}
            <button
              onClick={() => setActiveTab('catalog')}
              className={`flex items-center justify-center gap-1.5 py-3 px-4 rounded-xl transition-all duration-150 font-extrabold text-[12px] cursor-pointer ${activeTab === 'catalog'
                  ? 'bg-[#1e293b] text-white shadow-xs'
                  : 'bg-white text-slate-700 hover:bg-slate-50 border border-slate-100'
                }`}
            >
              <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" strokeWidth={2.5} stroke="currentColor" className="w-4 h-4">
                <path strokeLinecap="round" strokeLinejoin="round" d="M21 21l-5.197-5.197m0 0A7.5 7.5 0 105.196 5.196a7.5 7.5 0 0010.607 10.607z" />
              </svg>
              <span className={`text-[9px] font-bold w-4 h-4 flex items-center justify-center rounded-full ${activeTab === 'catalog' ? 'bg-slate-700 text-white' : 'bg-slate-100 text-slate-600'}`}>
                {books.length}
              </span>
              <span>Catalog Search</span>
            </button>

            {/* My Borrowing */}
            <button
              onClick={() => setActiveTab('borrowing')}
              className={`flex items-center justify-center gap-1.5 py-3 px-4 rounded-xl transition-all duration-150 font-extrabold text-[12px] cursor-pointer ${activeTab === 'borrowing'
                  ? 'bg-[#1e293b] text-white shadow-xs'
                  : 'bg-white text-slate-700 hover:bg-slate-50 border border-slate-100'
                }`}
            >
              <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" strokeWidth={2.5} stroke="currentColor" className={`w-4 h-4 ${activeTab === 'borrowing' ? 'text-white' : 'text-amber-500'}`}>
                <path strokeLinecap="round" strokeLinejoin="round" d="M17.593 3.322c1.1.128 1.907 1.077 1.907 2.185V21L12 17.25 4.5 21V5.507c0-1.108.806-2.057 1.907-2.185a48.507 48.507 0 0111.186 0z" />
              </svg>
              <span className={`text-[9px] font-bold w-4 h-4 flex items-center justify-center rounded-full ${activeTab === 'borrowing' ? 'bg-slate-700 text-white' : 'bg-slate-100 text-slate-600'}`}>
                0
              </span>
              <span>My Borrowing</span>
            </button>

            {/* Course Reserves */}
            <button
              onClick={() => setActiveTab('reserves')}
              className={`flex items-center justify-center gap-1.5 py-3 px-4 rounded-xl transition-all duration-150 font-extrabold text-[12px] cursor-pointer ${activeTab === 'reserves'
                  ? 'bg-[#1e293b] text-white shadow-xs'
                  : 'bg-white text-slate-700 hover:bg-slate-50 border border-slate-100'
                }`}
            >
              <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" strokeWidth={2.5} stroke="currentColor" className={`w-4 h-4 ${activeTab === 'reserves' ? 'text-white' : 'text-sky-500'}`}>
                <path strokeLinecap="round" strokeLinejoin="round" d="M12 2.25L2.25 7.5l9.75 5.25L21.75 7.5 12 2.25zM2.25 12l9.75 5.25L21.75 12M2.25 16.5l9.75 5.25L21.75 16.5" />
              </svg>
              <span className={`text-[9px] font-bold w-4 h-4 flex items-center justify-center rounded-full ${activeTab === 'reserves' ? 'bg-slate-700 text-white' : 'bg-slate-100 text-slate-600'}`}>
                2
              </span>
              <span>Course Reserves</span>
            </button>
          </div>

          {/* Row 2: Admin Tabs + Add Title Button (Only on SuperAdmin and Admin, NO yellow background) */}
          {isSuperAdmin && (
            <div className="grid grid-cols-1 sm:grid-cols-3 gap-2.5">
              {/* Circulation Desk */}
              <button
                onClick={() => setActiveTab('circulation')}
                className={`flex items-center justify-center gap-2 py-3 px-4 rounded-xl transition-all duration-150 font-extrabold text-[12px] cursor-pointer ${activeTab === 'circulation'
                    ? 'bg-[#1e293b] text-white shadow-xs'
                    : 'bg-white text-slate-700 hover:bg-slate-50 border border-slate-100'
                  }`}
              >
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" strokeWidth={2.5} stroke="currentColor" className={`w-4 h-4 ${activeTab === 'circulation' ? 'text-white' : 'text-emerald-500'}`}>
                  <path strokeLinecap="round" strokeLinejoin="round" d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0l3.181 3.183a8.25 8.25 0 0013.803-3.7M4.031 9.865a8.25 8.25 0 0113.803-3.7l3.181 3.182m0-4.991v4.99" />
                </svg>
                <span>Circulation Desk (2)</span>
              </button>

              {/* Admin Fines & Queue */}
              <button
                onClick={() => setActiveTab('fines')}
                className={`flex items-center justify-center gap-2 py-3 px-4 rounded-xl transition-all duration-150 font-extrabold text-[12px] cursor-pointer ${activeTab === 'fines'
                    ? 'bg-[#1e293b] text-white shadow-xs'
                    : 'bg-white text-slate-700 hover:bg-slate-50 border border-slate-100'
                  }`}
              >
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" strokeWidth={2.5} stroke="currentColor" className={`w-4 h-4 ${activeTab === 'fines' ? 'text-white' : 'text-rose-500'}`}>
                  <path strokeLinecap="round" strokeLinejoin="round" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                </svg>
                <span>Admin Fines & Queue (2)</span>
              </button>

              {/* Add Title & Copies */}
              <button
                type="button"
                onClick={() => setShowAddBookModal(true)}
                className="flex items-center justify-center gap-2 py-3 px-4 rounded-xl transition-all duration-150 font-extrabold text-[12px] cursor-pointer active:scale-[0.99] bg-white hover:bg-slate-50 text-slate-700 border border-slate-200 shadow-xs"
              >
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" strokeWidth={2.5} stroke="currentColor" className="w-4 h-4 text-slate-700">
                  <path strokeLinecap="round" strokeLinejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                </svg>
                <span>Add Title & Copies</span>
              </button>
            </div>
          )}
        </div>

        {/* 4. Desktop Tab Content Area */}
        <div className="flex flex-col gap-4 w-full">
          {activeTab === 'catalog' && (
            <>
              {/* Search & Filter Section (Desktop) */}
              <div className="bg-white rounded-[1.25rem] p-5 shadow-xs border border-slate-200/70">
                <div className="flex items-center gap-2 mb-3.5 pb-2.5 border-b border-slate-100">
                  <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" strokeWidth={2.5} stroke="currentColor" className="w-4 h-4 text-[#8B1A24]">
                    <path strokeLinecap="round" strokeLinejoin="round" d="M21 21l-5.197-5.197m0 0A7.5 7.5 0 105.196 5.196a7.5 7.5 0 0010.607 10.607z" />
                  </svg>
                  <h2 className="text-[11px] font-black text-[#0f172a] tracking-wider uppercase">
                    LIBRARY CATALOG SEARCH & DISCOVERY
                  </h2>
                </div>

                <div className="mb-3.5">
                  <label className="block text-[9.5px] font-extrabold text-slate-500 tracking-wider uppercase mb-1.5">
                    SEARCH BOOK TITLES, AUTHORS, ISBNS, OR CALL NUMBERS
                  </label>
                  <div className="relative">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" strokeWidth={2} stroke="currentColor" className="w-4 h-4 text-slate-400 absolute left-3 top-1/2 -translate-y-1/2">
                      <path strokeLinecap="round" strokeLinejoin="round" d="M21 21l-5.197-5.197m0 0A7.5 7.5 0 105.196 5.196a7.5 7.5 0 0010.607 10.607z" />
                    </svg>
                    <input
                      type="text"
                      value={searchQuery}
                      onChange={(e) => setSearchQuery(e.target.value)}
                      placeholder="Search title, author, ISBN, location..."
                      className="w-full bg-[#f8fafc] border border-slate-200/80 rounded-xl py-2 pl-9 pr-4 text-[12px] text-slate-700 placeholder-slate-400 focus:outline-none focus:ring-1 focus:ring-slate-300"
                    />
                    {searchQuery && (
                      <button
                        onClick={() => setSearchQuery('')}
                        className="absolute right-3 top-1/2 -translate-y-1/2 text-slate-400 hover:text-slate-600 text-xs font-bold cursor-pointer"
                      >
                        Clear
                      </button>
                    )}
                  </div>
                </div>

                <div>
                  <label className="block text-[9.5px] font-extrabold text-slate-500 tracking-wider uppercase mb-1.5">
                    FILTER BY CATEGORY:
                  </label>
                  <div className="flex flex-wrap gap-1.5">
                    {categoriesList.map((cat) => (
                      <button
                        key={cat}
                        onClick={() => setSelectedCategory(cat)}
                        className={`text-[10px] px-3 py-1 rounded-full transition-all duration-150 cursor-pointer ${selectedCategory === cat
                            ? 'bg-[#8B1A24] text-white font-black shadow-xs'
                            : 'bg-[#f1f5f9] text-slate-600 hover:bg-slate-200 font-bold'
                          }`}
                      >
                        {cat}
                      </button>
                    ))}
                  </div>
                </div>
              </div>

              {/* Book Cards Grid - 3 Columns Exactly Matching User Mockup */}
              {filteredBooks.length === 0 ? (
                <div className="bg-white rounded-[1.25rem] p-10 text-center border border-slate-200/70 shadow-xs">
                  <p className="text-slate-500 font-bold text-xs">No books found matching your search.</p>
                  <button
                    onClick={() => { setSearchQuery(''); setSelectedCategory('All'); }}
                    className="mt-2.5 px-3 py-1.5 bg-[#8B1A24] text-white text-[11px] font-extrabold rounded-lg cursor-pointer"
                  >
                    Reset Filters
                  </button>
                </div>
              ) : (
                <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                  {filteredBooks.map((book) => (
                    <div key={book.id} className="bg-white rounded-[1.25rem] p-4 shadow-xs border border-slate-200/70 flex flex-col justify-between hover:shadow-sm transition-all">
                      <div className="flex gap-3.5">
                        <div className="w-[76px] h-[92px] shrink-0 rounded-lg overflow-hidden border border-slate-100 bg-slate-100 shadow-xs flex items-center justify-center">
                          <img
                            src={book.image}
                            alt={book.title}
                            className="w-full h-full object-cover"
                            onError={(e) => {
                              e.target.style.display = 'none';
                              if (e.target.nextSibling) e.target.nextSibling.style.display = 'flex';
                            }}
                          />
                          <div className="hidden w-full h-full flex-col items-center justify-center p-1 text-center bg-slate-100 text-slate-400 text-[8px] font-bold">
                            {book.title}
                          </div>
                        </div>

                        <div className="flex flex-col flex-1 min-w-0">
                          <div className="flex flex-wrap gap-1 mb-1">
                            {book.categories.map((cat, i) => (
                              <span
                                key={i}
                                className={`text-[8px] font-black uppercase px-1.5 py-0.5 rounded tracking-wider ${cat === 'COURSE RESERVE'
                                    ? 'bg-amber-100 text-amber-800'
                                    : 'bg-[#e0f2fe] text-[#0284c7]'
                                  }`}
                              >
                                {cat}
                              </span>
                            ))}
                          </div>

                          <h3 className="font-extrabold text-[12.5px] text-[#0f172a] leading-snug mb-0.5 line-clamp-2">
                            {book.title}
                          </h3>
                          <p className="text-[10.5px] text-slate-400 font-medium truncate mb-0.5">
                            by {book.author}
                          </p>
                          <p className="text-[9.5px] text-slate-400 font-medium truncate mb-1.5">
                            ISBN: {book.isbn}
                          </p>

                          <div className="flex items-start gap-1 text-slate-500 mt-auto">
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" strokeWidth={2} stroke="currentColor" className="w-3 h-3 shrink-0 mt-0.5 text-[#8B1A24]">
                              <path strokeLinecap="round" strokeLinejoin="round" d="M15 10.5a3 3 0 11-6 0 3 3 0 016 0z" />
                              <path strokeLinecap="round" strokeLinejoin="round" d="M19.5 10.5c0 7.142-7.5 11.25-7.5 11.25S4.5 17.642 4.5 10.5a7.5 7.5 0 1115 0z" />
                            </svg>
                            <span className="text-[10px] font-bold leading-tight text-slate-600 truncate">
                              {book.location}
                            </span>
                          </div>
                        </div>
                      </div>

                      <div className="flex items-center justify-between border-t border-slate-100 pt-2.5 mt-3">
                        <div className="bg-[#ecfdf5] text-[#059669] border border-[#a7f3d0]/60 px-2.5 py-0.5 rounded-full flex items-center gap-1.5">
                          <div className="w-1.5 h-1.5 bg-[#10b981] rounded-full"></div>
                          <span className="text-[9.5px] font-extrabold">
                            {book.available} of {book.total} Copies Available
                          </span>
                        </div>

                        <button
                          onClick={() => setSelectedBook(book)}
                          className="bg-[#1e293b] text-white text-[10.5px] font-extrabold px-3 py-1 rounded-full flex items-center gap-1 hover:bg-[#0f172a] transition-colors cursor-pointer shadow-xs"
                        >
                          <span>View Copies</span>
                          <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" strokeWidth={3} stroke="currentColor" className="w-2.5 h-2.5">
                            <path strokeLinecap="round" strokeLinejoin="round" d="M13.5 4.5L21 12m0 0l-7.5 7.5M21 12H3" />
                          </svg>
                        </button>
                      </div>
                    </div>
                  ))}
                </div>
              )}
            </>
          )}

          {activeTab === 'borrowing' && (
            <div className="flex flex-col gap-4.5 w-full">
              {/* Top Full-Width Card: Your Active Book Loans */}
              <div className="bg-white rounded-[1.25rem] p-6 shadow-xs border border-slate-200/70">
                <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-2 mb-4 pb-1">
                  <div>
                    <div className="flex items-center gap-2">
                      <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" strokeWidth={2.2} stroke="currentColor" className="w-5 h-5 text-[#8B1A24]">
                        <path strokeLinecap="round" strokeLinejoin="round" d="M17.593 3.322c1.1.128 1.907 1.077 1.907 2.185V21L12 17.25 4.5 21V5.507c0-1.108.806-2.057 1.907-2.185a48.507 48.507 0 0111.186 0z" />
                      </svg>
                      <h2 className="text-[16px] font-extrabold text-[#0f172a]">Your Active Book Loans (0)</h2>
                    </div>
                    <p className="text-slate-400 text-[11px] font-medium mt-1">
                      Books currently checked out under your student profile ({user?.username || '2012-00000-SYS'}).
                    </p>
                  </div>
                  <div className="self-start sm:self-auto bg-slate-100 text-slate-700 text-[10px] font-extrabold px-3 py-1.5 rounded-lg shrink-0">
                    Max Allowed: 0 / {user?.role === 'Teacher' ? '10' : user?.role === 'Student' ? '3' : '20'} Books
                  </div>
                </div>

                <div className="border border-slate-100 rounded-2xl py-12 px-6 flex flex-col items-center justify-center bg-slate-50/50 text-center">
                  <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" strokeWidth={1.5} stroke="currentColor" className="w-9 h-9 text-slate-300 mb-2.5">
                    <path strokeLinecap="round" strokeLinejoin="round" d="M12 6.042A8.967 8.967 0 006 3.75c-1.052 0-2.062.18-3 .512v14.25A8.987 8.987 0 016 18c2.305 0 4.408.867 6 2.292m0-14.25a8.966 8.966 0 016-2.292c1.052 0 2.062.18 3 .512v14.25A8.987 8.987 0 0018 18a8.967 8.967 0 00-6 2.292m0-14.25v14.25" />
                  </svg>
                  <h3 className="font-extrabold text-[#0f172a] text-[13px] mb-1">No active book loans at this time.</h3>
                  <p className="text-slate-400 text-[11px] font-medium">
                    Browse the catalog to find available physical books for research or coursework.
                  </p>
                </div>
              </div>

              {/* Bottom 2-Column Grid: Fines Ledger & Book Hold Requests */}
              <div className="grid grid-cols-1 md:grid-cols-2 gap-4.5">
                {/* Left: Library Fines & Clearance Ledger */}
                <div className="bg-white rounded-[1.25rem] p-6 shadow-xs border border-slate-200/70 flex flex-col justify-between">
                  <div>
                    <div className="flex items-center justify-between gap-2 mb-4">
                      <div className="flex items-center gap-2">
                        <span className="text-[#8B1A24] font-black text-lg leading-none">$</span>
                        <h2 className="text-[15px] font-extrabold text-[#0f172a]">Library Fines & Clearance Ledger</h2>
                      </div>
                      <span className="bg-[#d1fae5] text-[#065f46] text-[9.5px] font-black uppercase px-2.5 py-1 rounded tracking-wider">
                        CLEAR ACCOUNT
                      </span>
                    </div>

                    <div className="border border-slate-100 rounded-xl p-4 bg-white shadow-2xs mb-3.5">
                      <div className="flex justify-between items-center mb-1.5">
                        <span className="text-[12px] font-extrabold text-slate-600">Unpaid Library Fines Balance:</span>
                        <span className="text-[17px] font-black text-[#8B1A24]">₱0.00</span>
                      </div>
                      <p className="text-slate-400 text-[10.5px] font-medium leading-relaxed">
                        Calculated @ ₱10.00 / overdue calendar day per unreturned title. Fines must be cleared for graduation & semestral clearance.
                      </p>
                    </div>
                  </div>

                  <div className="border border-sky-200 bg-sky-50/60 rounded-xl p-3.5 flex gap-2.5 items-start mt-auto">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" strokeWidth={2} stroke="currentColor" className="w-4 h-4 shrink-0 text-sky-600 mt-0.5">
                      <path strokeLinecap="round" strokeLinejoin="round" d="M11.25 11.25l.041-.02a.75.75 0 011.063.852l-.708 2.836a.75.75 0 001.063.853l.041-.021M21 12a9 9 0 11-18 0 9 9 0 0118 0zm-9-3.75h.008v.008H12V8.25z" />
                    </svg>
                    <p className="text-[10.5px] text-sky-800 font-medium leading-relaxed">
                      Payments can be settled at the University Cashier (Ground Floor Main Admin) or through online GCash/Bank Deposit clearance verification.
                    </p>
                  </div>
                </div>

                {/* Right: Your Book Hold Requests */}
                <div className="bg-white rounded-[1.25rem] p-6 shadow-xs border border-slate-200/70 flex flex-col justify-between">
                  <div>
                    <div className="flex items-center justify-between gap-2 mb-4">
                      <div className="flex items-center gap-2">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" strokeWidth={2.2} stroke="currentColor" className="w-4 h-4 text-amber-500">
                          <path strokeLinecap="round" strokeLinejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z" />
                        </svg>
                        <h2 className="text-[15px] font-extrabold text-[#0f172a]">Your Book Hold Requests (0)</h2>
                      </div>
                      <span className="text-[10px] font-extrabold text-slate-400 uppercase tracking-wider">
                        Queue Status
                      </span>
                    </div>

                    <div className="border border-slate-100 rounded-xl py-6 px-4 bg-white flex flex-col items-center justify-center text-center shadow-2xs mb-3.5">
                      <h3 className="font-extrabold text-[#0f172a] text-[13px] mb-1">No active book hold requests.</h3>
                      <p className="text-slate-400 text-[10.5px] font-medium">
                        When a book is out of stock, click "View Copies" to join the hold queue.
                      </p>
                    </div>
                  </div>

                  <div className="border border-amber-200 bg-amber-50/60 rounded-xl p-3.5 flex gap-2.5 items-start mt-auto">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" strokeWidth={2} stroke="currentColor" className="w-4 h-4 shrink-0 text-amber-600 mt-0.5">
                      <path strokeLinecap="round" strokeLinejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                    </svg>
                    <p className="text-[10.5px] text-amber-900 font-medium leading-relaxed">
                      Books held under "Ready for Pickup" are reserved at the Circulation Desk for 48 hours before proceeding to the next student in queue.
                    </p>
                  </div>
                </div>
              </div>
            </div>
          )}

          {activeTab === 'reserves' && (
            <div className="flex flex-col gap-4">
              <div className="bg-white rounded-[1.25rem] p-5 shadow-xs border border-slate-200/70 flex flex-col md:flex-row items-start md:items-center justify-between gap-3">
                <div>
                  <div className="flex items-center gap-2 mb-1">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" strokeWidth={2.5} stroke="currentColor" className="w-4 h-4 text-sky-600">
                      <path strokeLinecap="round" strokeLinejoin="round" d="M12 2.25L2.25 7.5l9.75 5.25L21.75 7.5 12 2.25zM2.25 12l9.75 5.25L21.75 12M2.25 16.5l9.75 5.25L21.75 16.5" />
                    </svg>
                    <h2 className="text-[16px] font-extrabold text-[#0f172a]">Course Reserve Books & Syllabus References</h2>
                  </div>
                  <p className="text-slate-400 text-[11px] font-medium">
                    Textbooks set aside by professors for 2-Hour In-Library Desk Reference or Overnight study.
                  </p>
                </div>

                <button
                  onClick={() => setShowReserveModal(true)}
                  className="bg-[#8B1A24] text-white text-[11px] font-extrabold py-2 px-4 rounded-xl hover:bg-[#6b141c] transition-colors flex items-center gap-1.5 shrink-0 cursor-pointer shadow-xs"
                >
                  <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" strokeWidth={3} stroke="currentColor" className="w-3 h-3">
                    <path strokeLinecap="round" strokeLinejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                  </svg>
                  Request Course Reserve
                </button>
              </div>

              <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                {mockReserves.map(reserve => (
                  <div key={reserve.id} className="border border-sky-200 rounded-[1.25rem] p-4 bg-white shadow-xs flex flex-col justify-between gap-2.5">
                    <div>
                      <div className="flex gap-1.5 mb-1.5">
                        <span className="bg-[#0369a1] text-white text-[8.5px] font-extrabold px-2 py-0.5 rounded-full uppercase tracking-wider">
                          COURSE: {reserve.course}
                        </span>
                        <span className="border border-emerald-300 bg-emerald-50 text-emerald-700 text-[8.5px] font-extrabold px-2 py-0.5 rounded-full">
                          {reserve.status}
                        </span>
                      </div>
                      <h4 className="font-extrabold text-[13px] text-[#0f172a] leading-tight mb-1.5">{reserve.title}</h4>

                      <div className="text-[10.5px] text-slate-500 flex flex-col gap-0.5">
                        <p>Reserve Type: <span className="font-extrabold text-[#0f172a]">{reserve.type}</span></p>
                        <p>Requested by: <span className="font-extrabold text-[#0f172a]">{reserve.requester}</span> <span className="text-slate-400">({reserve.role}) on {reserve.date}</span></p>
                      </div>
                    </div>

                    <div className="border border-slate-100 rounded-xl p-2.5 text-[10.5px] text-slate-600 italic bg-slate-50">
                      "{reserve.note}"
                    </div>
                  </div>
                ))}
              </div>
            </div>
          )}

          {/* Circulation Desk (Desktop) - Only on SuperAdmin and Admin */}
          {activeTab === 'circulation' && isSuperAdmin && (
            <div className="bg-white rounded-[1.25rem] p-6 shadow-xs border border-slate-200/70">
              <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-2 mb-4 pb-2 border-b border-slate-100">
                <div>
                  <h2 className="text-[18px] font-extrabold text-[#0f172a]">Circulation Log & Active Loans</h2>
                  <p className="text-slate-400 text-[11px] font-medium mt-0.5">
                    Live barcode tracking of physical copies checked out to students and faculty.
                  </p>
                </div>
                <span className="self-start sm:self-auto bg-slate-100 text-slate-700 text-[9.5px] font-extrabold px-3 py-1.5 rounded-md uppercase tracking-wider">
                  Circulation Desk #01
                </span>
              </div>

              <div className="overflow-x-auto">
                <table className="w-full text-left border-collapse">
                  <thead>
                    <tr className="border-b border-slate-100">
                      <th className="py-3 px-3 text-[10px] font-black text-slate-400 tracking-wider uppercase">COPY BARCODE</th>
                      <th className="py-3 px-3 text-[10px] font-black text-slate-400 tracking-wider uppercase">BOOK TITLE</th>
                      <th className="py-3 px-3 text-[10px] font-black text-slate-400 tracking-wider uppercase">BORROWER</th>
                      <th className="py-3 px-3 text-[10px] font-black text-slate-400 tracking-wider uppercase">DUE DATE</th>
                      <th className="py-3 px-3 text-[10px] font-black text-slate-400 tracking-wider uppercase">ACTIONS</th>
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-slate-100/70">
                    {mockCirculation.map(item => (
                      <tr key={item.id} className="hover:bg-slate-50/50 transition-colors">
                        <td className="py-3.5 px-3 align-middle">
                          <div className="font-mono text-[12px] font-extrabold text-[#0f172a]">{item.copyId}</div>
                          <div className="font-mono text-[9.5px] text-slate-400 mt-0.5">{item.barcode}</div>
                        </td>
                        <td className="py-3.5 px-3 align-middle">
                          <div className="font-extrabold text-[12.5px] text-[#0f172a] leading-tight">{item.title}</div>
                          <div className="text-[10.5px] text-slate-400 font-medium mt-0.5">{item.author}</div>
                        </td>
                        <td className="py-3.5 px-3 align-middle">
                          <div className="font-extrabold text-[12.5px] text-[#0f172a] leading-tight">{item.borrower}</div>
                          <div className="text-[10px] text-slate-400 font-medium mt-0.5">ID: {item.borrowerId}</div>
                        </td>
                        <td className="py-3.5 px-3 align-middle">
                          <span className="font-extrabold text-[11px] text-rose-600 uppercase tracking-tight">
                            OVERDUE: {item.overdueDate}
                          </span>
                        </td>
                        <td className="py-3.5 px-3 align-middle">
                          <button
                            onClick={() => alert(`Checked-in copy ${item.copyId} successfully!`)}
                            className="bg-[#047857] hover:bg-[#065f46] text-white text-[11px] font-extrabold py-2 px-4 rounded-xl transition-colors cursor-pointer shadow-xs whitespace-nowrap"
                          >
                            Check-In Copy
                          </button>
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            </div>
          )}

          {/* Admin Fines & Queue (Desktop) - Only on SuperAdmin and Admin */}
          {activeTab === 'fines' && isSuperAdmin && (
            <div className="flex flex-col gap-4.5 w-full">
              {/* 1. Hold Request Queue Card (Full Width) */}
              <div className="bg-white rounded-[1.25rem] p-6 shadow-xs border border-slate-200/70">
                <div className="flex items-center justify-between gap-2 mb-4 pb-1">
                  <div>
                    <h2 className="text-[18px] font-extrabold text-[#0f172a]">Hold Request Queue</h2>
                    <p className="text-slate-400 text-[11px] font-medium mt-0.5">
                      Reserves queue placement when stock is 0.
                    </p>
                  </div>
                  <span className="border border-amber-300 bg-amber-50/60 text-amber-800 text-[10px] font-extrabold px-3 py-1 rounded-lg">
                    2 Requests
                  </span>
                </div>

                <div className="flex flex-col gap-3">
                  {mockHoldRequests.map(request => (
                    <div key={request.id} className="border border-slate-200/80 rounded-xl p-4 bg-white flex flex-col sm:flex-row sm:items-center justify-between gap-3 shadow-2xs">
                      <div>
                        <div className="flex items-center gap-2 mb-0.5">
                          <span className="bg-[#8B1A24] text-white text-[9px] font-black uppercase px-2 py-0.5 rounded leading-none">
                            POS {request.pos}
                          </span>
                          <h3 className="font-extrabold text-[13px] text-[#0f172a] leading-tight">
                            {request.bookTitle}
                          </h3>
                        </div>
                        <p className="text-[11px] text-slate-400 font-medium">
                          Requested by: <span className="font-extrabold text-[#0f172a]">{request.requester}</span> ({request.role}) on {request.date}
                        </p>
                      </div>

                      <button
                        onClick={() => alert(`Cancelled hold request for "${request.bookTitle}".`)}
                        className="bg-slate-100 hover:bg-slate-200 text-slate-700 text-[11px] font-extrabold py-2 px-4 rounded-xl transition-colors cursor-pointer self-end sm:self-auto shrink-0"
                      >
                        Cancel Hold
                      </button>
                    </div>
                  ))}
                </div>
              </div>

              {/* 2. Unpaid Library Fines Card (Full Width Data Table) */}
              <div className="bg-white rounded-[1.25rem] p-6 shadow-xs border border-slate-200/70">
                <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-2 mb-4 pb-2 border-b border-slate-100">
                  <div>
                    <h2 className="text-[18px] font-extrabold text-[#0f172a]">Unpaid Library Fines</h2>
                    <p className="text-slate-400 text-[11px] font-medium mt-0.5">
                      Late return fees calculated @ ₱10.00/day for students.
                    </p>
                  </div>
                  <span className="self-start sm:self-auto border border-pink-200 bg-pink-50 text-[#be123c] text-[10px] font-extrabold px-3 py-1.5 rounded-lg">
                    ₱10.00 / Overdue Day
                  </span>
                </div>

                <div className="overflow-x-auto">
                  <table className="w-full text-left border-collapse">
                    <thead>
                      <tr className="border-b border-slate-100">
                        <th className="py-3 px-3 text-[10px] font-black text-slate-400 tracking-wider uppercase">REF ID</th>
                        <th className="py-3 px-3 text-[10px] font-black text-slate-400 tracking-wider uppercase">BORROWER NAME</th>
                        <th className="py-3 px-3 text-[10px] font-black text-slate-400 tracking-wider uppercase">BOOK TITLE</th>
                        <th className="py-3 px-3 text-[10px] font-black text-slate-400 tracking-wider uppercase">DAYS LATE</th>
                        <th className="py-3 px-3 text-[10px] font-black text-slate-400 tracking-wider uppercase">AMOUNT</th>
                      </tr>
                    </thead>
                    <tbody className="divide-y divide-slate-100/70">
                      {mockFines.map(fine => (
                        <tr key={fine.id} className="hover:bg-slate-50/50 transition-colors">
                          <td className="py-3.5 px-3 align-middle font-mono text-[11.5px] font-extrabold text-[#0f172a]">
                            {fine.refId}
                          </td>
                          <td className="py-3.5 px-3 align-middle font-extrabold text-[12.5px] text-[#0f172a]">
                            {fine.borrowerName}
                          </td>
                          <td className="py-3.5 px-3 align-middle font-extrabold text-[12.5px] text-[#0f172a]">
                            {fine.bookTitle}
                          </td>
                          <td className="py-3.5 px-3 align-middle font-extrabold text-[11.5px] text-rose-600">
                            {fine.daysLate}
                          </td>
                          <td className="py-3.5 px-3 align-middle font-black text-[12.5px] text-[#0f172a]">
                            {fine.amount}
                          </td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
              </div>
            </div>
          )}

          {/* Add Title & Physical Copies Tab (Desktop) - Only on SuperAdmin and Admin */}
          {activeTab === 'add_title' && isSuperAdmin && (
            <div className="max-w-2xl mx-auto w-full bg-white rounded-[1.25rem] p-6 sm:p-8 shadow-xs border border-slate-200/70">
              <div className="mb-6 pb-4 border-b border-slate-100 text-center">
                {/* Neutral badge - NO yellow background */}
                <span className="inline-block bg-slate-100 text-slate-800 border border-slate-200 text-[9.5px] font-black uppercase px-2.5 py-1 rounded tracking-wider mb-2">
                  LIBRARY INVENTORY MANAGEMENT
                </span>
                <h2 className="text-[20px] font-extrabold text-[#0f172a] leading-tight">
                  Register New Title & Physical Copies
                </h2>
                <p className="text-slate-400 text-[11.5px] font-medium mt-1">
                  Enter complete book bibliographic details and initial physical copy inventory.
                </p>
              </div>

              <form onSubmit={handleAddBook} className="flex flex-col gap-4 w-full">
                <div>
                  <label className="block text-[11px] font-extrabold text-[#0f172a] mb-1.5">Book Title *</label>
                  <input
                    type="text"
                    required
                    value={newBook.title}
                    onChange={(e) => setNewBook({ ...newBook, title: e.target.value })}
                    placeholder="e.g. Operating System Concepts 10th Ed."
                    className="w-full border border-slate-200 rounded-xl py-2.5 px-3 text-[13px] focus:outline-none focus:ring-1 focus:ring-[#8B1A24] text-[#0f172a] bg-[#f8fafc]"
                  />
                </div>

                <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                  <div>
                    <label className="block text-[11px] font-extrabold text-[#0f172a] mb-1.5">Author *</label>
                    <input
                      type="text"
                      required
                      value={newBook.author}
                      onChange={(e) => setNewBook({ ...newBook, author: e.target.value })}
                      placeholder="e.g. Abraham Silberschatz"
                      className="w-full border border-slate-200 rounded-xl py-2.5 px-3 text-[13px] focus:outline-none focus:ring-1 focus:ring-[#8B1A24] text-[#0f172a] bg-[#f8fafc]"
                    />
                  </div>

                  <div>
                    <label className="block text-[11px] font-extrabold text-[#0f172a] mb-1.5">ISBN-13 *</label>
                    <input
                      type="text"
                      required
                      value={newBook.isbn}
                      onChange={(e) => setNewBook({ ...newBook, isbn: e.target.value })}
                      placeholder="e.g. 978-1118063330"
                      className="w-full border border-slate-200 rounded-xl py-2.5 px-3 text-[13px] focus:outline-none focus:ring-1 focus:ring-[#8B1A24] text-[#0f172a] bg-[#f8fafc]"
                    />
                  </div>
                </div>

                <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                  <div>
                    <label className="block text-[11px] font-extrabold text-[#0f172a] mb-1.5">Category</label>
                    <select
                      value={newBook.category}
                      onChange={(e) => setNewBook({ ...newBook, category: e.target.value })}
                      className="w-full border border-slate-200 rounded-xl py-2.5 px-3 text-[13px] focus:outline-none focus:ring-1 focus:ring-[#8B1A24] text-[#0f172a] font-bold bg-[#f8fafc] cursor-pointer"
                    >
                      <option value="IT & Computer Science">IT & Computer Science</option>
                      <option value="Mathematics & Sciences">Mathematics & Sciences</option>
                      <option value="General Education">General Education</option>
                      <option value="Literature & Arts">Literature & Arts</option>
                      <option value="Research & Journals">Research & Journals</option>
                    </select>
                  </div>

                  <div>
                    <label className="block text-[11px] font-extrabold text-[#0f172a] mb-1.5">Copy Quantity</label>
                    <input
                      type="number"
                      min="1"
                      max="50"
                      value={newBook.copies}
                      onChange={(e) => setNewBook({ ...newBook, copies: e.target.value })}
                      className="w-full border border-slate-200 rounded-xl py-2.5 px-3 text-[13px] focus:outline-none focus:ring-1 focus:ring-[#8B1A24] text-[#0f172a] font-extrabold bg-[#f8fafc]"
                    />
                  </div>
                </div>

                <div>
                  <label className="block text-[11px] font-extrabold text-[#0f172a] mb-1.5">Shelf Location</label>
                  <input
                    type="text"
                    value={newBook.location}
                    onChange={(e) => setNewBook({ ...newBook, location: e.target.value })}
                    placeholder="e.g. Floor 2 - Shelf CS-101"
                    className="w-full border border-slate-200 rounded-xl py-2.5 px-3 text-[13px] focus:outline-none focus:ring-1 focus:ring-[#8B1A24] text-[#0f172a] bg-[#f8fafc]"
                  />
                </div>

                <div className="flex items-center gap-2 mt-1">
                  <input
                    type="checkbox"
                    id="desktopCourseReserveCheck"
                    checked={newBook.isCourseReserve}
                    onChange={(e) => setNewBook({ ...newBook, isCourseReserve: e.target.checked })}
                    className="w-4 h-4 rounded text-[#8B1A24] focus:ring-[#8B1A24] cursor-pointer"
                  />
                  <label htmlFor="desktopCourseReserveCheck" className="text-[12px] font-bold text-[#0f172a] cursor-pointer">
                    Mark as Course Reserve (In-Library Reference Only)
                  </label>
                </div>

                <div className="flex justify-end gap-3 mt-4 pt-3 border-t border-slate-100">
                  <button
                    type="button"
                    onClick={() => setActiveTab('catalog')}
                    className="px-5 py-2 border border-slate-200 rounded-xl text-slate-700 text-[12px] font-extrabold hover:bg-slate-50 cursor-pointer"
                  >
                    Cancel
                  </button>
                  <button
                    type="submit"
                    className="px-5 py-2 bg-[#8B1A24] text-white rounded-xl text-[12px] font-extrabold hover:bg-[#6b141c] transition-colors shadow-xs cursor-pointer"
                  >
                    Save Title & Register Copies
                  </button>
                </div>
              </form>
            </div>
          )}
        </div>
      </div>

      {/* ========================================================================= */}
      {/* 📋 SHARED MODALS: View Copies, Request Course Reserve                      */}
      {/* ========================================================================= */}

      {/* Modal: View Copies & Physical RFID Status */}
      {selectedBook && (
        <div className="fixed inset-0 bg-black/50 backdrop-blur-xs flex items-center justify-center p-4 z-50">
          <div className="bg-white rounded-[1.5rem] p-5 lg:p-6 shadow-2xl border border-slate-100 max-w-md w-full">
            <div className="flex justify-between items-start mb-3">
              <div>
                <span className="inline-block bg-sky-50 text-sky-700 text-[8.5px] font-black uppercase px-2 py-0.5 rounded tracking-wider mb-1">
                  PHYSICAL COPY INVENTORY
                </span>
                <h2 className="text-[16px] font-extrabold text-[#0f172a] leading-tight">
                  {selectedBook.title}
                </h2>
                <p className="text-slate-400 text-[11px] font-medium mt-0.5">by {selectedBook.author}</p>
              </div>
              <button
                onClick={() => setSelectedBook(null)}
                className="text-slate-400 hover:text-slate-600 text-lg font-bold p-1 cursor-pointer"
              >
                ✕
              </button>
            </div>

            <div className="bg-slate-50 rounded-xl p-3 mb-3 text-[11px] font-medium text-slate-600 flex justify-between items-center border border-slate-100">
              <span>Shelf: <strong className="text-[#0f172a]">{selectedBook.location}</strong></span>
              <span className="bg-emerald-100 text-emerald-800 text-[9.5px] font-black px-2 py-0.5 rounded-full">
                {selectedBook.available} / {selectedBook.total} Available
              </span>
            </div>

            <div className="flex flex-col gap-2 mb-4 max-h-48 overflow-y-auto pr-1">
              {Array.from({ length: selectedBook.total }).map((_, idx) => {
                const isAvailable = idx < selectedBook.available;
                const copyBarcode = `CPY-${selectedBook.isbn.slice(-4)}-0${idx + 1}`;
                return (
                  <div key={idx} className="border border-slate-200 rounded-xl p-2.5 flex items-center justify-between bg-white shadow-2xs">
                    <div>
                      <div className="font-mono text-[11px] font-extrabold text-[#0f172a]">{copyBarcode}</div>
                      <div className="text-[9px] text-slate-400 font-medium">RFID Tag: #RF-{1000 + selectedBook.id * 10 + idx}</div>
                    </div>
                    <span className={`text-[9.5px] font-extrabold px-2 py-0.5 rounded-full ${isAvailable ? 'bg-emerald-50 text-emerald-700 border border-emerald-200' : 'bg-amber-50 text-amber-700 border border-amber-200'
                      }`}>
                      {isAvailable ? 'Available on Shelf' : 'Checked Out'}
                    </span>
                  </div>
                );
              })}
            </div>

            <div className="flex justify-end gap-2.5 pt-2.5 border-t border-slate-100">
              <button
                onClick={() => setSelectedBook(null)}
                className="px-4 py-2 border border-slate-200 rounded-xl text-slate-700 text-[11px] font-extrabold hover:bg-slate-50 cursor-pointer"
              >
                Close
              </button>
              <button
                onClick={() => {
                  alert(`Borrow/Hold request submitted for "${selectedBook.title}"!`);
                  setSelectedBook(null);
                }}
                className="px-4 py-2 bg-[#8B1A24] text-white rounded-xl text-[11px] font-extrabold hover:bg-[#6b141c] transition-colors shadow-xs cursor-pointer"
              >
                Request Checkout / Hold
              </button>
            </div>
          </div>
        </div>
      )}

      {/* Modal: Request Course Reserve */}
      {showReserveModal && (
        <div className="fixed inset-0 bg-black/50 backdrop-blur-xs flex items-center justify-center p-4 z-50">
          <div className="bg-white rounded-[1.5rem] p-5 shadow-2xl border border-slate-100 max-w-md w-full">
            <div className="flex justify-between items-start mb-3">
              <div>
                <span className="inline-block bg-[#0369a1] text-white text-[8.5px] font-black uppercase px-2 py-0.5 rounded tracking-wider mb-1">
                  FACULTY REQUEST
                </span>
                <h2 className="text-[16px] font-extrabold text-[#0f172a] leading-tight">
                  Request Course Reserve Allocation
                </h2>
              </div>
              <button
                onClick={() => setShowReserveModal(false)}
                className="text-slate-400 hover:text-slate-600 text-lg font-bold p-1 cursor-pointer"
              >
                ✕
              </button>
            </div>

            <form onSubmit={(e) => {
              e.preventDefault();
              alert('Course reserve request submitted for faculty syllabus review!');
              setShowReserveModal(false);
            }} className="flex flex-col gap-3">
              <div>
                <label className="block text-[10.5px] font-extrabold text-[#0f172a] mb-1">Course Code *</label>
                <input required type="text" placeholder="e.g. IT 311 or CS 102" className="w-full border border-slate-200 rounded-xl py-1.5 px-3 text-xs font-medium text-[#0f172a]" />
              </div>
              <div>
                <label className="block text-[10.5px] font-extrabold text-[#0f172a] mb-1">Book Title / Reference *</label>
                <input required type="text" placeholder="e.g. Database Management Systems 4th Ed." className="w-full border border-slate-200 rounded-xl py-1.5 px-3 text-xs font-medium text-[#0f172a]" />
              </div>
              <div>
                <label className="block text-[10.5px] font-extrabold text-[#0f172a] mb-1">Reserve Type</label>
                <select className="w-full border border-slate-200 rounded-xl py-1.5 px-3 text-xs font-bold text-[#0f172a] bg-white">
                  <option>2-Hour In-Library Desk Reference</option>
                  <option>Overnight Checkout Reserve</option>
                  <option>3-Day Extended Course Reserve</option>
                </select>
              </div>
              <div>
                <label className="block text-[10.5px] font-extrabold text-[#0f172a] mb-1">Notes / Syllabus Reference</label>
                <textarea rows="2" placeholder="Required textbook for upcoming midterm exam..." className="w-full border border-slate-200 rounded-xl py-1.5 px-3 text-xs font-medium text-[#0f172a]"></textarea>
              </div>

              <div className="flex justify-end gap-2.5 pt-2.5 border-t border-slate-100 mt-1">
                <button
                  type="button"
                  onClick={() => setShowReserveModal(false)}
                  className="px-3.5 py-1.5 border border-slate-200 rounded-xl text-xs font-extrabold hover:bg-slate-50 cursor-pointer"
                >
                  Cancel
                </button>
                <button
                  type="submit"
                  className="px-3.5 py-1.5 bg-[#8B1A24] text-white rounded-xl text-xs font-extrabold hover:bg-[#6b141c] cursor-pointer"
                >
                  Submit Request
                </button>
              </div>
            </form>
          </div>
        </div>
      )}

      {/* Modal: Register New Title & Physical Copies Pop-Up */}
      {showAddBookModal && (
        <div 
          onClick={(e) => { if (e.target === e.currentTarget) setShowAddBookModal(false); }}
          className="fixed inset-0 bg-black/60 backdrop-blur-xs flex items-center justify-center p-4 z-50 animate-in fade-in duration-200"
        >
          <div className="bg-white rounded-[1.5rem] p-6 shadow-2xl border border-slate-100 max-w-lg w-full max-h-[92vh] overflow-y-auto relative">
            <div className="flex justify-between items-start mb-2">
              <div>
                <span className="inline-block bg-[#fef3c7] text-[#92400e] border border-[#fde68a] text-[9.5px] font-black uppercase px-2.5 py-0.5 rounded tracking-wider mb-1">
                  LIBRARY INVENTORY MANAGEMENT
                </span>
                <h2 className="text-[19px] font-black text-[#0f172a] leading-tight tracking-tight">
                  Register New Title & Physical Copies
                </h2>
              </div>
              <button 
                type="button"
                onClick={() => setShowAddBookModal(false)}
                className="text-slate-400 hover:text-slate-600 text-lg font-bold p-1 cursor-pointer transition-colors"
              >
                ✕
              </button>
            </div>

            <form onSubmit={handleAddBook} className="flex flex-col gap-3.5 mt-3">
              <div>
                <label className="block text-[11px] font-extrabold text-[#0f172a] mb-1">Book Title *</label>
                <input 
                  type="text" 
                  required
                  value={newBook.title}
                  onChange={(e) => setNewBook({ ...newBook, title: e.target.value })}
                  placeholder="e.g. Operating System Concepts 10th Ed."
                  className="w-full border border-slate-200 rounded-xl py-2 px-3 text-[12.5px] focus:outline-none focus:ring-1 focus:ring-[#8B1A24] text-[#0f172a] bg-[#f8fafc]"
                />
              </div>

              <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <div>
                  <label className="block text-[11px] font-extrabold text-[#0f172a] mb-1">Author *</label>
                  <input 
                    type="text" 
                    required
                    value={newBook.author}
                    onChange={(e) => setNewBook({ ...newBook, author: e.target.value })}
                    placeholder="e.g. Abraham Silberschatz"
                    className="w-full border border-slate-200 rounded-xl py-2 px-3 text-[12.5px] focus:outline-none focus:ring-1 focus:ring-[#8B1A24] text-[#0f172a] bg-[#f8fafc]"
                  />
                </div>

                <div>
                  <label className="block text-[11px] font-extrabold text-[#0f172a] mb-1">ISBN-13 *</label>
                  <input 
                    type="text" 
                    required
                    value={newBook.isbn}
                    onChange={(e) => setNewBook({ ...newBook, isbn: e.target.value })}
                    placeholder="e.g. 978-1118063330"
                    className="w-full border border-slate-200 rounded-xl py-2 px-3 text-[12.5px] focus:outline-none focus:ring-1 focus:ring-[#8B1A24] text-[#0f172a] bg-[#f8fafc]"
                  />
                </div>
              </div>

              <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <div>
                  <label className="block text-[11px] font-extrabold text-[#0f172a] mb-1">Category</label>
                  <select 
                    value={newBook.category}
                    onChange={(e) => setNewBook({ ...newBook, category: e.target.value })}
                    className="w-full border border-slate-200 rounded-xl py-2 px-3 text-[12.5px] focus:outline-none focus:ring-1 focus:ring-[#8B1A24] text-[#0f172a] font-bold bg-[#f8fafc] cursor-pointer"
                  >
                    <option value="IT & Computer Science">IT & Computer Science</option>
                    <option value="Mathematics & Sciences">Mathematics & Sciences</option>
                    <option value="General Education">General Education</option>
                    <option value="Literature & Arts">Literature & Arts</option>
                    <option value="Research & Journals">Research & Journals</option>
                  </select>
                </div>

                <div>
                  <label className="block text-[11px] font-extrabold text-[#0f172a] mb-1">Copy Quantity</label>
                  <input 
                    type="number" 
                    min="1" 
                    max="50"
                    value={newBook.copies}
                    onChange={(e) => setNewBook({ ...newBook, copies: e.target.value })}
                    className="w-full border border-slate-200 rounded-xl py-2 px-3 text-[12.5px] focus:outline-none focus:ring-1 focus:ring-[#8B1A24] text-[#0f172a] font-extrabold bg-[#f8fafc]"
                  />
                </div>
              </div>

              <div>
                <label className="block text-[11px] font-extrabold text-[#0f172a] mb-1">Shelf Location</label>
                <input 
                  type="text" 
                  value={newBook.location}
                  onChange={(e) => setNewBook({ ...newBook, location: e.target.value })}
                  placeholder="Floor 2 - Shelf CS-101"
                  className="w-full border border-slate-200 rounded-xl py-2 px-3 text-[12.5px] focus:outline-none focus:ring-1 focus:ring-[#8B1A24] text-[#0f172a] bg-[#f8fafc]"
                />
              </div>

              <div className="flex justify-end items-center gap-2.5 pt-3 border-t border-slate-100 mt-1">
                <button 
                  type="button"
                  onClick={() => setShowAddBookModal(false)}
                  className="px-4 py-2 border border-slate-200 rounded-xl text-slate-700 text-[11.5px] font-extrabold hover:bg-slate-50 cursor-pointer"
                >
                  Cancel
                </button>
                <button 
                  type="submit"
                  className="px-4 py-2 bg-[#8B1A24] text-white rounded-xl text-[11.5px] font-extrabold hover:bg-[#6b141c] transition-colors shadow-xs cursor-pointer"
                >
                  Save Title & Register Copies
                </button>
              </div>
            </form>
          </div>
        </div>
      )}

    </div>
  );
}
