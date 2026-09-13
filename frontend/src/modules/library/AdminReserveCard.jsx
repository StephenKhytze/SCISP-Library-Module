import React from 'react';
import StatusBadge from './StatusBadge';

/**
 * A course reserve as the librarian sees it: the same summary the student gets,
 * plus the approve / deny / allocate / release actions for its current status.
 *
 * Shared by the mobile and desktop layouts so the two cannot drift apart.
 */
export default function AdminReserveCard({ reserve, onUpdateStatus, onAllocate, busyKey }) {
  const approveKey = `reserve-${reserve.id}-approved`;
  const denyKey = `reserve-${reserve.id}-denied`;
  const allocateKey = `reserve-${reserve.id}-allocate`;
  const releaseKey = `reserve-${reserve.id}-released`;
  const anyBusy = busyKey !== null && busyKey !== undefined;

  const actionClasses = "text-[10.5px] font-extrabold py-2 px-3 rounded-lg text-white transition-colors cursor-pointer disabled:opacity-60 disabled:cursor-not-allowed";

  return (
    <div className="border border-sky-200 rounded-[1.25rem] p-4 bg-white shadow-xs flex flex-col justify-between gap-2.5">
      <div>
        <div className="flex gap-1.5 mb-1.5 flex-wrap">
          <span className="bg-[#0369a1] text-white text-[8.5px] font-extrabold px-2 py-0.5 rounded-full uppercase tracking-wider">
            COURSE: {reserve.course}
          </span>
          <StatusBadge status={reserve.status} />
        </div>

        <h4 className="font-extrabold text-[13px] text-[#0f172a] leading-tight mb-1.5">{reserve.title}</h4>

        <div className="text-[10.5px] text-slate-500 flex flex-col gap-0.5">
          <p>Reserve Type: <span className="font-extrabold text-[#0f172a]">{reserve.type}</span></p>
          <p>
            Requested by <span className="font-extrabold text-[#0f172a]">{reserve.requester}</span>
            <span className="text-slate-400"> ({reserve.role}) on {reserve.date}</span>
          </p>
          <p>
            Allocated: <span className="font-extrabold text-[#0f172a]">{reserve.available_for_section ?? 0} free</span>
            <span className="text-slate-400"> of {reserve.allocated_copies ?? 0} {reserve.allocated_copies === 1 ? 'copy' : 'copies'} · {reserve.copies_requested ?? 0} requested</span>
          </p>
        </div>
      </div>

      {reserve.note && (
        <div className="border border-slate-100 rounded-xl p-2.5 text-[10.5px] text-slate-600 italic bg-slate-50">
          &ldquo;{reserve.note}&rdquo;
        </div>
      )}

      <div className="flex flex-wrap gap-2 mt-1 pt-3 border-t border-slate-100">
        {reserve.status === 'pending' && (
          <>
            <button
              onClick={() => onUpdateStatus(reserve.id, 'approved')}
              disabled={anyBusy}
              className={`${actionClasses} bg-emerald-600 hover:bg-emerald-700`}
            >
              {busyKey === approveKey ? 'Approving…' : 'Approve Reserve'}
            </button>
            <button
              onClick={() => onUpdateStatus(reserve.id, 'denied')}
              disabled={anyBusy}
              className={`${actionClasses} bg-rose-600 hover:bg-rose-700`}
            >
              {busyKey === denyKey ? 'Denying…' : 'Deny'}
            </button>
          </>
        )}

        {reserve.status === 'approved' && (
          <button
            onClick={() => onAllocate(reserve.id)}
            disabled={anyBusy}
            className={`${actionClasses} bg-[#0369a1] hover:bg-[#0284c7]`}
          >
            {busyKey === allocateKey ? 'Allocating…' : 'Allocate Physical Copies'}
          </button>
        )}

        {(reserve.status === 'approved' || reserve.status === 'active') && (
          <button
            onClick={() => onUpdateStatus(reserve.id, 'released')}
            disabled={anyBusy}
            className={`${actionClasses} bg-amber-500 hover:bg-amber-600`}
          >
            {busyKey === releaseKey ? 'Releasing…' : 'Release Copies Back to Gen. Circulation'}
          </button>
        )}

        {reserve.status !== 'pending' && reserve.status !== 'approved' && reserve.status !== 'active' && (
          <p className="text-[10.5px] text-slate-400 font-medium italic">No further action for this reserve.</p>
        )}
      </div>
    </div>
  );
}
