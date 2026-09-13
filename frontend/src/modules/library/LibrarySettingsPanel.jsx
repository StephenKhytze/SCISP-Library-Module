import React, { useState, useEffect } from 'react';
import api from '../../api';
import { useToast } from './ToastProvider';
import { useConfirm } from './ConfirmDialog';
import { Save } from 'lucide-react';

/**
 * Librarian settings panel.
 *
 * Card-based layout, value formatting and a confirmation diff.
 *
 * There is no settings change log: `library_settings` stores only the CURRENT
 * policy values. The diff below is temporary UI state shown before saving.
 *
 * The field list below is the API contract, not a display convenience: every
 * `k` must match a key in LibrarySettingsService::DEFAULTS exactly, and
 * `nullable` must match that key's backend validation rule. A key that does not
 * match reads back `undefined`, so its input renders blank and its value is
 * silently dropped on save.
 */

const GROUPS = [
  {
    title: 'Student Policy',
    keys: [
      { k: 'student_borrowing_enabled', type: 'bool', label: 'Borrowing enabled' },
      { k: 'student_max_books', type: 'number', label: 'Max active books', nullable: true, blank: 'Unlimited' },
      { k: 'student_loan_days', type: 'number', label: 'Loan duration', suffix: 'days' },
      { k: 'student_fine_per_day', type: 'number', label: 'Fine per day', prefix: '₱', step: '0.01' },
      { k: 'student_grace_days', type: 'number', label: 'Grace period', suffix: 'days' },
      { k: 'student_max_fine', type: 'number', label: 'Maximum fine', prefix: '₱', step: '0.01', nullable: true, blank: 'No cap' },
    ],
  },
  {
    title: 'Faculty Policy',
    keys: [
      { k: 'faculty_borrowing_enabled', type: 'bool', label: 'Borrowing enabled' },
      { k: 'faculty_max_books', type: 'number', label: 'Max active books', nullable: true, blank: 'Unlimited' },
      { k: 'faculty_loan_days', type: 'number', label: 'Loan duration', suffix: 'days' },
      { k: 'faculty_fine_per_day', type: 'number', label: 'Fine per day', prefix: '₱', step: '0.01' },
      { k: 'faculty_grace_days', type: 'number', label: 'Grace period', suffix: 'days' },
      { k: 'faculty_max_fine', type: 'number', label: 'Maximum fine', prefix: '₱', step: '0.01', nullable: true, blank: 'No cap' },
    ],
  },
  {
    title: 'Renewals',
    keys: [
      { k: 'student_renewal_enabled', type: 'bool', label: 'Students may request renewals' },
      { k: 'faculty_renewal_enabled', type: 'bool', label: 'Faculty may request renewals' },
      { k: 'max_renewals_per_loan', type: 'number', label: 'Max renewals per loan', nullable: true, blank: 'Unlimited' },
    ],
  },
  {
    title: 'Course Reserves',
    // Days, not hours — the backend key is reserve_loan_days.
    keys: [
      { k: 'reserve_loan_days', type: 'number', label: 'Reserve loan duration', suffix: 'days' },
    ],
  },
];

const ALL_FIELDS = GROUPS.flatMap((g) => g.keys);
const FIELD_BY_KEY = Object.fromEntries(ALL_FIELDS.map((f) => [f.k, f]));

/** Blank, null and undefined all mean "no value" once a box has been cleared. */
const isBlank = (v) => v === null || v === undefined || v === '';

/** Compare loosely enough that 10 and "10" are the same saved value. */
const same = (a, b) => (isBlank(a) && isBlank(b)) || String(a) === String(b);

export default function LibrarySettingsPanel() {
  const [settings, setSettings] = useState({});
  const [original, setOriginal] = useState({});
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const toast = useToast();
  const confirm = useConfirm();

  const fetchSettings = async () => {
    try {
      const setRes = await api.get('/library/settings');
      const s = setRes.data.settings || {};
      setSettings(s);
      setOriginal(s);
    } catch {
      toast.error('Could not load library settings.');
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    fetchSettings();
  }, []);

  const handleChange = (key, val) => {
    setSettings((prev) => ({ ...prev, [key]: val }));
  };

  // Convert "student_fine_per_day" -> "Student Fine Per Day"
  const humanize = (str) => {
    if (!str) return '';
    return str
      .split('_')
      .map((w) => w.charAt(0).toUpperCase() + w.slice(1))
      .join(' ');
  };

  /** Show a saved value the way the field displays it, for the diff summary. */
  const display = (key, value) => {
    const field = FIELD_BY_KEY[key];
    if (!field) return String(value ?? '—');
    // A stored boolean can arrive as '1' / '0', and '0' is truthy in JS.
    if (field.type === 'bool') {
      return (value === true || value === 1 || value === '1' || value === 'true')
        ? 'Enabled'
        : 'Disabled';
    }
    if (isBlank(value)) return field.blank || '—';
    return `${field.prefix || ''}${value}${field.suffix ? ` ${field.suffix}` : ''}`;
  };

  const hasChanges = ALL_FIELDS.some(f => !same(settings[f.k], original[f.k]));

  const discardChanges = () => {
    setSettings(original);
  };

  const save = async () => {
    // 1. Diff, and catch cleared boxes that the backend will not accept as null.
    const diff = {};
    const missing = [];

    ALL_FIELDS.forEach((f) => {
      const current = settings[f.k];

      if (f.type === 'number' && isBlank(current) && !f.nullable) {
        missing.push(f.label);
        return;
      }

      if (!same(current, original[f.k])) {
        diff[f.k] = isBlank(current) ? null : current;
      }
    });

    if (missing.length > 0) {
      toast.error(
        missing.length === 1
          ? `${missing[0]} needs a value.`
          : `${missing.length} settings need a value: ${missing.join(', ')}.`
      );
      return;
    }

    if (Object.keys(diff).length === 0) {
      toast.warning('No changes to save.');
      return;
    }

    // 2. Confirm diff
    const ok = await confirm({
      title: 'Apply Library Policy Changes?',
      message: 'The following policy changes will take effect immediately for new checkouts and renewals:',
      content: (
        <div className="mt-3 bg-slate-50 border border-slate-200 rounded-xl overflow-hidden">
          <table className="w-full text-left border-collapse">
            <tbody>
              {Object.keys(diff).map((k, i) => (
                <tr key={k} className={i !== 0 ? 'border-t border-slate-200/60' : ''}>
                  <td className="py-2.5 px-3 text-[11px] font-bold text-slate-600">{FIELD_BY_KEY[k]?.label || humanize(k)}</td>
                  <td className="py-2.5 px-3 text-[11px] font-medium text-slate-400 line-through whitespace-nowrap">
                    {display(k, original[k])}
                  </td>
                  <td className="py-2.5 px-3 text-[11px] font-black text-[#8B1A24] whitespace-nowrap">
                    {display(k, diff[k])}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      ),
      confirmText: 'Apply Changes'
    });

    if (!ok) return;

    // 3. Save. The endpoint expects the changes under a `settings` key.
    setSaving(true);
    try {
      const res = await api.put('/library/settings', { settings: diff });
      toast.success(res.data?.message || 'Library settings updated.');
      await fetchSettings();
    } catch (err) {
      const errors = err.response?.data?.errors;
      toast.error(
        (errors && Object.values(errors).flat()[0]) ||
        err.response?.data?.message ||
        'Could not save settings.'
      );
    } finally {
      setSaving(false);
    }
  };

  if (loading) {
    return <div className="p-6 text-center text-slate-500 font-medium text-[13px]">Loading settings…</div>;
  }

  const renderInput = (field) => {
    const val = settings[field.k];

    if (field.type === 'bool') {
      return (
        <label className="lib-toggle">
          <input
            type="checkbox"
            checked={!!val}
            onChange={(e) => handleChange(field.k, e.target.checked)}
            aria-label={field.label}
          />
          <span />
        </label>
      );
    }

    return (
      <div className="flex items-stretch bg-slate-50 border border-slate-200 rounded-xl overflow-hidden focus-within:ring-1 focus-within:ring-[#8B1A24] focus-within:border-[#8B1A24] transition-all">
        {field.prefix && <span className="lib-field-prefix border-r border-slate-200">{field.prefix}</span>}
        <input
          type="number"
          step={field.step || '1'}
          value={val ?? ''}
          placeholder={field.blank || ''}
          onChange={(e) => handleChange(field.k, e.target.value === '' ? null : Number(e.target.value))}
          className="w-16 px-2 py-1.5 bg-transparent text-[13px] font-bold text-center text-[#0f172a] focus:outline-none placeholder-slate-300"
          aria-label={field.label}
        />
        {field.suffix && <span className="lib-field-suffix border-l border-slate-200">{field.suffix}</span>}
      </div>
    );
  };

  return (
    <div className="max-w-3xl">
      <div>
        <div className="mb-6">
          <h2 className="text-[19px] font-black text-[#0f172a] leading-tight">Library Policy</h2>
          <p className="text-slate-500 text-[12px] font-medium mt-1">
            Configure borrowing rules, fines, and renewals.
          </p>
        </div>

        <div className="flex flex-col gap-5 mb-6">
          {GROUPS.map((g) => (
            <div key={g.title} className="bg-white rounded-2xl border border-slate-200/70 shadow-xs overflow-hidden">
              <div className="bg-slate-50/50 px-5 py-3 border-b border-slate-100">
                <h3 className="text-[12px] font-black text-[#0f172a] uppercase tracking-wider">{g.title}</h3>
              </div>
              <div className="p-5 flex flex-col gap-4">
                {g.keys.map((field) => (
                  <div key={field.k} className="flex items-center justify-between gap-4">
                    <label className="text-[13px] font-bold text-slate-700 flex-1 cursor-pointer">
                      {field.label}
                      {field.nullable && (
                        <span className="block text-[10.5px] font-medium text-slate-400 mt-0.5">
                          Leave blank for {field.blank.toLowerCase()}
                        </span>
                      )}
                    </label>
                    <div className="shrink-0">
                      {renderInput(field)}
                    </div>
                  </div>
                ))}
              </div>
            </div>
          ))}
        </div>

        <div className="flex flex-col sm:flex-row items-center justify-between border-t border-slate-200 pt-5 gap-4">
          <div className="flex items-center gap-3 w-full sm:w-auto">
            <p className="text-[11px] text-slate-500 font-medium italic">
              Changes affect future checkouts only.
            </p>
            {hasChanges && (
              <span className="text-[10px] font-extrabold bg-amber-100 text-amber-700 px-2 py-1 rounded-md anim-fade-in uppercase tracking-wider">
                Unsaved changes
              </span>
            )}
          </div>
          <div className="flex items-center gap-3 w-full sm:w-auto justify-end">
            <button
              onClick={discardChanges}
              disabled={!hasChanges || saving}
              className="px-5 py-2.5 bg-white border border-slate-200 text-slate-600 rounded-xl text-[12px] font-extrabold hover:bg-slate-50 transition-colors lib-btn-press disabled:opacity-50 disabled:cursor-not-allowed"
            >
              Discard Changes
            </button>
            <button
              onClick={save}
              disabled={!hasChanges || saving}
              className="flex items-center gap-2 bg-[#8B1A24] hover:bg-[#6b141c] text-white text-[13px] font-extrabold py-2.5 px-5 rounded-xl transition-colors lib-btn-press disabled:opacity-50 disabled:cursor-not-allowed"
            >
              <Save className="w-4 h-4" />
              {saving ? 'Saving…' : 'Save Policies'}
            </button>
          </div>
        </div>
      </div>

    </div>
  );
}
