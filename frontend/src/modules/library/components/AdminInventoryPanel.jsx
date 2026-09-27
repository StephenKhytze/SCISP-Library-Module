import React, { useState } from 'react';
import api from '../services/api';
import StatusBadge from './StatusBadge';
import useDialog from '../hooks/useDialog';
import { useToast } from '../ToastProvider';
import { useConfirm } from '../ConfirmDialog';
import CategorySelect from './CategorySelect';
import AddBookForm from './AddBookForm';

/**
 * Librarian inventory management.
 *
 * Wires up backend endpoints that already existed but had no UI:
 *   PUT  /library/books/{id}          edit title / author / category / ISBN / shelf
 *   POST /library/books/{id}/copies   add physical copies
 *   PUT  /library/copies/{id}         update a copy's condition / availability
 */
export default function AdminInventoryPanel({ 
  books, 
  onChanged,
  newBook,
  setNewBook,
  handleAddBook,
  actionBusy,
  categories,
  onCategoriesChanged,
  canManageCategories
}) {
  const [search, setSearch] = useState('');
  const [categoryFilter, setCategoryFilter] = useState('');
  const [isAddBookOpen, setIsAddBookOpen] = useState(false);
  const derivedCategories = [...new Set(books.map(b => b.categories?.[0]).filter(Boolean))];
  const [filterMode, setFilterMode] = useState('active');
  const [editing, setEditing] = useState(null);
  const [managing, setManaging] = useState(null);
  const [copyFilterMode, setCopyFilterMode] = useState('active');

  const [form, setForm] = useState({});
  const [addCopies, setAddCopies] = useState({ quantity: 1, condition: 'new' });

  const toast = useToast();
  const confirm = useConfirm();

  const editDialogRef = useDialog(!!editing, () => setEditing(null));
  const manageDialogRef = useDialog(!!managing, () => setManaging(null));
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');
  const [notice, setNotice] = useState('');

  const CONDITIONS = ['new', 'good', 'fair', 'poor', 'damaged'];
  // Operational statuses a librarian may set by hand. checked_out and on_hold are
  // driven by circulation, not manual edits.
  const MANUAL_STATUSES = ['available', 'lost', 'damaged'];

  const visible = books.filter((b) => {
    if (categoryFilter && b.categories?.[0] !== categoryFilter) return false;
    if (filterMode === 'active' && b.isArchived) return false;
    if (filterMode === 'archived' && !b.isArchived) return false;

    const q = search.toLowerCase();
    if (!q) return true;
    return (
      b.title?.toLowerCase().includes(q) ||
      b.author?.toLowerCase().includes(q) ||
      b.isbn?.toLowerCase().includes(q)
    );
  });

  const openEdit = (book) => {
    setEditing(book);
    setForm({
      book_title: book.title || '',
      author: book.author || '',
      edition: book.edition || '',
      publisher: book.publisher || '',
      publication_year: book.publicationYear || '',
      category: book.categories?.[0] || '',
      isbn: book.isbn || '',
      physical_location: book.location || '',
    });
    setError('');
    setNotice('');
  };

  /** One cover per title, replacing whatever was there. */
  const uploadCover = async (book, file) => {
    if (!file) return;

    const data = new FormData();
    data.append('cover', file);

    setBusy(true);
    try {
      await api.post(`/library/books/${book.id}/cover`, data, {
        headers: { 'Content-Type': 'multipart/form-data' },
      });
      toast.success('Cover updated.');
      if (onChanged) onChanged();
    } catch (err) {
      toast.error(
        Object.values(err.response?.data?.errors || {})[0]?.[0] ||
        err.response?.data?.error ||
        'Could not save that cover.'
      );
    } finally {
      setBusy(false);
    }
  };

  const removeCover = async (book) => {
    setBusy(true);
    try {
      await api.delete(`/library/books/${book.id}/cover`);
      toast.success('Cover removed.');
      if (onChanged) onChanged();
    } catch (err) {
      toast.error(err.response?.data?.message || 'Could not remove the cover.');
    } finally {
      setBusy(false);
    }
  };

  /**
   * Archive, never delete: a title that has circulated is referenced by loans
   * and fines, and those records have to stay readable.
   */
  const archive = async (book) => {
    const ok = await confirm({
      title: 'Archive title',
      message: `Hide "${book.title}" from the catalog? Its loan and fine history stays intact, and you can restore it later.`,
      confirmText: 'Archive',
      isDestructive: true,
    });
    if (!ok) return;

    setBusy(true);
    try {
      const res = await api.post(`/library/books/${book.id}/archive`, {});
      toast.success(res.data.message);
      if (onChanged) onChanged();
    } catch (err) {
      toast.error(err.response?.data?.error || 'Could not archive this title.');
    } finally {
      setBusy(false);
    }
  };

  const restore = async (book) => {
    const ok = await confirm({
      title: 'Restore title',
      message: `Return "${book.title}" to the catalog?`,
      confirmText: 'Restore',
      isDestructive: false,
    });
    if (!ok) return;

    setBusy(true);
    try {
      const res = await api.post(`/library/books/${book.id}/restore`, {});
      toast.success(res.data.message);
      if (onChanged) onChanged();
    } catch (err) {
      toast.error(err.response?.data?.error || 'Could not restore this title.');
    } finally {
      setBusy(false);
    }
  };

  const saveEdit = async () => {
    setBusy(true);
    setError('');
    try {
      await api.put(`/library/books/${editing.id}`, form);
      setNotice(`"${form.book_title}" updated.`);
      setEditing(null);
      if (onChanged) onChanged();
    } catch (err) {
      setError(
        err.response?.data?.message ||
        Object.values(err.response?.data?.errors || {})[0]?.[0] ||
        'Could not save changes.'
      );
    } finally {
      setBusy(false);
    }
  };

  const submitAddCopies = async () => {
    setBusy(true);
    setError('');
    try {
      const res = await api.post(`/library/books/${managing.id}/copies`, {
        quantity: parseInt(addCopies.quantity, 10) || 1,
        condition: addCopies.condition,
      });
      setNotice(res.data?.message || 'Copies added.');
      setAddCopies({ quantity: 1, condition: 'new' });
      if (onChanged) onChanged();
      setManaging(null);
    } catch (err) {
      setError(err.response?.data?.error || err.response?.data?.message || 'Could not add copies.');
    } finally {
      setBusy(false);
    }
  };

  const updateCopy = async (copyId, patch) => {
    setBusy(true);
    setError('');
    try {
      const res = await api.put(`/library/copies/${copyId}`, patch);
      setNotice(`Copy CPY-${copyId} updated.`);
      
      const updatedCopy = res.data.copy || res.data;
      setManaging(prev => {
        if (!prev) return prev;
        return {
          ...prev,
          copies: (prev.copies || []).map(c => c.copy_id === copyId ? { ...c, ...updatedCopy } : c)
        };
      });
      if (onChanged) onChanged();
    } catch (err) {
      setError(err.response?.data?.error || err.response?.data?.message || 'Could not update copy.');
    } finally {
      setBusy(false);
    }
  };

  const archiveCopy = async (copyId, title, accession) => {
    const ok = await confirm({
      title: 'Archive Physical Copy?',
      message: `Hide copy ${accession} of "${title}"?`,
      confirmText: 'Archive',
      isDestructive: true,
    });
    if (!ok) return;

    setBusy(true);
    try {
      const res = await api.post(`/library/copies/${copyId}/archive`, {});
      toast.success(res.data.message || `Copy ${accession} archived.`);
      
      const updatedCopy = res.data.copy || res.data;
      setManaging(prev => {
        if (!prev) return prev;
        return {
           ...prev,
           copies: (prev.copies || []).map(c => c.copy_id === copyId ? { ...c, ...updatedCopy } : c)
        };
      });
      if (onChanged) onChanged();
    } catch (err) {
      toast.error(err.response?.data?.error || err.response?.data?.message || 'Could not archive this copy.');
    } finally {
      setBusy(false);
    }
  };

  const restoreCopy = async (copyId, title, accession) => {
    const ok = await confirm({
      title: 'Restore Physical Copy',
      message: `Restore copy ${accession} of "${title}" to the active inventory?`,
      confirmText: 'Restore',
      isDestructive: false,
    });
    if (!ok) return;

    setBusy(true);
    try {
      const res = await api.post(`/library/copies/${copyId}/restore`, {});
      toast.success(res.data.message || `Copy restored.`);
      
      const updatedCopy = res.data.copy || res.data;
      setManaging(prev => {
        if (!prev) return prev;
        return {
           ...prev,
           copies: (prev.copies || []).map(c => c.copy_id === copyId ? { ...c, ...updatedCopy } : c)
        };
      });
      if (onChanged) onChanged();
    } catch (err) {
      toast.error(err.response?.data?.error || err.response?.data?.message || 'Could not restore this copy.');
    } finally {
      setBusy(false);
    }
  };

  return (
    <div className="bg-white rounded-[1.25rem] p-5 sm:p-6 shadow-xs border border-slate-200/70">
      <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-3 mb-4 pb-2 border-b border-slate-100">
        <div>
          <h2 className="text-[17px] font-extrabold text-[#0f172a]">Manage Inventory</h2>
          <p className="text-slate-400 text-[11px] font-medium mt-0.5">
            Edit titles, add physical copies, and set copy condition or status.
          </p>
        </div>
        <div className="flex flex-col sm:flex-row gap-3 w-full sm:w-auto">
          <button 
              onClick={() => setIsAddBookOpen(true)}
              className="flex items-center justify-center bg-[#8B1A24] hover:bg-[#72151d] transition-colors text-white text-[12px] font-extrabold px-4 py-2 rounded-xl whitespace-nowrap shadow-sm"
            >
              + Register Title
            </button>
          <div className="flex bg-slate-100/80 rounded-xl p-1.5 shrink-0 shadow-inner w-full sm:w-auto">
            {['active', 'archived', 'all'].map(m => (
              <button
                key={m}
                onClick={() => setFilterMode(m)}
                className={`flex-1 sm:flex-none px-4 py-1.5 text-[11px] font-extrabold rounded-lg capitalize transition-all duration-200 ${filterMode === m ? 'bg-white text-slate-800 shadow-sm ring-1 ring-slate-200/50' : 'text-slate-500 hover:text-slate-700 hover:bg-slate-200/50'}`}
              >
                {m}
              </button>
            ))}
          </div>
          <select
              value={categoryFilter}
              onChange={(e) => setCategoryFilter(e.target.value)}
              className="px-3.5 py-2 bg-slate-50 border border-slate-200 rounded-xl text-[12.5px] font-medium text-slate-700 focus:outline-none focus:ring-1 focus:ring-[#8B1A24] w-full sm:w-auto"
            >
              <option value="">All Categories</option>
              {derivedCategories.map(c => <option key={c} value={c}>{c}</option>)}
            </select>
            <input
              type="text"
              placeholder="Find a title..."
            value={search}
            onChange={(e) => setSearch(e.target.value)}
            className="w-full sm:w-60 px-3.5 py-2 bg-slate-50 border border-slate-200 rounded-xl text-[12.5px] focus:outline-none focus:ring-1 focus:ring-[#8B1A24]"
          />
        </div>
      </div>

      {notice && (
        <div className="mb-3 rounded-xl border border-emerald-200 bg-emerald-50 px-3.5 py-2.5 text-[11.5px] font-semibold text-emerald-800">
          {notice}
        </div>
      )}
      {error && !editing && !managing && (
        <div className="mb-3 rounded-xl border border-rose-200 bg-rose-50 px-3.5 py-2.5 text-[11.5px] font-semibold text-rose-700">
          {error}
        </div>
      )}

      {visible.length === 0 ? (
        <p className="text-[12px] text-slate-500 italic py-6 text-center">No titles match that search.</p>
      ) : (
        <div className="flex flex-col gap-2.5 max-h-[26rem] overflow-y-auto pr-1">
          {visible.map((book) => {
            const archivedCopyCount = (book.copies || []).filter(copy => copy.is_archived === true).length;
            
            return (
            <div
              key={book.id}
              className="border border-slate-200/80 rounded-xl p-4 bg-white flex flex-col sm:flex-row sm:items-start justify-between gap-4 shadow-sm"
            >
              <div className="min-w-0 flex-1">
                <h3 className="font-extrabold text-[14px] text-[#0f172a] leading-tight truncate mb-1">{book.title}</h3>
                <div className="flex flex-col gap-0.5">
                  <p className="text-[11.5px] text-slate-500 font-medium truncate">
                    {book.author}
                    {book.edition ? ` · ${book.edition}` : ''}
                    {book.publisher ? ` · ${book.publisher}` : ''}
                    {book.publicationYear ? ` (${book.publicationYear})` : ''}
                  </p>
                  <p className="text-[11px] text-slate-400 font-medium truncate">
                    ISBN {book.isbn || '—'} · <span className="font-semibold text-slate-500">{book.location}</span>
                  </p>
                </div>
                <div className="flex items-center gap-2 mt-3 flex-wrap">
                  <span className="inline-flex items-center text-[10px] font-extrabold px-2.5 py-1 rounded-full bg-[#ecfdf5] text-[#059669] border border-[#a7f3d0]/60 shadow-sm">
                    {book.available} of {book.total} available
                  </span>
                  {book.reservedCount > 0 && (
                    <span className="inline-block text-[9.5px] font-extrabold px-2.5 py-1 rounded-full bg-blue-50 text-blue-700 border border-blue-200">
                      {book.reservedCount} on course reserve
                    </span>
                  )}
                  {archivedCopyCount > 0 && (
                    <span className="inline-flex items-center text-[9.5px] font-extrabold px-2.5 py-1 rounded-full bg-slate-100 text-slate-600 border border-slate-300">
                      {archivedCopyCount} archived
                    </span>
                  )}
                  {book.isArchived && (
                    <StatusBadge status="archived" />
                  )}
                </div>
              </div>

              <div className="flex items-center gap-2 self-start sm:self-center shrink-0 flex-wrap justify-start sm:justify-end mt-1 sm:mt-0">
                <button
                  onClick={() => openEdit(book)}
                  className="bg-slate-100 hover:bg-slate-200 text-slate-700 text-[11px] font-extrabold py-2 px-3.5 rounded-xl cursor-pointer transition-colors"
                >
                  Edit Title
                </button>
                <button
                  onClick={() => { setManaging(book); setError(''); setNotice(''); }}
                  className="bg-[#1e293b] hover:bg-[#0f172a] text-white text-[11px] font-extrabold py-2 px-3.5 rounded-xl cursor-pointer shadow-xs transition-colors"
                >
                  Manage Copies
                </button>

                {/* One cover per title. The file input is hidden behind a
                    label so the button matches the others. */}
                <label className="bg-slate-100 hover:bg-slate-200 text-slate-700 text-[11px] font-extrabold py-2 px-3.5 rounded-xl cursor-pointer transition-colors m-0 flex items-center">
                  {book.image && !book.image.includes('unsplash') ? 'Replace Cover' : 'Add Cover'}
                  <input
                    type="file"
                    accept="image/jpeg,image/png,image/webp"
                    className="hidden"
                    onChange={(e) => { uploadCover(book, e.target.files?.[0]); e.target.value = ''; }}
                  />
                </label>

                {book.image && !book.image.includes('unsplash') && (
                  <button
                    onClick={() => removeCover(book)}
                    disabled={busy}
                    className="bg-white hover:bg-slate-50 text-slate-600 border border-slate-200 text-[11px] font-extrabold py-2 px-3 rounded-xl cursor-pointer disabled:opacity-60 transition-colors"
                  >
                    Remove Cover
                  </button>
                )}

                {book.isArchived ? (
                  <button
                    onClick={() => restore(book)}
                    disabled={busy}
                    className="bg-emerald-600 hover:bg-emerald-700 text-white text-[11px] font-extrabold py-2 px-3.5 rounded-xl cursor-pointer disabled:opacity-60 transition-colors"
                  >
                    Restore Title
                  </button>
                ) : (
                  <button
                    onClick={() => archive(book)}
                    disabled={busy}
                    className="bg-rose-50 hover:bg-rose-100 text-rose-700 border border-rose-200 text-[11px] font-extrabold py-2 px-3.5 rounded-xl cursor-pointer disabled:opacity-60 transition-colors"
                  >
                    Archive Title
                  </button>
                )}
              </div>
            </div>
            );
          })}
        </div>
      )}

      {/* Edit title */}
      {editing && (
        <div className="fixed inset-0 flex items-center justify-center p-4 z-50">
          <div
            className="absolute inset-0 bg-black/60 backdrop-blur-xs anim-fade-in"
            onClick={() => setEditing(null)}
            aria-hidden="true"
          />
          <div
            ref={editDialogRef}
            role="dialog"
            aria-modal="true"
            aria-labelledby="edit-title-heading"
            className="relative z-10 bg-white rounded-[1.5rem] p-6 shadow-2xl border border-slate-100 max-w-md w-full anim-zoom-in"
          >
            <h2 id="edit-title-heading" className="text-[17px] font-black text-[#0f172a] mb-4">Edit Title</h2>

            <div className="flex flex-col gap-3">
          {visible.length === 0 && (
            <div className="text-center py-10 bg-slate-50 rounded-2xl border border-slate-100">
              <p className="text-[13px] text-slate-500 font-medium">No titles match the current search and category filters.</p>
            </div>
          )}
              {[
                ['book_title', 'Title'],
                ['author', 'Author'],
                ['edition', 'Edition'],
                ['publisher', 'Publisher'],
                ['publication_year', 'Publication year'],
                ['isbn', 'ISBN (identifies the title and edition)'],
                ['physical_location', 'Shelf / Location'],
              ].map(([key, label]) => (
                <div key={key}>
                  <label className="block text-[10.5px] font-extrabold text-[#0f172a] mb-1 uppercase tracking-wider">
                    {label}
                  </label>
                  <input
                    type="text"
                    value={form[key] || ''}
                    onChange={(e) => setForm({ ...form, [key]: e.target.value })}
                    className="w-full px-3.5 py-2 bg-slate-50 border border-slate-200 rounded-xl text-[13px] focus:outline-none focus:ring-1 focus:ring-[#8B1A24]"
                  />
                </div>
              ))}

              <div>
                <label htmlFor="edit-book-category" className="block text-[10.5px] font-extrabold text-[#0f172a] mb-1 uppercase tracking-wider">
                  Category
                </label>
                <CategorySelect
                  id="edit-book-category"
                  value={form.category || ''}
                  onChange={(next) => setForm({ ...form, category: next })}
                  canCreate
                  className="w-full px-3.5 py-2 bg-slate-50 border border-slate-200 rounded-xl text-[13px] focus:outline-none focus:ring-1 focus:ring-[#8B1A24] cursor-pointer"
                />
              </div>
            </div>

            {error && (
              <div className="mt-3 rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-[11px] font-semibold text-rose-700">
                {error}
              </div>
            )}

            <div className="flex justify-end gap-2.5 mt-5">
              <button
                onClick={() => setEditing(null)}
                className="px-4 py-2 border border-slate-200 rounded-xl text-[12px] font-extrabold hover:bg-slate-50 cursor-pointer"
              >
                Cancel
              </button>
              <button
                onClick={saveEdit}
                disabled={busy}
                className="px-4 py-2 bg-[#8B1A24] hover:bg-[#6b141c] text-white rounded-xl text-[12px] font-extrabold cursor-pointer disabled:opacity-50"
              >
                {busy ? 'Saving…' : 'Save Changes'}
              </button>
            </div>
          </div>
        </div>
      )}

      {/* Manage copies */}
      {managing && (
        <div className="fixed inset-0 flex items-center justify-center p-4 z-50">
          <div
            className="absolute inset-0 bg-black/60 backdrop-blur-xs anim-fade-in"
            onClick={() => setManaging(null)}
            aria-hidden="true"
          />
          <div
            ref={manageDialogRef}
            role="dialog"
            aria-modal="true"
            aria-labelledby="manage-copies-heading"
            className="relative z-10 bg-white rounded-[1.5rem] p-6 shadow-2xl border border-slate-100 max-w-2xl w-full anim-zoom-in flex flex-col max-h-[90vh]"
          >
            <div className="flex flex-col sm:flex-row sm:items-center justify-between mb-5 gap-4">
              <div>
                <h2 id="manage-copies-heading" className="text-[18px] font-black text-[#0f172a] leading-tight pr-4">{managing.title}</h2>
                <p className="text-slate-500 text-[12px] font-semibold mt-1">Manage physical copies</p>
              </div>
              <div className="flex bg-slate-100/80 rounded-xl p-1.5 shrink-0 shadow-inner w-full sm:w-auto">
                {['active', 'archived', 'all'].map(m => (
                  <button
                    key={m}
                    onClick={() => setCopyFilterMode(m)}
                    className={`flex-1 sm:flex-none px-3.5 py-1.5 text-[10.5px] font-extrabold rounded-lg capitalize transition-all duration-200 ${copyFilterMode === m ? 'bg-white text-slate-800 shadow-sm ring-1 ring-slate-200/50' : 'text-slate-500 hover:text-slate-700 hover:bg-slate-200/50'}`}
                  >
                    {m}
                  </button>
                ))}
              </div>
            </div>

            <div className="flex flex-col gap-3 overflow-y-auto pr-1 mb-5 min-h-[10rem]">
              {(managing.copies || []).length === 0 && (
                <div className="text-center py-8 bg-slate-50 rounded-xl border border-slate-100">
                  <p className="text-[12px] text-slate-500 italic font-medium">No copies yet. Add some below.</p>
                </div>
              )}

              {(() => {
                const copiesToManage = (managing.copies || []).filter(c => {
                  if (copyFilterMode === 'active' && c.is_archived) return false;
                  if (copyFilterMode === 'archived' && !c.is_archived) return false;
                  return true;
                });
                if (copiesToManage.length === 0 && (managing.copies || []).length > 0) {
                   return (
                     <div className="text-center py-8 bg-slate-50 rounded-xl border border-slate-100">
                       <p className="text-[12px] text-slate-500 italic font-medium">No copies match the current filter.</p>
                     </div>
                   );
                }
                return copiesToManage.map((copy) => {
                  const locked = copy.is_archived || copy.availability_status === 'checked_out' || copy.availability_status === 'on_hold';

                  return (
                    <div key={copy.copy_id} className="border border-slate-200/80 rounded-xl p-3.5 bg-white flex flex-col gap-3 shadow-xs">
                      <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-3 min-w-0">
                        <div className="font-mono text-[11.5px] font-extrabold text-[#0f172a] shrink-0 flex items-center flex-wrap gap-2.5 min-w-0">
                          <span className="truncate">{copy.accession_number || `CPY-${copy.copy_id}`}</span>
                          {copy.is_archived ? (
                            <StatusBadge status="archived" />
                          ) : (
                            <StatusBadge status={copy.availability_status} />
                          )}
                        </div>

                        <div className="flex items-center flex-wrap gap-2.5">
                          <div className="flex items-center gap-1.5">
                            <label className="text-[10px] font-extrabold text-slate-400 uppercase tracking-wider hidden sm:block">Cond</label>
                            <select
                              value={copy.condition}
                              disabled={copy.is_archived}
                              onChange={(e) => updateCopy(copy.copy_id, { condition: e.target.value })}
                              className="text-[11.5px] px-2.5 py-1.5 border border-slate-200 rounded-lg bg-white disabled:bg-slate-50 disabled:text-slate-400 font-semibold focus:ring-1 focus:ring-[#8B1A24] outline-none"
                            >
                              {CONDITIONS.map((c) => (
                                <option key={c} value={c}>{c}</option>
                              ))}
                            </select>
                          </div>

                          <div className="flex items-center gap-1.5">
                            <label className="text-[10px] font-extrabold text-slate-400 uppercase tracking-wider hidden sm:block">Avail</label>
                            <select
                              value={copy.availability_status}
                              disabled={locked}
                              onChange={(e) => updateCopy(copy.copy_id, { availability_status: e.target.value })}
                              title={locked ? (copy.is_archived ? 'Archived copies cannot be modified.' : 'On loan or on hold — use Check-In to return it to the shelf.') : undefined}
                              className="text-[11.5px] px-2.5 py-1.5 border border-slate-200 rounded-lg bg-white disabled:bg-slate-50 disabled:text-slate-400 font-semibold focus:ring-1 focus:ring-[#8B1A24] outline-none"
                            >
                              {locked ? (
                                <option value={copy.availability_status}>{copy.availability_status.replace('_', ' ')}</option>
                              ) : (
                                MANUAL_STATUSES.map((s) => <option key={s} value={s}>{s}</option>)
                              )}
                            </select>
                          </div>

                          {copy.is_archived ? (
                             <button
                               onClick={() => restoreCopy(copy.copy_id, managing.title, copy.accession_number || `CPY-${copy.copy_id}`)}
                               disabled={busy}
                               className="bg-emerald-50 hover:bg-emerald-100 text-emerald-700 border border-emerald-200 text-[10.5px] font-extrabold py-1.5 px-3 rounded-lg cursor-pointer disabled:opacity-50 shrink-0 transition-colors"
                             >
                               Restore Copy
                             </button>
                          ) : (
                             <button
                               onClick={() => archiveCopy(copy.copy_id, managing.title, copy.accession_number || `CPY-${copy.copy_id}`)}
                               disabled={busy}
                               className="bg-rose-50 hover:bg-rose-100 text-rose-700 border border-rose-200 text-[10.5px] font-extrabold py-1.5 px-3 rounded-lg cursor-pointer disabled:opacity-50 shrink-0 transition-colors"
                             >
                               Archive Copy
                             </button>
                          )}
                        </div>
                      </div>
                      
                      {!copy.is_archived && copy.condition !== 'damaged' && copy.availability_status === 'damaged' && (
                        <div className="w-full text-[11px] text-amber-800 bg-amber-50/80 rounded-lg px-3 py-2 font-semibold border border-amber-200/60 flex items-start gap-2">
                          <svg className="w-4 h-4 text-amber-600 shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path></svg>
                          <span>This copy remains unavailable until you set Availability to Available.</span>
                        </div>
                      )}
                    </div>
                  );
                });
              })()}
            </div>

            <div className="border-t border-slate-100 pt-4">
              <label className="block text-[10.5px] font-extrabold text-[#0f172a] mb-1.5 uppercase tracking-wider">
                Add copies
              </label>
              <div className="flex flex-wrap items-center gap-2">
                <input
                  type="number"
                  min="1"
                  max="100"
                  value={addCopies.quantity}
                  onChange={(e) => setAddCopies({ ...addCopies, quantity: e.target.value })}
                  className="w-24 px-3.5 py-2 bg-slate-50 border border-slate-200 rounded-xl text-[13px] focus:outline-none focus:ring-1 focus:ring-[#8B1A24]"
                />
                <select
                  value={addCopies.condition}
                  onChange={(e) => setAddCopies({ ...addCopies, condition: e.target.value })}
                  className="px-3.5 py-2 bg-slate-50 border border-slate-200 rounded-xl text-[13px] focus:outline-none focus:ring-1 focus:ring-[#8B1A24]"
                >
                  {CONDITIONS.map((c) => (
                    <option key={c} value={c}>{c}</option>
                  ))}
                </select>
                <button
                  onClick={submitAddCopies}
                  disabled={busy}
                  className="bg-emerald-600 hover:bg-emerald-700 text-white text-[12px] font-extrabold py-2 px-4 rounded-xl cursor-pointer disabled:opacity-50"
                >
                  Add
                </button>
              </div>
            </div>

            {error && (
              <div className="mt-3 rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-[11px] font-semibold text-rose-700">
                {error}
              </div>
            )}

            <div className="flex justify-end mt-5">
              <button
                onClick={() => setManaging(null)}
                className="px-4 py-2 border border-slate-200 rounded-xl text-[12px] font-extrabold hover:bg-slate-50 cursor-pointer"
              >
                Done
              </button>
            </div>
          </div>
        </div>
      )}
      <AddBookForm 
        isOpen={isAddBookOpen}
        newBook={newBook}
        setNewBook={setNewBook}
        handleAddBook={async (e) => {
          await handleAddBook(e);
          setIsAddBookOpen(false);
        }}
        onCancel={() => setIsAddBookOpen(false)}
        actionBusy={actionBusy}
        categories={categories}
        onCategoriesChanged={onCategoriesChanged}
        canManageCategories={canManageCategories}
      />
    </div>
  );
}
