import React, { useState } from 'react';
import StatusBadge from './StatusBadge';

/**
 * Librarian hold queue.
 *
 * Shared by the mobile and desktop layouts so both offer the same actions —
 * previously mobile could only cancel, which left the workflow unfinishable
 * on a phone.
 */
export default function HoldQueuePanel({
  requests,
  totalRequests,
  bookOptions = [],
  statusFilter,
  setStatusFilter,
  bookFilter,
  setBookFilter,
  search,
  setSearch,
  onAccept,
  onCheckout,
  onCancel,
  loading = false,
}) {
  const [acting, setActing] = useState(null);

  const run = async (key, fn) => {
    if (acting) return;
    setActing(key);
    try {
      await fn();
    } finally {
      setActing(null);
    }
  };

  const statusOf = (request) => {
    if (request.status === 'pending_approval') return { status: 'pending approval', label: 'Getting Approval' };
    if (request.status === 'fulfilled') return { status: 'ready for pickup', label: 'Ready for Pickup' };
    // queuePosition is the raw number; pos is the already-formatted "#3" label.
    const position = request.queuePosition ?? request.pos;
    const text = typeof position === 'number' ? `Queue #${position}` : `Queue ${position}`;
    return { status: 'queued', label: text };
  };

  return (
    <div className="bg-white rounded-[1.25rem] p-5 sm:p-6 shadow-xs border border-slate-200/70">
      <div className="flex flex-col lg:flex-row lg:items-center justify-between gap-3 mb-4 pb-1">
        <div>
          <h2 className="text-[17px] font-extrabold text-[#0f172a]">Hold Request Queue</h2>
          <p className="text-slate-400 text-[11px] font-medium mt-0.5">
            First come, first served. A copy is held for the next borrower in line.
          </p>
        </div>

        <div className="flex flex-wrap items-center gap-2">
          <select
            value={statusFilter}
            onChange={(e) => setStatusFilter(e.target.value)}
            aria-label="Filter holds by status"
            className="w-36 px-3 py-1.5 bg-slate-50 border border-slate-200 rounded-lg text-[11px] focus:border-amber-400 text-[#0f172a]"
          >
            <option value="">All Statuses</option>
            <option value="pending_approval">Getting Approval</option>
            <option value="fulfilled">Ready for Pickup</option>
            <option value="pending">Waitlisted</option>
          </select>

          <select
            value={bookFilter}
            onChange={(e) => setBookFilter(e.target.value)}
            aria-label="Filter holds by book"
            className="w-40 px-3 py-1.5 bg-slate-50 border border-slate-200 rounded-lg text-[11px] focus:border-amber-400 text-[#0f172a] truncate"
          >
            <option value="">All Books</option>
            {bookOptions.filter(Boolean).map((title, i) => (
              <option key={i} value={title}>{title}</option>
            ))}
          </select>

          <input
            type="text"
            placeholder="Search borrower or ID…"
            value={search}
            onChange={(e) => setSearch(e.target.value)}
            aria-label="Search hold requests"
            className="w-full sm:w-48 px-3 py-1.5 bg-slate-50 border border-slate-200 rounded-lg text-[11px] focus:border-amber-400 text-[#0f172a]"
          />

          <span className="border border-amber-300 bg-amber-50/60 text-amber-800 text-[10px] font-extrabold px-3 py-1 rounded-lg whitespace-nowrap">
            {loading ? '—' : `${totalRequests} Request${totalRequests === 1 ? '' : 's'}`}
          </span>
        </div>
      </div>

      {loading ? (
        <div className="flex flex-col gap-3">
          {Array.from({ length: 2 }).map((_, i) => (
            <div key={i} className="border border-slate-200/80 rounded-xl p-4 bg-white animate-pulse">
              <div className="h-3 bg-slate-200 rounded w-1/3 mb-3"></div>
              <div className="h-2.5 bg-slate-100 rounded w-1/2"></div>
            </div>
          ))}
        </div>
      ) : requests.length === 0 ? (
        <div className="border border-slate-100 rounded-2xl py-10 px-6 flex flex-col items-center justify-center bg-slate-50/50 text-center">
          <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" strokeWidth={1.5} stroke="currentColor" className="w-8 h-8 text-slate-300 mb-2" aria-hidden="true">
            <path strokeLinecap="round" strokeLinejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z" />
          </svg>
          <h3 className="font-extrabold text-[#0f172a] text-[13px] mb-1">No hold requests.</h3>
          <p className="text-slate-400 text-[11px] font-medium">
            Holds appear here when a borrower reserves a title.
          </p>
        </div>
      ) : (
        <div className="flex flex-col gap-3">
          {requests.map((request) => {
            const badge = statusOf(request);
            const acceptKey = `accept-${request.id}`;
            const checkoutKey = `checkout-${request.id}`;
            const cancelKey = `cancel-${request.id}`;

            return (
              <div
                key={request.id}
                className="border border-slate-200/80 rounded-xl p-4 bg-white flex flex-col sm:flex-row sm:items-center justify-between gap-3 shadow-2xs"
              >
                <div className="min-w-0">
                  <div className="flex items-center gap-2 mb-0.5 flex-wrap">
                    <StatusBadge status={badge.status} label={badge.label} />
                    <h3 className="font-extrabold text-[13px] text-[#0f172a] leading-tight truncate">
                      {request.bookTitle}
                    </h3>
                  </div>
                  <p className="text-[11px] text-slate-400 font-medium">
                    Requested by <span className="font-extrabold text-[#0f172a]">{request.requester}</span>
                    {request.role ? ` (${request.role})` : ''}
                    {request.date ? ` on ${new Date(request.date).toLocaleDateString()}` : ''}
                  </p>
                </div>

                <div className="flex flex-wrap items-center gap-2 self-end sm:self-auto shrink-0">
                  {request.status === 'pending_approval' && (
                    <button
                      onClick={() => run(acceptKey, () => onAccept(request.id))}
                      disabled={acting !== null}
                      className="bg-emerald-600 hover:bg-emerald-700 text-white text-[11px] font-extrabold py-2 px-4 rounded-xl transition-colors cursor-pointer shadow-xs disabled:opacity-60 disabled:cursor-not-allowed"
                    >
                      {acting === acceptKey ? 'Accepting…' : 'Accept'}
                    </button>
                  )}

                  {request.status === 'fulfilled' && (
                    <button
                      onClick={(e) => run(checkoutKey, () => onCheckout(e, request.requesterId, request.copyId))}
                      disabled={acting !== null}
                      className="bg-[#8B1A24] hover:bg-[#6b141c] text-white text-[11px] font-extrabold py-2 px-4 rounded-xl transition-colors cursor-pointer shadow-xs disabled:opacity-60 disabled:cursor-not-allowed"
                    >
                      {acting === checkoutKey ? 'Checking out…' : 'Check Out'}
                    </button>
                  )}

                  <button
                    onClick={() => run(cancelKey, () => onCancel(request.id))}
                    disabled={acting !== null}
                    title="Cancels the hold and returns the copy to circulation."
                    className="bg-slate-100 hover:bg-slate-200 text-slate-700 text-[11px] font-extrabold py-2 px-4 rounded-xl transition-colors cursor-pointer disabled:opacity-60 disabled:cursor-not-allowed"
                  >
                    {acting === cancelKey ? 'Cancelling…' : 'Cancel / Release'}
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
