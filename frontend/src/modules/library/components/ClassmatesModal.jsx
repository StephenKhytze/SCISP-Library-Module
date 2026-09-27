import React from 'react';
import useDialog from '../hooks/useDialog';

export default function ClassmatesModal({ open, sectionName, classmates, onClose }) {
  const panelRef = useDialog(open, onClose);

  if (!open) return null;

  return (
    <div className="fixed inset-0 z-[9999] flex flex-col justify-end md:items-center md:justify-center p-0 md:p-4">
      <div
        className="absolute inset-0 bg-slate-900/40 backdrop-blur-sm anim-fade-in"
        onClick={onClose}
        aria-hidden="true"
      />

      <div
        ref={panelRef}
        role="dialog"
        aria-modal="true"
        aria-labelledby="classmates-modal-title"
        className="relative z-10 bg-white w-full md:max-w-md rounded-t-[2rem] md:rounded-[1.5rem] shadow-2xl flex flex-col max-h-[85vh] anim-slide-up md:anim-fade-in"
      >
        <div className="p-5 border-b border-slate-100 flex items-center justify-between">
          <div>
            <h3 id="classmates-modal-title" className="font-extrabold text-slate-900 text-lg leading-tight">Classmates</h3>
            <p className="text-xs text-slate-500 font-medium">{sectionName}</p>
          </div>
          <button
            onClick={onClose}
            aria-label="Close classmates"
            className="p-2 hover:bg-slate-100 rounded-full text-slate-400 hover:text-slate-600 transition-all duration-150 active:scale-[0.95] cursor-pointer"
          >
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" strokeWidth={2.5} stroke="currentColor" className="w-5 h-5" aria-hidden="true"><path strokeLinecap="round" strokeLinejoin="round" d="M6 18L18 6M6 6l12 12" /></svg>
          </button>
        </div>
        <div className="p-4 overflow-y-auto pb-8">
          {!classmates ? (
            <div className="text-center py-8">
              <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" strokeWidth={1.5} stroke="currentColor" className="w-12 h-12 text-slate-300 mx-auto mb-3" aria-hidden="true">
                <path strokeLinecap="round" strokeLinejoin="round" d="M18 18.72a9.094 9.094 0 003.741-.479 3 3 0 00-4.682-2.72m.94 3.198l.001.031c0 .225-.012.447-.037.666A11.944 11.944 0 0112 21c-2.17 0-4.207-.576-5.963-1.584A6.062 6.062 0 016 18.719m12 0a5.971 5.971 0 00-.941-3.197m0 0A5.995 5.995 0 0012 12.75a5.995 5.995 0 00-5.058 2.772m0 0a3 3 0 00-4.681 2.72 8.986 8.986 0 003.74.477m.94-3.197a5.971 5.971 0 00-.94 3.197M15 6.75a3 3 0 11-6 0 3 3 0 016 0zm6 3a2.25 2.25 0 11-4.5 0 2.25 2.25 0 014.5 0zm-13.5 0a2.25 2.25 0 11-4.5 0 2.25 2.25 0 014.5 0z" />
              </svg>
              <p className="text-sm font-bold text-slate-600 mb-1">Classmates Unavailable</p>
              <p className="text-xs text-slate-500">The class roster is currently pending synchronization.</p>
            </div>
          ) : classmates.length === 0 ? (
             <p className="text-sm text-slate-500 italic text-center py-8">No other students enrolled in this section.</p>
          ) : (
            <div className="flex flex-col gap-2">
              {classmates.map(c => (
                <div key={c.user_id} className="flex items-center gap-3 p-3 rounded-xl border border-slate-100 bg-slate-50">
                  <div className="w-10 h-10 rounded-full bg-indigo-100 flex items-center justify-center text-indigo-700 font-black text-sm shrink-0">
                    {c.first_name ? c.first_name[0] : c.username[0]}
                  </div>
                  <div>
                    <div className="font-extrabold text-slate-800 text-sm">
                      {c.first_name || c.last_name ? `${c.first_name || ''} ${c.last_name || ''}`.trim() : c.username}
                    </div>
                    <div className="text-xs font-bold text-slate-400">ID: {c.username}</div>
                  </div>
                </div>
              ))}
            </div>
          )}
        </div>
      </div>
    </div>
  );
}
