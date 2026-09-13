import React, { createContext, useContext, useState, useCallback, useMemo } from 'react';
import { CheckCircle, XCircle, AlertTriangle, Info, X } from 'lucide-react';

const ToastContext = createContext(null);

export function ToastProvider({ children }) {
  const [toasts, setToasts] = useState([]);

  const removeToast = useCallback((id) => {
    setToasts((prev) => prev.filter((toast) => toast.id !== id));
  }, []);

  const addToast = useCallback((message, type = 'info', duration = 3000) => {
    const id = Date.now().toString() + Math.random().toString(36).slice(2, 11);
    setToasts((prev) => [...prev, { id, message, type }]);

    if (duration > 0) {
      setTimeout(() => removeToast(id), duration);
    }
  }, [removeToast]);

  const api = useMemo(() => ({
    success: (msg, duration) => addToast(msg, 'success', duration),
    // Errors persist longer than the rest.
    error: (msg, duration = 5000) => addToast(msg, 'error', duration),
    warning: (msg, duration) => addToast(msg, 'warning', duration),
    info: (msg, duration) => addToast(msg, 'info', duration),
  }), [addToast]);

  return (
    <ToastContext.Provider value={api}>
      {children}
      <div
        role="status"
        aria-live="polite"
        className="fixed bottom-6 sm:bottom-4 right-4 left-4 sm:left-auto z-[200] flex flex-col gap-2 sm:max-w-sm pointer-events-none"
      >
        {toasts.map((toast) => (
          <ToastItem key={toast.id} toast={toast} onRemove={() => removeToast(toast.id)} />
        ))}
      </div>
    </ToastContext.Provider>
  );
}

function ToastItem({ toast, onRemove }) {
  const icons = {
    success: <CheckCircle className="w-5 h-5 text-green-500" />,
    error: <XCircle className="w-5 h-5 text-red-500" />,
    warning: <AlertTriangle className="w-5 h-5 text-amber-500" />,
    info: <Info className="w-5 h-5 text-blue-500" />
  };

  const bgs = {
    success: 'bg-green-50 border-green-200',
    error: 'bg-red-50 border-red-200',
    warning: 'bg-amber-50 border-amber-200',
    info: 'bg-blue-50 border-blue-200'
  };

  return (
    <div className={`pointer-events-auto flex items-start gap-3 p-4 rounded-[1.25rem] shadow-xl shadow-black/5 border anim-slide-in-right ${bgs[toast.type]}`}>
      <div className="shrink-0 mt-0.5">{icons[toast.type]}</div>
      <div className="flex-1 text-[13px] font-bold text-slate-800 break-words pr-2">
        {toast.message}
      </div>
      <button
        onClick={onRemove}
        aria-label="Dismiss notification"
        className="shrink-0 text-slate-400 hover:text-slate-600 hover:bg-black/5 p-1 -m-1 rounded-full transition-all duration-150 active:scale-95 cursor-pointer"
      >
        <X className="w-4 h-4" aria-hidden="true" />
      </button>
    </div>
  );
}

export const useToast = () => {
  const context = useContext(ToastContext);
  if (!context) {
    throw new Error('useToast must be used within a ToastProvider');
  }
  return context;
};
