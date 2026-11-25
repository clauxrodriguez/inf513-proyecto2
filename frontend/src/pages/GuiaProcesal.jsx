import { useEffect, useState } from 'react';
import { obtenerGuiaProcesal } from '../api/cuatroApi';
import Alerta from '../components/ui/Alerta';
import { moneda, UNIDAD_COBRO } from '../components/tramites/calculosTramite';

const NOMBRE_TIPO = { LEGALIZACION: 'Legalización de documentos', DESCUENTO_DIPLOMADO: 'Descuento del 50% para diplomado' };

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

  const circuitos = (guia.flujo || []).reduce((grupos, paso) => {
    (grupos[paso.tipo] ||= []).push(paso);
    return grupos;
  }, {});

  return (
    <div className="mx-auto max-w-6xl space-y-5">
      <header>
        <h1 className="text-xl font-bold text-slate-800">Guía Procesal Institucional</h1>
        <p className="text-sm text-slate-500">Circuito desde el origen facultativo hasta el certificado con QR, cadena de firmas por documento y requisitos.</p>
      </header>
      {cargando && <p className="text-sm text-slate-500">Cargando guía...</p>}
      {!cargando && error && <Alerta mensaje={error} />}
      {!cargando && !error && <>
        <section className="space-y-3">
          <h2 className="text-lg font-semibold text-slate-800">Circuitos de flujo</h2>
          {Object.entries(circuitos).map(([tipo, pasos]) => (
            <article key={tipo} className="rounded-xl border border-slate-200 bg-white p-5">
              <h3 className="mb-3 font-semibold text-blue-900">{NOMBRE_TIPO[tipo] || tipo}</h3>
              <ol className="grid gap-3 md:grid-cols-2">
                {pasos.map((paso) => (
                  <li key={`${tipo}-${paso.orden}`} className="rounded-lg bg-slate-50 p-3">
                    <p className="text-xs font-semibold text-slate-500">Paso {paso.orden} · {paso.unidad}{paso.destino !== 'TODOS' ? ` · Solo ${paso.destino}` : ''}</p>
                    <p className="font-medium text-slate-800">{paso.paso}</p>
                    <p className="mt-1 text-sm text-slate-600">{paso.descripcion}</p>
                    {(paso.firma_requerida || paso.sello_requerido) && (
                      <p className="mt-2 text-xs text-slate-500">{[paso.firma_requerida, paso.sello_requerido].filter(Boolean).join(' · ')}</p>
                    )}
                  </li>
                ))}
              </ol>
            </article>
          ))}
        </section>

        <section className="space-y-3">
          <h2 className="text-lg font-semibold text-slate-800">Documentos legalizables y su cadena de firmas</h2>
          <div className="grid gap-3 md:grid-cols-2">
            {(guia.documentos || []).map((documento) => (
              <article key={documento.id} className="rounded-xl border border-slate-200 bg-white p-5">
                <h3 className="font-semibold text-slate-800">{documento.nombre}</h3>
                <p className="mt-1 text-sm text-slate-500">Emite: {documento.unidad_emisora} · {moneda(documento.tarifa)} {UNIDAD_COBRO[documento.tipo_cobro]}</p>
                <ol className="mt-3 space-y-2">
                  {documento.firmas_precedentes.map((firma) => (
                    <li key={firma.orden} className="rounded-lg bg-blue-50 p-3 text-sm text-blue-950">
                      {firma.orden}. {firma.unidad} · {firma.cargo_firmante}{firma.requiere_sello ? ' · con sello' : ''}
                    </li>
                  ))}
                </ol>
              </article>
            ))}
          </div>
        </section>

        <section className="rounded-xl border border-slate-200 bg-white p-5">
          <h2 className="mb-3 text-lg font-semibold text-slate-800">Requisitos</h2>
          <ul className="grid gap-2 md:grid-cols-2">
            {(guia.requisitos || []).map((requisito) => (
              <li key={requisito.id} className="rounded-lg border border-slate-100 p-3 text-sm">
                <span className="font-medium text-slate-800">{NOMBRE_TIPO[requisito.tipo] || requisito.tipo}</span>
                <p className="text-slate-600">{requisito.descripcion}</p>
                <p className="mt-1 text-xs text-slate-500">{requisito.obligatorio ? 'Obligatorio' : 'Opcional'}</p>
              </li>
            ))}
          </ul>
        </section>
      </>}
    </div>
  );
}
