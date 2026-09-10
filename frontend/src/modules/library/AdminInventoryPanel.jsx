import React, { useState } from 'react';
import api from '../../api';

/**
 * Librarian inventory management.
 *
 * Wires up backend endpoints that already existed but had no UI:
 *   PUT  /library/books/{id}          edit title / author / category / ISBN / shelf
 *   POST /library/books/{id}/copies   add physical copies
 *   PUT  /library/copies/{id}         update a copy's condition / availability
 */
export default function AdminInventoryPanel({ books, onChanged }) {
  const [search, setSearch] = useState('');
  const [editing, setEditing] = useState(null);
  const [managing, setManaging] = useState(null);
  const [form, setForm] = useState({});
  const [addCopies, setAddCopies] = useState({ quantity: 1, condition: 'new' });
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');
  const [notice, setNotice] = useState('');

  const CONDITIONS = ['new', 'good', 'fair', 'poor'];
  // Operational statuses a librarian may set by hand. checked_out and on_hold are
  // driven by circulation, not manual edits.
  const MANUAL_STATUSES = ['available', 'lost', 'damaged'];

  const visible = books.filter((b) => {
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
      category: book.categories?.[0] || '',
      isbn: book.isbn || '',
      physical_location: book.location || '',
    });
    setError('');
    setNotice('');
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
      await api.put(`/library/copies/${copyId}`, patch);
      setNotice(`Copy CPY-${copyId} updated.`);
      if (onChanged) onChanged();
      setManaging(null);
    } catch (err) {
      setError(err.response?.data?.error || err.response?.data?.message || 'Could not update copy.');
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
        <input
          type="text"
          placeholder="Find a title…"
          value={search}
          onChange={(e) => setSearch(e.target.value)}
          className="w-full sm:w-60 px-3.5 py-2 bg-slate-50 border border-slate-200 rounded-xl text-[12.5px] focus:outline-none focus:ring-1 focus:ring-[#8B1A24]"
        />
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
          {visible.map((book) => (
            <div
              key={book.id}
              className="border border-slate-200/80 rounded-xl p-3.5 bg-white flex flex-col sm:flex-row sm:items-center justify-between gap-3 shadow-2xs"
            >
              <div className="min-w-0">
                <h3 className="font-extrabold text-[13px] text-[#0f172a] leading-tight truncate">{book.title}</h3>
                <p className="text-[11px] text-slate-400 font-medium truncate">
                  {book.author} · ISBN {book.isbn} · {book.location}
                </p>
                <span className="inline-block mt-1 text-[9.5px] font-extrabold px-2 py-0.5 rounded-full bg-[#ecfdf5] text-[#059669] border border-[#a7f3d0]/60">
                  {book.available} of {book.total} available
                </span>
              </div>

              <div className="flex items-center gap-2 self-end sm:self-auto shrink-0">
                <button
                  onClick={() => openEdit(book)}
                  className="bg-slate-100 hover:bg-slate-200 text-slate-700 text-[11px] font-extrabold py-2 px-3.5 rounded-xl cursor-pointer"
                >
                  Edit Title
                </button>
                <button
                  onClick={() => { setManaging(book); setError(''); setNotice(''); }}
                  className="bg-[#1e293b] hover:bg-[#0f172a] text-white text-[11px] font-extrabold py-2 px-3.5 rounded-xl cursor-pointer"
                >
                  Manage Copies
                </button>
              </div>
            </div>
          ))}
        </div>
      )}

      {/* Edit title */}
      {editing && (
        <div
          onClick={(e) => { if (e.target === e.currentTarget) setEditing(null); }}
          className="fixed inset-0 bg-black/60 backdrop-blur-xs flex items-center justify-center p-4 z-[110]"
        >
          <div className="bg-white rounded-[1.5rem] p-6 shadow-2xl border border-slate-100 max-w-md w-full">
            <h2 className="text-[17px] font-black text-[#0f172a] mb-4">Edit Title</h2>

            <div className="flex flex-col gap-3">
              {[
                ['book_title', 'Title'],
                ['author', 'Author'],
                ['category', 'Category'],
                ['isbn', 'ISBN'],
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
        <div
          onClick={(e) => { if (e.target === e.currentTarget) setManaging(null); }}
          className="fixed inset-0 bg-black/60 backdrop-blur-xs flex items-center justify-center p-4 z-[110]"
        >
          <div className="bg-white rounded-[1.5rem] p-6 shadow-2xl border border-slate-100 max-w-lg w-full">
            <h2 className="text-[17px] font-black text-[#0f172a] leading-tight">{managing.title}</h2>
            <p className="text-slate-400 text-[11px] font-medium mt-0.5 mb-4">Physical copies</p>

            <div className="flex flex-col gap-2 max-h-56 overflow-y-auto pr-1 mb-4">
              {(managing.copies || []).length === 0 && (
                <p className="text-[11.5px] text-slate-500 italic">No copies yet. Add some below.</p>
              )}

              {(managing.copies || []).map((copy) => {
                const locked = copy.availability_status === 'checked_out' || copy.availability_status === 'on_hold';

                return (
                  <div key={copy.copy_id} className="border border-slate-200 rounded-xl p-2.5 bg-white flex flex-wrap items-center justify-between gap-2">
                    <div className="font-mono text-[11px] font-extrabold text-[#0f172a]">
                      CPY-{copy.copy_id}
                      <span className="ml-2 font-sans text-[9.5px] font-bold uppercase tracking-wider text-slate-500">
                        {copy.availability_status.replace('_', ' ')}
                      </span>
                    </div>

                    <div className="flex items-center gap-2">
                      <select
                        defaultValue={copy.condition}
                        onChange={(e) => updateCopy(copy.copy_id, { condition: e.target.value })}
                        className="text-[11px] px-2 py-1.5 border border-slate-200 rounded-lg bg-white"
                      >
                        {CONDITIONS.map((c) => (
                          <option key={c} value={c}>{c}</option>
                        ))}
                      </select>

                      <select
                        value={copy.availability_status}
                        disabled={locked}
                        onChange={(e) => updateCopy(copy.copy_id, { availability_status: e.target.value })}
                        title={locked ? 'On loan or on hold — use Check-In to return it to the shelf.' : undefined}
                        className="text-[11px] px-2 py-1.5 border border-slate-200 rounded-lg bg-white disabled:bg-slate-100 disabled:text-slate-400"
                      >
                        {locked ? (
                          <option value={copy.availability_status}>{copy.availability_status.replace('_', ' ')}</option>
                        ) : (
                          MANUAL_STATUSES.map((s) => <option key={s} value={s}>{s}</option>)
                        )}
                      </select>
                    </div>
                  </div>
                );
              })}
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
                  className="w-24 px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl text-[13px]"
                />
                <select
                  value={addCopies.condition}
                  onChange={(e) => setAddCopies({ ...addCopies, condition: e.target.value })}
                  className="px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl text-[13px]"
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
    </div>
  );
}
