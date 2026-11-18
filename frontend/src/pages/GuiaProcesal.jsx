import { useEffect, useState } from 'react';
import { obtenerGuiaProcesal } from '../api/cuatroApi';
import Alerta from '../components/ui/Alerta';

export default function GuiaProcesal() {
  const [guia, setGuia] = useState({ flujo: [], documentos: [], requisitos: [] });
  const [error, setError] = useState('');
  const [cargando, setCargando] = useState(true);

  useEffect(() => {
    obtenerGuiaProcesal()
      .then((resultado) => setGuia(resultado.data))
      .catch((err) => setError(err.response?.data?.message || 'No se pudo cargar la guía procesal.'))
      .finally(() => setCargando(false));
  }, []);

  const gruposFlujo = (guia.flujo || []).reduce((grupos, paso) => {
    const clave = `${paso.tipo} · ${paso.destino}`;
    if (!grupos[clave]) grupos[clave] = [];
    grupos[clave].push(paso);
    return grupos;
  }, {});
  const documentos = (guia.documentos || []).reduce((grupos, item) => {
    if (!grupos[item.documento_codigo]) grupos[item.documento_codigo] = { ...item, firmas: [] };
    if (item.orden) grupos[item.documento_codigo].firmas.push(item);
    return grupos;
  }, {});

  return (
    <div className="mx-auto max-w-6xl space-y-5">
      <header>
        <h1 className="text-xl font-bold text-slate-800">Guía Procesal Institucional</h1>
        <p className="text-sm text-slate-500">Circuitos de legalización, firmas precedentes y requisitos del esquema vigente.</p>
      </header>
      {cargando && <p className="text-sm text-slate-500">Cargando guía...</p>}
      {!cargando && error && <Alerta mensaje={error} />}
      {!cargando && !error && <>
        <section className="space-y-3">
          <h2 className="text-lg font-semibold text-slate-800">Circuitos de flujo</h2>
          {Object.entries(gruposFlujo).map(([clave, pasos]) => (
            <article key={clave} className="rounded-xl border border-slate-200 bg-white p-5">
              <h3 className="mb-3 font-semibold text-blue-900">{clave.replaceAll('_', ' ')}</h3>
              <ol className="grid gap-3 md:grid-cols-2">
                {pasos.map((paso) => <li key={`${clave}-${paso.orden}`} className="rounded-lg bg-slate-50 p-3">
                  <p className="text-xs font-semibold text-slate-500">Paso {paso.orden} · {paso.unidad}</p>
                  <p className="font-medium text-slate-800">{paso.paso}</p>
                  <p className="mt-1 text-sm text-slate-600">{paso.descripcion}</p>
                  <p className="mt-2 text-xs text-slate-500">
                    {paso.firma_requerida ? 'Firma requerida' : 'Firma no requerida'}
                    {paso.sello_requerido ? ' · Sello requerido' : ''}
                  </p>
                </li>)}
              </ol>
            </article>
          ))}
          {!Object.keys(gruposFlujo).length && <p className="text-sm text-slate-500">No hay pasos procesales configurados.</p>}
        </section>

        <section className="space-y-3">
          <h2 className="text-lg font-semibold text-slate-800">Procedencia y sellos por documento</h2>
          <div className="grid gap-3 md:grid-cols-2">
            {Object.entries(documentos).map(([codigo, documento]) => (
              <article key={codigo} className="rounded-xl border border-slate-200 bg-white p-5">
                <h3 className="font-semibold text-slate-800">{codigo} · {documento.documento}</h3>
                <p className="mt-1 text-sm text-slate-500">Unidad emisora: {documento.unidad_emisora}</p>
                <ol className="mt-3 space-y-2">
                  {documento.firmas.map((firma) => <li key={`${codigo}-${firma.orden}`} className="rounded-lg bg-blue-50 p-3 text-sm text-blue-950">
                    {firma.orden}. {firma.unidad_firma} · {firma.cargo_firmante}{firma.requiere_sello ? ' · requiere sello' : ''}
                  </li>)}
                  {!documento.firmas.length && <li className="text-sm text-slate-500">No hay firmas previas configuradas.</li>}
                </ol>
              </article>
            ))}
          </div>
        </section>

        <section className="rounded-xl border border-slate-200 bg-white p-5">
          <h2 className="mb-3 text-lg font-semibold text-slate-800">Requisitos obligatorios</h2>
          <ul className="grid gap-2 md:grid-cols-2">
            {(guia.requisitos || []).map((requisito) => <li key={requisito.id} className="rounded-lg border border-slate-100 p-3 text-sm">
              <span className="font-medium text-slate-800">{requisito.tipo?.replaceAll('_', ' ')}</span>
              <p className="text-slate-600">{requisito.descripcion}</p>
              <p className="mt-1 text-xs text-slate-500">{requisito.obligatorio ? 'Obligatorio' : 'Opcional'}{requisito.pais_aplica ? ` · ${requisito.pais_aplica}` : ''}</p>
            </li>)}
            {!guia.requisitos?.length && <li className="text-sm text-slate-500">No hay requisitos configurados.</li>}
          </ul>
        </section>
      </>}
    </div>
  );
}
