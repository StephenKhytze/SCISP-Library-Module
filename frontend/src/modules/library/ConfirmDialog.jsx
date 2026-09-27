import React, { createContext, useContext, useState, useCallback } from 'react';
import { createPortal } from 'react-dom';
import useDialog from './hooks/useDialog';

const ConfirmDialogContext = createContext(null);

export function ConfirmDialogProvider({ children }) {
  const [dialogState, setDialogState] = useState({
    isOpen: false,
    title: '',
    message: '',
    // Optional node rendered under the message — a change summary, a list of
    // blockers. Callers that pass nothing get exactly the dialog they had.
    content: null,
    confirmText: 'Confirm',
    cancelText: 'Cancel',
    onConfirm: () => { },
    onCancel: () => { },
    isDestructive: false
  });

  // Escape, focus move, focus trap and focus restore all live in the hook.
  const panelRef = useDialog(dialogState.isOpen, dialogState.onCancel);

  const confirm = useCallback(({ title, message, content = null, confirmText = 'Confirm', cancelText = 'Cancel', isDestructive = false }) => {
    return new Promise((resolve) => {
      setDialogState({
        isOpen: true,
        title,
        message,
        content,
        confirmText,
        cancelText,
        isDestructive,
        onConfirm: () => {
          setDialogState((prev) => ({ ...prev, isOpen: false }));
          resolve(true);
        },
        onCancel: () => {
          setDialogState((prev) => ({ ...prev, isOpen: false }));
          resolve(false);
        }
      });
    });
  }, []);

  return (
    <ConfirmDialogContext.Provider value={confirm}>
      {children}
      {dialogState.isOpen && createPortal(
        <div className="fixed inset-0 z-[60] flex items-center justify-center p-4">
          <div
            className="absolute inset-0 bg-black/40 backdrop-blur-sm anim-fade-in"
            onClick={dialogState.onCancel}
            aria-hidden="true"
          />
          <div
            ref={panelRef}
            role="dialog"
            aria-modal="true"
            aria-labelledby="confirm-dialog-title"
            aria-describedby="confirm-dialog-message"
            className={`bg-white rounded-2xl shadow-xl w-full overflow-hidden relative z-[70] anim-zoom-in ${
              dialogState.content ? 'max-w-md' : 'max-w-sm'
            }`}
          >
            <div className="p-5">
              <h3 id="confirm-dialog-title" className="text-lg font-bold text-gray-900 mb-2">{dialogState.title}</h3>
              <p id="confirm-dialog-message" className="text-sm text-gray-600 leading-relaxed">{dialogState.message}</p>
              {dialogState.content}
            </div>
            <div className="px-5 py-4 bg-gray-50 flex justify-end gap-2.5 border-t border-gray-100">
              <button
                onClick={dialogState.onCancel}
                className="px-4 py-2 text-[13px] font-extrabold text-slate-500 hover:text-slate-700 hover:bg-slate-200/50 rounded-xl transition-all duration-150 active:scale-[0.98] cursor-pointer"
              >
                {dialogState.cancelText}
              </button>
              <button
                onClick={dialogState.onConfirm}
                className={`px-4 py-2 text-[13px] font-extrabold text-white rounded-xl transition-all duration-150 active:scale-[0.98] cursor-pointer shadow-sm ${dialogState.isDestructive
                    ? 'bg-rose-600 hover:bg-rose-700 shadow-rose-200'
                    : 'bg-[#0f172a] hover:bg-[#1e293b] shadow-slate-200'
                  }`}
              >
                {dialogState.confirmText}
              </button>
            </div>
          </div>
        </div>,
        document.body
      )}
    </ConfirmDialogContext.Provider>
  );
}

export const useConfirm = () => {
  const context = useContext(ConfirmDialogContext);
  if (!context) {
    throw new Error('useConfirm must be used within a ConfirmDialogProvider');
  }
  return context;
};
