import React from 'react';
import StatusBadge from './StatusBadge';

/**
 * Renewal requests awaiting a librarian's decision.
 *
 * Borrowers may only ask; approving here is the only thing that moves a due
 * date. Styled to match the hold queue it sits beside.
 */
export default function RenewalRequestsPanel({ requests, onDecide, busyKey, loading = false }) {
  const pending = requests || [];

  return (
    <div className="bg-white rounded-[1.25rem] p-5 sm:p-6 shadow-xs border border-slate-200/70">
      <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-2 mb-4 pb-2 border-b border-slate-100">
        <div>
          <h2 className="text-[17px] font-extrabold text-[#0f172a]">Renewal Requests</h2>
          <p className="text-slate-400 text-[11px] font-medium mt-0.5">
            Borrowers ask; approving is what extends the due date.
          </p>
        </div>
        <span className="self-start sm:self-auto border border-sky-200 bg-sky-50 text-sky-800 text-[11px] font-extrabold px-3 py-1.5 rounded-lg whitespace-nowrap">
          {loading ? '—' : `${pending.length} Pending`}
        </span>
      </div>

      {loading ? (
        <div className="flex flex-col gap-2.5">
          {Array.from({ length: 2 }).map((_, i) => (
            <div key={i} className="border border-slate-200/80 rounded-xl p-3.5 bg-white animate-pulse">
              <div className="h-3 bg-slate-200 rounded w-1/3 mb-3"></div>
              <div className="h-2.5 bg-slate-100 rounded w-1/2"></div>
            </div>
          ))}
        </div>
      ) : pending.length === 0 ? (
        <div className="border border-slate-100 rounded-2xl py-10 px-6 flex flex-col items-center justify-center bg-slate-50/50 text-center">
          <h3 className="font-extrabold text-[#0f172a] text-[13px] mb-1">No renewal requests.</h3>
          <p className="text-slate-400 text-[11px] font-medium">
            Requests appear here when a borrower asks to extend a loan.
          </p>
        </div>
      ) : (
        <div className="flex flex-col gap-2.5">
          {pending.map((request) => {
            const transaction = request.transaction || {};
            const title = transaction.book_copy?.book?.book_title || 'Unknown title';
            const dueDate = transaction.due_date ? new Date(transaction.due_date).toLocaleDateString() : '—';
            const busy = busyKey === `renewal-${request.renewal_request_id}`;

            return (
              <div
                key={request.renewal_request_id}
                className="border border-slate-200/80 rounded-xl p-3.5 bg-white flex flex-col sm:flex-row sm:items-center justify-between gap-3 shadow-2xs"
              >
                <div className="min-w-0">
                  <div className="flex items-center gap-2 mb-0.5 flex-wrap">
                    <StatusBadge status={request.status} />
                    <h3 className="font-extrabold text-[13px] text-[#0f172a] leading-tight truncate">{title}</h3>
                  </div>
                  <p className="text-[11px] text-slate-400 font-medium">
                    Requested by <span className="font-extrabold text-[#0f172a]">{request.user?.username || `User ${request.user_id}`}</span>
                    {request.user?.role ? ` (${request.user.role})` : ''}
                    {' · '}currently due {dueDate}
                  </p>
                </div>

                <div className="flex flex-wrap items-center gap-2 self-end sm:self-auto shrink-0">
                  <button
                    onClick={() => onDecide(request.renewal_request_id, true)}
                    disabled={busy}
                    className="bg-emerald-600 hover:bg-emerald-700 text-white text-[11px] font-extrabold py-2 px-4 rounded-xl transition-colors cursor-pointer shadow-xs disabled:opacity-60 disabled:cursor-not-allowed"
                  >
                    {busy ? 'Working…' : 'Approve'}
                  </button>
                  <button
                    onClick={() => onDecide(request.renewal_request_id, false)}
                    disabled={busy}
                    className="bg-white hover:bg-rose-50 text-rose-700 border border-rose-300 text-[11px] font-extrabold py-2 px-4 rounded-xl transition-colors cursor-pointer disabled:opacity-60 disabled:cursor-not-allowed"
                  >
                    Deny
                  </button>
                </div>
              </div>
            );
          })}
        </div>
      )}
    </div>
  );
}
