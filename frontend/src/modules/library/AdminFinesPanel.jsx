import React, { useState } from 'react';
import api from '../../api';

/**
 * Librarian fine settlement.
 *
 * The Library module does not process money. "Record Payment" means the
 * borrower settled externally; "Waive" means the librarian forgave the amount.
 * Both simply reduce users.total_fines. Partial amounts are allowed and the
 * balance can never go below zero.
 */
export default function AdminFinesPanel({ finesData, onSettled }) {
  const [target, setTarget] = useState(null); // { user, type }
  const [amount, setAmount] = useState('');
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState('');
  const [notice, setNotice] = useState('');

  const debtors = finesData?.debtors || [];
  const dailyRate = finesData?.daily_rate ?? 10;
  const totalOutstanding = finesData?.total_outstanding ?? 0;

  const peso = (n) => `₱${Number(n || 0).toFixed(2)}`;

  const openSettle = (user, type) => {
    setTarget({ user, type });
    setAmount(Number(user.total_fines).toFixed(2));
    setError('');
    setNotice('');
  };

  const closeSettle = () => {
    setTarget(null);
    setAmount('');
    setError('');
  };

  const submitSettlement = async () => {
    const value = parseFloat(amount);

    if (!value || value <= 0) {
      setError('Enter an amount greater than zero.');
      return;
    }

    setSubmitting(true);
    setError('');

    try {
      const res = await api.post('/library/fines/settle', {
        user_id: target.user.user_id,
        amount: value,
        type: target.type,
      });

      setNotice(res.data?.message || 'Settlement recorded.');
      closeSettle();
      if (onSettled) onSettled();
    } catch (err) {
      setError(err.response?.data?.error || err.response?.data?.message || 'Settlement failed.');
    } finally {
      setSubmitting(false);
    }
  };

  return (
    <div className="bg-white rounded-[1.25rem] p-5 sm:p-6 shadow-xs border border-slate-200/70">
      <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-2 mb-4 pb-2 border-b border-slate-100">
        <div>
          <h2 className="text-[17px] font-extrabold text-[#0f172a]">Unpaid Library Fines</h2>
          <p className="text-slate-400 text-[11px] font-medium mt-0.5">
            Overdue fees accrue at {peso(dailyRate)} per calendar day and are charged on return.
          </p>
        </div>
        <span className="self-start sm:self-auto border border-pink-200 bg-pink-50 text-[#be123c] text-[11px] font-extrabold px-3 py-1.5 rounded-lg">
          Outstanding: {peso(totalOutstanding)}
        </span>
      </div>

      {notice && (
        <div className="mb-3 rounded-xl border border-emerald-200 bg-emerald-50 px-3.5 py-2.5 text-[11.5px] font-semibold text-emerald-800">
          {notice}
        </div>
      )}

      {debtors.length === 0 ? (
        <div className="border border-slate-100 rounded-2xl py-10 px-6 flex flex-col items-center justify-center bg-slate-50/50 text-center">
          <h3 className="font-extrabold text-[#0f172a] text-[13px] mb-1">No outstanding fines.</h3>
          <p className="text-slate-400 text-[11px] font-medium">
            Fines appear here when an overdue book is checked in.
          </p>
        </div>
      ) : (
        <div className="flex flex-col gap-2.5">
          {debtors.map((d) => (
            <div
              key={d.user_id}
              className="border border-slate-200/80 rounded-xl p-3.5 bg-white flex flex-col sm:flex-row sm:items-center justify-between gap-3 shadow-2xs"
            >
              <div>
                <div className="flex items-center gap-2 flex-wrap">
                  <h3 className="font-extrabold text-[13px] text-[#0f172a] leading-tight">{d.username}</h3>
                  <span className="text-[9px] font-black uppercase tracking-wider px-2 py-0.5 rounded bg-slate-100 text-slate-600">
                    {d.role}
                  </span>
                </div>
                <p className="text-[11px] text-slate-400 font-medium mt-0.5">Borrower ID: {d.user_id}</p>
              </div>

              <div className="flex items-center gap-2 self-end sm:self-auto shrink-0">
                <span className="text-[15px] font-black text-[#8B1A24] mr-1">{peso(d.total_fines)}</span>
                <button
                  onClick={() => openSettle(d, 'paid')}
                  className="bg-emerald-600 hover:bg-emerald-700 text-white text-[11px] font-extrabold py-2 px-3.5 rounded-xl transition-colors cursor-pointer shadow-xs"
                >
                  Record Payment
                </button>
                <button
                  onClick={() => openSettle(d, 'waived')}
                  className="bg-white hover:bg-amber-50 text-amber-700 border border-amber-300 text-[11px] font-extrabold py-2 px-3.5 rounded-xl transition-colors cursor-pointer"
                >
                  Waive
                </button>
              </div>
            </div>
          ))}
        </div>
      )}

      {target && (
        <div
          onClick={(e) => { if (e.target === e.currentTarget) closeSettle(); }}
          className="fixed inset-0 bg-black/60 backdrop-blur-xs flex items-center justify-center p-4 z-[110]"
        >
          <div className="bg-white rounded-[1.5rem] p-6 shadow-2xl border border-slate-100 max-w-sm w-full">
            <span
              className={`inline-block text-[9px] font-black uppercase px-2.5 py-0.5 rounded tracking-wider mb-2 ${
                target.type === 'paid'
                  ? 'bg-emerald-50 text-emerald-700 border border-emerald-200'
                  : 'bg-amber-50 text-amber-800 border border-amber-200'
              }`}
            >
              {target.type === 'paid' ? 'Record Payment' : 'Waive Fine'}
            </span>

            <h2 className="text-[17px] font-black text-[#0f172a] leading-tight">{target.user.username}</h2>
            <p className="text-slate-500 text-[11.5px] font-medium mt-1">
              Current balance <strong className="text-[#8B1A24]">{peso(target.user.total_fines)}</strong>
            </p>

            <label className="block text-[10.5px] font-extrabold text-[#0f172a] mt-4 mb-1 uppercase tracking-wider">
              Amount
            </label>
            <input
              type="number"
              step="0.01"
              min="0.01"
              value={amount}
              onChange={(e) => setAmount(e.target.value)}
              autoFocus
              className="w-full px-3.5 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-[14px] font-bold text-[#0f172a] focus:outline-none focus:ring-1 focus:ring-[#8B1A24]"
            />
            <p className="text-[10.5px] text-slate-400 font-medium mt-1.5">
              Partial amounts are allowed. Anything above the balance settles it in full.
            </p>

            {error && (
              <div className="mt-3 rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-[11px] font-semibold text-rose-700">
                {error}
              </div>
            )}

            <div className="flex justify-end gap-2.5 mt-5">
              <button
                onClick={closeSettle}
                disabled={submitting}
                className="px-4 py-2 border border-slate-200 rounded-xl text-[12px] font-extrabold hover:bg-slate-50 cursor-pointer disabled:opacity-50"
              >
                Cancel
              </button>
              <button
                onClick={submitSettlement}
                disabled={submitting}
                className={`px-4 py-2 rounded-xl text-[12px] font-extrabold text-white cursor-pointer disabled:opacity-50 ${
                  target.type === 'paid' ? 'bg-emerald-600 hover:bg-emerald-700' : 'bg-amber-600 hover:bg-amber-700'
                }`}
              >
                {submitting ? 'Saving…' : target.type === 'paid' ? 'Record Payment' : 'Waive Amount'}
              </button>
            </div>
          </div>
        </div>
      )}
    </div>
  );
}
