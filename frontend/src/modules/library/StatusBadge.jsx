import React from 'react';

/**
 * The single source of truth for status colour and casing across the Library.
 *
 * `status` is the raw value (enum or human string); underscores are turned into
 * spaces and the text is uppercased by CSS, so `pending_approval`, `Pending
 * Approval` and `PENDING APPROVAL` all render identically. Pass `label` when
 * the display wording should differ from the underlying status.
 */
export default function StatusBadge({ status, label, className = '' }) {
  const s = String(status || '').toLowerCase().replace(/_/g, ' ').trim();

  const baseClasses = "text-[10px] sm:text-xs font-extrabold px-2.5 py-0.5 rounded-full uppercase tracking-wider inline-flex items-center justify-center border transition-colors";
  let colorClasses = "border-slate-300 bg-slate-50 text-slate-700";

  // Circulation / inventory
  if (s === 'available') colorClasses = "border-emerald-300 bg-emerald-50 text-emerald-700";
  else if (s === 'course reserved' || s === 'course reserve') colorClasses = "border-blue-300 bg-blue-50 text-blue-700";
  else if (s === 'on hold' || s === 'requested' || s === 'queued') colorClasses = "border-amber-300 bg-amber-50 text-amber-700";
  else if (s === 'ready for pickup' || s === 'fulfilled') colorClasses = "border-emerald-400 bg-emerald-100 text-emerald-800";
  else if (s === 'checked out' || s === 'on loan') colorClasses = "border-orange-300 bg-orange-50 text-orange-700";
  else if (s === 'overdue' || s === 'damaged' || s === 'lost') colorClasses = "border-rose-300 bg-rose-50 text-rose-700";
  else if (s === 'returned') colorClasses = "border-slate-300 bg-slate-100 text-slate-600";

  // Reserve / hold lifecycle
  else if (s === 'pending' || s === 'pending approval') colorClasses = "border-amber-300 bg-amber-50 text-amber-700";
  else if (s === 'approved') colorClasses = "border-emerald-300 bg-emerald-50 text-emerald-700";
  else if (s === 'active') colorClasses = "border-sky-300 bg-sky-50 text-sky-700";
  else if (s === 'denied') colorClasses = "border-rose-300 bg-rose-50 text-rose-700";
  else if (s === 'released' || s === 'cancelled' || s === 'canceled') colorClasses = "border-slate-300 bg-slate-100 text-slate-600";

  return (
    <span className={`${baseClasses} ${colorClasses} ${className}`}>
      {label ?? s}
    </span>
  );
}
