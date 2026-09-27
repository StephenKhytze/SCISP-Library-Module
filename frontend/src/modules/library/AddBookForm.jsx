import React from 'react';
import { Loader2, X } from 'lucide-react';
import { useEffect } from 'react';
import CategorySelect from './CategorySelect';

export default function AddBookForm({
  isOpen,

  newBook,
  setNewBook,
  handleAddBook,
  onCancel,
  actionBusy,
  categories,
  onCategoriesChanged,
  canManageCategories = false,
}) {
  useEffect(() => {
    if (isOpen) {
      document.body.style.overflow = 'hidden';
    } else {
      document.body.style.overflow = 'unset';
    }
    return () => {
      document.body.style.overflow = 'unset';
    };
  }, [isOpen]);

  if (!isOpen) return null;

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center p-4 sm:p-6">
      <div 
        className="absolute inset-0 bg-black/50 backdrop-blur-sm anim-fade-in" 
        onClick={onCancel}
        aria-hidden="true"
      />
      <div 
        className="relative z-10 bg-white rounded-2xl w-full max-w-3xl shadow-2xl flex flex-col max-h-[90vh] overflow-hidden anim-zoom-in"
        role="dialog"
        aria-modal="true"
      >
        <div className="flex items-center justify-between p-5 sm:p-6 border-b border-slate-100 shrink-0">
          <div>
            <h2 className="text-[18px] font-black text-[#0f172a] leading-tight">Register New Title</h2>
            <p className="text-slate-400 text-[11px] font-medium mt-1">
              Enter complete book bibliographic details and initial physical copy inventory.
            </p>
          </div>
          <button 
            onClick={onCancel}
            type="button"
            className="p-2 text-slate-400 hover:text-slate-600 hover:bg-slate-100 rounded-full transition-colors"
          >
            <X className="w-5 h-5" />
          </button>
        </div>
        
        <div className="p-5 sm:p-6 overflow-y-auto">
          <form onSubmit={handleAddBook} className="flex flex-col gap-5 w-full">
      {/* BASIC DETAILS */}
      <div className="bg-slate-50 p-4 rounded-xl border border-slate-100 flex flex-col gap-4">
        <h3 className="text-[10px] font-black text-slate-400 uppercase tracking-wider">Basic Details</h3>
        <div>
          <label className="block text-[11px] font-extrabold text-[#0f172a] mb-1.5">Book Title *</label>
          <input
            type="text"
            required
            value={newBook.title}
            onChange={(e) => setNewBook({ ...newBook, title: e.target.value })}
            placeholder="e.g. Operating System Concepts 10th Ed."
            className="w-full border border-slate-200 rounded-lg py-2.5 px-3 text-[13px] focus:outline-none focus:ring-1 focus:ring-[#8B1A24] text-[#0f172a] bg-white font-medium"
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
              className="w-full border border-slate-200 rounded-lg py-2.5 px-3 text-[13px] focus:outline-none focus:ring-1 focus:ring-[#8B1A24] text-[#0f172a] bg-white font-medium"
            />
          </div>
          <div>
            <label htmlFor="add-book-category" className="block text-[11px] font-extrabold text-[#0f172a] mb-1.5">Category</label>
            <CategorySelect
              id="add-book-category"
              value={newBook.category}
              onChange={(next) => setNewBook({ ...newBook, category: next })}
              categories={categories}
              onCategoriesChanged={onCategoriesChanged}
              canCreate={canManageCategories}
              required
            />
          </div>
        </div>
      </div>

      {/* PUBLICATION DETAILS */}
      <div className="bg-slate-50 p-4 rounded-xl border border-slate-100 flex flex-col gap-4">
        <h3 className="text-[10px] font-black text-slate-400 uppercase tracking-wider">Publication Details</h3>
        <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
          <div>
            <label className="block text-[11px] font-extrabold text-[#0f172a] mb-1.5">ISBN-13 *</label>
            <input
              type="text"
              required
              value={newBook.isbn}
              onChange={(e) => setNewBook({ ...newBook, isbn: e.target.value })}
              placeholder="e.g. 978-1118063330"
              className="w-full border border-slate-200 rounded-lg py-2.5 px-3 text-[13px] focus:outline-none focus:ring-1 focus:ring-[#8B1A24] text-[#0f172a] bg-white font-medium"
            />
          </div>
          <div>
            <label className="block text-[11px] font-extrabold text-[#0f172a] mb-1.5">Edition <span className="font-medium text-slate-400">(Optional)</span></label>
            <input
              type="text"
              value={newBook.edition || ''}
              onChange={(e) => setNewBook({ ...newBook, edition: e.target.value })}
              placeholder="e.g. 10th Edition"
              className="w-full border border-slate-200 rounded-lg py-2.5 px-3 text-[13px] focus:outline-none focus:ring-1 focus:ring-[#8B1A24] text-[#0f172a] bg-white font-medium"
            />
          </div>
          <div>
            <label className="block text-[11px] font-extrabold text-[#0f172a] mb-1.5">Publisher <span className="font-medium text-slate-400">(Optional)</span></label>
            <input
              type="text"
              value={newBook.publisher || ''}
              onChange={(e) => setNewBook({ ...newBook, publisher: e.target.value })}
              placeholder="e.g. Wiley"
              className="w-full border border-slate-200 rounded-lg py-2.5 px-3 text-[13px] focus:outline-none focus:ring-1 focus:ring-[#8B1A24] text-[#0f172a] bg-white font-medium"
            />
          </div>
          <div>
            <label className="block text-[11px] font-extrabold text-[#0f172a] mb-1.5">Publication Year <span className="font-medium text-slate-400">(Optional)</span></label>
            <input
              type="number"
              value={newBook.publication_year || ''}
              onChange={(e) => setNewBook({ ...newBook, publication_year: e.target.value })}
              placeholder="e.g. 2018"
              className="w-full border border-slate-200 rounded-lg py-2.5 px-3 text-[13px] focus:outline-none focus:ring-1 focus:ring-[#8B1A24] text-[#0f172a] bg-white font-medium"
            />
          </div>
        </div>
      </div>

      {/* LIBRARY DETAILS */}
      <div className="bg-slate-50 p-4 rounded-xl border border-slate-100 flex flex-col gap-4">
        <h3 className="text-[10px] font-black text-slate-400 uppercase tracking-wider">Library Details</h3>
        <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
          <div>
            <label className="block text-[11px] font-extrabold text-[#0f172a] mb-1.5">Shelf Location</label>
            <input
              type="text"
              value={newBook.location}
              onChange={(e) => setNewBook({ ...newBook, location: e.target.value })}
              placeholder="e.g. Floor 2 - Shelf CS-101"
              className="w-full border border-slate-200 rounded-lg py-2.5 px-3 text-[13px] focus:outline-none focus:ring-1 focus:ring-[#8B1A24] text-[#0f172a] bg-white font-medium"
            />
          </div>
          <div>
            <label className="block text-[11px] font-extrabold text-[#0f172a] mb-1.5">Initial Physical Copies</label>
            <input
              type="number"
              min="1"
              max="50"
              value={newBook.copies}
              onChange={(e) => setNewBook({ ...newBook, copies: e.target.value })}
              className="w-full border border-slate-200 rounded-lg py-2.5 px-3 text-[13px] focus:outline-none focus:ring-1 focus:ring-[#8B1A24] text-[#0f172a] font-extrabold bg-white"
            />
          </div>
        </div>
        <div className="flex items-center gap-2 mt-1">
          <input
            type="checkbox"
            id="addBookCourseReserveCheck"
            checked={newBook.isCourseReserve}
            onChange={(e) => setNewBook({ ...newBook, isCourseReserve: e.target.checked })}
            className="w-4 h-4 rounded text-[#8B1A24] focus:ring-[#8B1A24] cursor-pointer"
          />
          <label htmlFor="addBookCourseReserveCheck" className="text-[12px] font-bold text-[#0f172a] cursor-pointer">
            Mark as Course Reserve (In-Library Reference Only)
          </label>
        </div>
      </div>

      <div className="flex justify-end gap-3 mt-2 pt-4 border-t border-slate-100">
        <button
          type="button"
          onClick={onCancel}
          className="px-5 py-2.5 border border-slate-200 rounded-xl text-slate-600 text-[11px] font-extrabold hover:bg-slate-50 transition-colors cursor-pointer"
        >
          Cancel
        </button>
        <button
          type="submit"
          disabled={actionBusy === 'add-book'}
          className="px-5 py-2.5 bg-[#8B1A24] text-white rounded-xl text-[11px] font-extrabold hover:bg-[#6b141c] transition-colors shadow-sm cursor-pointer disabled:opacity-60 disabled:cursor-not-allowed"
        >
          {actionBusy === 'add-book' ? <><Loader2 className="w-3.5 h-3.5 animate-spin mr-1.5 inline-block" />Saving...</> : 'Register Title & Copies'}
        </button>
      </div>
              </form>
        </div>
      </div>
    </div>
  );
}
