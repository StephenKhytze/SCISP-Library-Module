import React, { useCallback, useEffect, useState } from 'react';
import api from '../../api';
import { useToast } from './ToastProvider';
import useDialog from './useDialog';
import { Loader2 } from 'lucide-react';

/**
 * The one place a book category is chosen.
 *
 * A real <select>, not a row of chips, so the control does not grow with the
 * catalog. Librarians additionally get "+ Add New Category", which creates the
 * category and selects it without leaving the form.
 *
 * Creation is gated at the route as well; `canCreate` only decides whether the
 * option is worth showing.
 */

const ADD_NEW = '__add_new_category__';

export default function CategorySelect({
  value,
  onChange,
  categories: providedCategories,
  onCategoriesChanged,
  canCreate = false,
  includeAll = false,
  allLabel = 'All Categories',
  placeholder = 'Select a category',
  className = '',
  id,
  ariaLabel = 'Category',
  required = false,
}) {
  const toast = useToast();

  // The list is either owned by the parent (the catalog already fetches it) or
  // fetched here, so the component drops into a form that has no list of its own.
  const [ownCategories, setOwnCategories] = useState(null);
  const categories = providedCategories ?? ownCategories ?? [];

  const [showAdd, setShowAdd] = useState(false);
  const [draftName, setDraftName] = useState('');
  const [saving, setSaving] = useState(false);

  const addDialogRef = useDialog(showAdd, () => setShowAdd(false));

  const loadOwn = useCallback(async () => {
    try {
      const res = await api.get('/library/categories');
      setOwnCategories(Array.isArray(res.data) ? res.data : []);
    } catch {
      setOwnCategories([]);
    }
  }, []);

  useEffect(() => {
    if (providedCategories === undefined) loadOwn();
  }, [providedCategories, loadOwn]);

  const handleSelect = (next) => {
    if (next === ADD_NEW) {
      setDraftName('');
      setShowAdd(true);
      return;
    }
    onChange(next);
  };

  const createCategory = async () => {
    const name = draftName.trim();
    if (!name) {
      toast.error('Enter a category name.');
      return;
    }

    setSaving(true);
    try {
      const res = await api.post('/library/categories', { name });
      const created = res.data.category.name;

      // Add it to whichever list this component is reading from, then select
      // it — no page refresh, and the form the librarian was filling in stays.
      if (providedCategories === undefined) {
        setOwnCategories((prev) => [...(prev || []), created].sort((a, b) => a.localeCompare(b)));
      }
      if (onCategoriesChanged) onCategoriesChanged(created);

      onChange(created);
      toast.success(res.data.message || `Category "${created}" added.`);
      setShowAdd(false);
    } catch (err) {
      // 409 means it already exists; say so and do not add a duplicate option.
      toast.error(
        err.response?.data?.error ||
        Object.values(err.response?.data?.errors || {})[0]?.[0] ||
        'Could not add that category.'
      );
    } finally {
      setSaving(false);
    }
  };

  // A book saved before its category joined the list must still show its own
  // value, otherwise opening the edit form would silently reassign it.
  const options = value && !includeAll && !categories.includes(value)
    ? [value, ...categories]
    : categories;

  return (
    <>
      <select
        id={id}
        aria-label={ariaLabel}
        required={required}
        value={value ?? ''}
        onChange={(e) => handleSelect(e.target.value)}
        className={className || 'w-full border border-slate-200 rounded-lg py-2.5 px-3 text-[13px] focus:outline-none focus:ring-1 focus:ring-[#8B1A24] text-[#0f172a] font-extrabold bg-white cursor-pointer'}
      >
        {includeAll ? (
          <option value="">{allLabel}</option>
        ) : (
          <option value="" disabled>{placeholder}</option>
        )}

        {options.map((name) => (
          <option key={name} value={name}>{name}</option>
        ))}

        {canCreate && <option value={ADD_NEW}>+ Add New Category</option>}
      </select>

      {showAdd && (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4">
          <div
            className="absolute inset-0 bg-black/40 backdrop-blur-sm anim-fade-in"
            onClick={() => setShowAdd(false)}
            aria-hidden="true"
          />
          <div
            ref={addDialogRef}
            role="dialog"
            aria-modal="true"
            aria-labelledby="add-category-title"
            className="bg-white rounded-2xl shadow-xl w-full max-w-sm overflow-hidden relative z-10 anim-zoom-in"
          >
            {/* Deliberately not a <form>: this dialog renders inside the Add
                Book form, and a nested form is invalid HTML — the browser
                un-nests it and a submit here would submit the book instead. */}
            <div>
              <div className="p-5">
                <h3 id="add-category-title" className="text-lg font-bold text-gray-900 mb-3">Add New Category</h3>

                <label htmlFor="new-category-name" className="block text-[11px] font-extrabold text-[#0f172a] mb-1.5">
                  Category Name
                </label>
                <input
                  id="new-category-name"
                  type="text"
                  autoFocus
                  value={draftName}
                  onChange={(e) => setDraftName(e.target.value)}
                  // Enter still submits, without a form to carry the event up.
                  onKeyDown={(e) => {
                    if (e.key === 'Enter') {
                      e.preventDefault();
                      if (!saving) createCategory();
                    }
                  }}
                  placeholder="e.g. Computer Science"
                  className="w-full border border-slate-200 rounded-lg py-2.5 px-3 text-[13px] focus:outline-none focus:ring-1 focus:ring-[#8B1A24] text-[#0f172a] bg-white font-medium"
                />
              </div>

              <div className="px-5 py-4 bg-gray-50 flex justify-end gap-2.5 border-t border-gray-100">
                <button
                  type="button"
                  onClick={() => setShowAdd(false)}
                  className="px-4 py-2 text-[13px] font-extrabold text-slate-500 hover:text-slate-700 hover:bg-slate-200/50 rounded-xl transition-all duration-150 active:scale-[0.98] cursor-pointer"
                >
                  Cancel
                </button>
                <button
                  type="button"
                  onClick={createCategory}
                  disabled={saving}
                  className="px-4 py-2 text-[13px] font-extrabold text-white rounded-xl transition-all duration-150 active:scale-[0.98] cursor-pointer shadow-sm bg-[#0f172a] hover:bg-[#1e293b] shadow-slate-200 disabled:opacity-60"
                >
                  {saving ? <><Loader2 className="w-3.5 h-3.5 animate-spin mr-1.5 inline-block" />Adding…</> : 'Add Category'}
                </button>
              </div>
            </div>
          </div>
        </div>
      )}
    </>
  );
}
