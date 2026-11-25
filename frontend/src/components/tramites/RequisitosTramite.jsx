export default function RequisitosTramite({ requisitos, tipo }) {
  const aplicables = requisitos.filter((requisito) => requisito.tipo === tipo);
  if (!aplicables.length) return null;

  return (
    <section className="bg-blue-50 border border-blue-100 p-4 rounded-xl">
      <h2 className="text-sm font-bold text-blue-900">Requisitos que verificará la Ventanilla</h2>
      <ul className="mt-2 space-y-1">
        {aplicables.map((requisito) => (
          <li key={requisito.id} className="text-sm text-slate-700">
            <span className="font-medium">{requisito.obligatorio ? 'Obligatorio' : 'Opcional'}:</span> {requisito.descripcion}
          </li>
        ))}
      </ul>
    </section>
  );
}
