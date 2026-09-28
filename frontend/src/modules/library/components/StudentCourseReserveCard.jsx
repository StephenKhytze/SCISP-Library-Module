import React, { useState } from 'react';
import StatusBadge from './StatusBadge';
import { Loader2 } from 'lucide-react';

/**
 * A single course reserve as the enrolled student sees it.
 *
 * `allocated_copies` / `available_for_section` are derived by the caller from
 * the copies nested under this reserve, so the counts describe THIS reserve's
 * allocation only — never general-circulation copies of the same title.
 *
 * `student_request_status` covers the states that need the borrower's own
 * request record. That data is not served yet, so today only `on_loan` (derived
 * from the reserve's own active transactions) and the availability states are
 * reachable; the rest stay dormant rather than being simulated.
 */
export default function StudentCourseReserveCard({ reserve, onViewClassmates, onRequestBorrow, borrowingEnabled = true }) {
  const [isRequesting, setIsRequesting] = useState(false);

  const canRequest = typeof onRequestBorrow === 'function' && borrowingEnabled !== false;

  const handleRequest = async () => {
    if (!canRequest) return;
    setIsRequesting(true);
    try {
      await onRequestBorrow(reserve);
    } finally {
      setIsRequesting(false);
    }
  };

  const reqStatus = reserve.student_request_status;
  const allocated = reserve.allocated_copies ?? 0;
  const available = reserve.available_for_section ?? 0;
  const isAvailable = available > 0;
  const isAllInUse = !isAvailable && allocated > 0;

  let actionContent = null;

  if (reqStatus === 'pending' || reqStatus === 'requested') {
    actionContent = (
      <div className="flex flex-col items-center justify-center py-2 px-3 bg-amber-50 rounded-xl border border-amber-200">
        <span className="text-[11px] font-extrabold text-amber-700 uppercase">Requested</span>
        <span className="text-[9px] text-amber-600 font-bold">Waiting for the librarian</span>
      </div>
    );
  } else if (reqStatus === 'queued') {
    actionContent = (
      <div className="flex flex-col items-center justify-center py-2 px-3 bg-amber-50 rounded-xl border border-amber-200">
        <span className="text-[11px] font-extrabold text-amber-700 uppercase">Queued</span>
        <span className="text-[9px] text-amber-600 font-bold">
          {reserve.queue_position ? `Position #${reserve.queue_position} for this reserve` : 'Waiting for this reserve'}
        </span>
      </div>
    );
  } else if (reqStatus === 'ready_for_pickup') {
    actionContent = (
      <div className="flex flex-col items-center justify-center py-2 px-3 bg-emerald-50 rounded-xl border border-emerald-200">
        <span className="text-[11px] font-extrabold text-emerald-700 uppercase">Ready for Pickup</span>
        <span className="text-[9px] text-emerald-600 font-bold">Go to the circulation desk</span>
      </div>
    );
  } else if (reqStatus === 'on_loan') {
    actionContent = (
      <div className="flex flex-col items-center justify-center py-2 px-3 bg-blue-50 rounded-xl border border-blue-200">
        <span className="text-[11px] font-extrabold text-blue-700 uppercase">On Loan</span>
        <span className="text-[9px] text-blue-600 font-bold">Checked out to you</span>
      </div>
    );
  } else if (isAvailable) {
    actionContent = (
      <div className="flex flex-col items-center gap-1">
        <button
          onClick={handleRequest}
          disabled={!canRequest || isRequesting}
          title={canRequest ? undefined : 'Requesting a reserve copy is not available yet.'}
          className="w-full sm:w-auto flex items-center justify-center gap-2 py-2 px-4 bg-[#8B1A24] hover:bg-[#651020] text-white rounded-xl text-[11px] font-bold transition-all duration-150 active:scale-[0.98] cursor-pointer disabled:opacity-50 disabled:cursor-not-allowed disabled:hover:bg-[#8B1A24] disabled:active:scale-100"
        >
          {isRequesting ? (
            <>
              <Loader2 className="w-3.5 h-3.5 animate-spin" aria-hidden="true" />
              Requesting...
            </>
          ) : (
            'Request to Borrow'
          )}
        </button>
        {!borrowingEnabled ? (
          <span className="text-[9px] font-bold text-red-500 text-center leading-tight">
            Student borrowing is currently disabled by the library.
          </span>
        ) : !canRequest && (
          <span className="text-[9px] font-bold text-slate-400 text-center leading-tight">
            Ask the circulation desk — online requests are not live yet
          </span>
        )}
      </div>
    );
  } else if (isAllInUse) {
    actionContent = (
      <div className="flex flex-col items-center justify-center py-2 px-3 bg-slate-50 rounded-xl border border-slate-200 text-center">
        <span className="text-[11px] font-extrabold text-slate-700 uppercase">All Copies In Use</span>
        <span className="text-[9px] text-slate-500 font-bold">Check back later</span>
      </div>
    );
  } else {
    actionContent = (
      <div className="text-[10px] font-bold text-slate-400 italic py-2 text-center">
        No copies allocated yet
      </div>
    );
  }

  return (
    <div className="border border-sky-200 rounded-[1.25rem] p-4 bg-white shadow-sm flex flex-col justify-between gap-3 hover:-translate-y-0.5 hover:shadow-md transition-all duration-200">
      <div>
        <div className="flex justify-between items-start gap-2 mb-2">
          <div className="flex gap-1.5 flex-wrap">
            <span className="bg-[#0369a1] text-white text-[8.5px] font-extrabold px-2 py-0.5 rounded-full uppercase tracking-wider">
              COURSE: {reserve.course}
            </span>
            <StatusBadge status={reserve.status} />
          </div>
          <button
            onClick={() => onViewClassmates(reserve)}
            className="shrink-0 flex items-center justify-center gap-1.5 px-2.5 py-1 bg-indigo-50 hover:bg-indigo-100 text-indigo-700 border border-indigo-100 rounded-lg text-[10px] font-extrabold transition-colors cursor-pointer uppercase tracking-wider"
            title="View Classmates"
          >
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" strokeWidth={2.5} stroke="currentColor" className="w-3 h-3" aria-hidden="true"><path strokeLinecap="round" strokeLinejoin="round" d="M15 19.128a9.38 9.38 0 002.625.372 9.337 9.337 0 004.121-.952 4.125 4.125 0 00-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 018.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0111.964-3.07M12 6.375a3.375 3.375 0 11-6.75 0 3.375 3.375 0 016.75 0zm8.25 2.25a2.625 2.625 0 11-5.25 0 2.625 2.625 0 015.25 0z" /></svg>
            Classmates
          </button>
        </div>

        <h4 className="font-extrabold text-[14px] text-slate-900 leading-tight mb-1 pr-2">{reserve.title}</h4>

        <div className="text-[11px] text-slate-500 flex flex-col gap-1 mt-2">
          <p>Professor: <span className="font-extrabold text-slate-800">{reserve.requester || reserve.teacher_name}</span></p>
          <p>Reserve Type: <span className="font-bold text-slate-700">{reserve.type}</span></p>
        </div>
      </div>

      {reserve.note && (
        <div className="border border-slate-100 rounded-xl p-3 text-[11px] text-slate-600 italic bg-slate-50 mt-1 relative">
          <div className="absolute -top-2 left-4 px-1 bg-slate-50 text-[9px] font-bold text-slate-400 uppercase">Syllabus Note</div>
          &ldquo;{reserve.note}&rdquo;
        </div>
      )}

      <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-3 mt-1 pt-3 border-t border-slate-100">
        <div className="flex flex-col gap-0.5">
          <div className="text-[10px] font-bold text-slate-500 uppercase tracking-wider">Available for this section</div>
          <div className="text-xs font-black text-[#0369a1]">
            {available} <span className="text-slate-400 font-bold">/ {allocated} allocated {allocated === 1 ? 'copy' : 'copies'}</span>
          </div>
        </div>

        <div className="w-full sm:w-auto shrink-0">
          {actionContent}
        </div>
      </div>
    </div>
  );
}
