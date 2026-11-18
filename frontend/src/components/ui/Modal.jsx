export default function Modal({ titulo, children }) {
  return (
    <div className="fixed inset-0 bg-black/40 flex items-center justify-center p-4 z-50">
      <div
        role="dialog"
        aria-modal="true"
        aria-labelledby="modal-titulo"
        className="bg-white rounded-xl max-w-lg w-full p-6 shadow-xl max-h-[90vh] overflow-y-auto"
      >
        <h2 id="modal-titulo" className="text-lg font-bold text-slate-800 mb-4">{titulo}</h2>
        {children}
      </div>
    </div>
  );
}
