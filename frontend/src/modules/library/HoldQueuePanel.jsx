import React from 'react';

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
}) {
  const label = (request) => {
    if (request.status === 'pending_approval') return 'Getting Approval';
    if (request.status === 'fulfilled') return 'Ready for Pickup';
    return `Queue #${request.queuePosition ?? request.pos}`;
  };

  const badgeColor = (request) => {
    if (request.status === 'pending_approval') return 'bg-orange-500';
    if (request.status === 'fulfilled') return 'bg-emerald-600';
    return 'bg-[#8B1A24]';
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
            className="w-36 px-3 py-1.5 bg-slate-50 border border-slate-200 rounded-lg text-[11px] focus:outline-none focus:border-amber-400 text-[#0f172a]"
          >
            <option value="">All Statuses</option>
            <option value="pending_approval">Getting Approval</option>
            <option value="fulfilled">Ready for Pickup</option>
            <option value="pending">Waitlisted</option>
          </select>

          <select
            value={bookFilter}
            onChange={(e) => setBookFilter(e.target.value)}
            className="w-40 px-3 py-1.5 bg-slate-50 border border-slate-200 rounded-lg text-[11px] focus:outline-none focus:border-amber-400 text-[#0f172a] truncate"
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
            className="w-full sm:w-48 px-3 py-1.5 bg-slate-50 border border-slate-200 rounded-lg text-[11px] focus:outline-none focus:border-amber-400 text-[#0f172a]"
          />

          <span className="border border-amber-300 bg-amber-50/60 text-amber-800 text-[10px] font-extrabold px-3 py-1 rounded-lg whitespace-nowrap">
            {totalRequests} Request{totalRequests === 1 ? '' : 's'}
          </span>
        </div>
      </div>

      {requests.length === 0 ? (
        <div className="border border-slate-100 rounded-2xl py-10 px-6 flex flex-col items-center justify-center bg-slate-50/50 text-center">
          <h3 className="font-extrabold text-[#0f172a] text-[13px] mb-1">No hold requests.</h3>
          <p className="text-slate-400 text-[11px] font-medium">
            Holds appear here when a borrower reserves a title.
          </p>
        </div>
      ) : (
        <div className="flex flex-col gap-3">
          {requests.map((request) => (
            <div
              key={request.id}
              className="border border-slate-200/80 rounded-xl p-4 bg-white flex flex-col sm:flex-row sm:items-center justify-between gap-3 shadow-2xs"
            >
              <div className="min-w-0">
                <div className="flex items-center gap-2 mb-0.5 flex-wrap">
                  <span className={`${badgeColor(request)} text-white text-[9px] font-black uppercase px-2 py-0.5 rounded leading-none`}>
                    {label(request)}
                  </span>
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
                    onClick={() => onAccept(request.id)}
                    className="bg-emerald-600 hover:bg-emerald-700 text-white text-[11px] font-extrabold py-2 px-4 rounded-xl transition-colors cursor-pointer shadow-xs"
                  >
                    Accept
                  </button>
                )}

                {request.status === 'fulfilled' && (
                  <button
                    onClick={(e) => onCheckout(e, request.requesterId, request.copyId)}
                    className="bg-[#8B1A24] hover:bg-[#6b141c] text-white text-[11px] font-extrabold py-2 px-4 rounded-xl transition-colors cursor-pointer shadow-xs"
                  >
                    Check Out
                  </button>
                )}

                <button
                  onClick={() => onCancel(request.id)}
                  title="Cancels the hold and returns the copy to circulation."
                  className="bg-slate-100 hover:bg-slate-200 text-slate-700 text-[11px] font-extrabold py-2 px-4 rounded-xl transition-colors cursor-pointer"
                >
                  Cancel / Release
                </button>
              </div>
            </div>
          ))}
        </div>
      )}
    </div>
  );
}
