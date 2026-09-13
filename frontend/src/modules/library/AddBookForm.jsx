import React from 'react';
import { Loader2 } from 'lucide-react';
import CategorySelect from './CategorySelect';

export default function AddBookForm({
  newBook,
  setNewBook,
  handleAddBook,
  onCancel,
  actionBusy,
  categories,
  onCategoriesChanged,
  canManageCategories = false,
}) {
  return (
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
  );
}
