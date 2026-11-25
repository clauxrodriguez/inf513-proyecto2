import { useCallback, useEffect, useState } from 'react';
import { FileDown, FileSpreadsheet, Filter } from 'lucide-react';
import { obtenerReporte } from '../api/estadisticasApi';
import { exportarExcel, exportarPdf } from '../components/graficos/exportar';
import PanelEstadisticas from '../components/graficos/PanelEstadisticas';
import { mensajeErrorApi, nombreEstado } from '../components/tramites/calculosTramite';
import Alerta from '../components/ui/Alerta';

/** Conjuntos de datos del reporte: columnas para la tabla y para la exportación a Excel. */
const CONJUNTOS = [
  { clave: 'estado', titulo: 'Trámites por estado', filas: (r) => r.negocio.por_estado.map((f) => ({ ...f, estado: nombreEstado(f.estado) })), columnas: [['estado', 'Estado'], ['total', 'Trámites']] },
  { clave: 'carrera', titulo: 'Trámites por carrera', filas: (r) => r.negocio.por_carrera, columnas: [['carrera', 'Carrera'], ['facultad', 'Facultad'], ['total', 'Trámites'], ['concluidos', 'Concluidos']] },
  { clave: 'tipo', titulo: 'Trámites por tipo y destino', filas: (r) => r.negocio.por_tipo, columnas: [['tipo', 'Tipo'], ['destino', 'Destino'], ['total', 'Trámites']] },
  { clave: 'recaudacion', titulo: 'Recaudación por mes y concepto', filas: (r) => r.negocio.recaudacion, columnas: [['mes', 'Mes'], ['concepto', 'Concepto'], ['recibos', 'Recibos'], ['total', 'Total (Bs)']] },
  { clave: 'indicadores', titulo: 'Indicadores de gestión', filas: (r) => r.negocio.indicadores.map((i) => ({ ...i, cumple: i.cumple === null ? '—' : i.cumple ? 'Sí' : 'No' })), columnas: [['nombre', 'Indicador'], ['valor', 'Valor'], ['unidad_medida', 'Unidad'], ['meta', 'Meta'], ['cumple', 'Cumple']] },
  { clave: 'accesos', titulo: 'Accesos por día', filas: (r) => r.accesos.por_dia, columnas: [['dia', 'Día'], ['login_ok', 'Inicios correctos'], ['login_fallido', 'Inicios fallidos'], ['accesos', 'Accesos a la API']] },
  { clave: 'recursos', titulo: 'Recursos más accedidos', filas: (r) => r.accesos.recursos, columnas: [['metodo_http', 'Método'], ['ruta', 'Recurso'], ['total', 'Accesos'], ['usuarios', 'Usuarios']] },
  { clave: 'paginas', titulo: 'Páginas más visitadas', filas: (r) => r.accesos.paginas, columnas: [['titulo_pagina', 'Página'], ['ruta', 'Ruta'], ['visitas', 'Visitas']] },
  { clave: 'ips', titulo: 'IP con más intentos fallidos', filas: (r) => r.accesos.ips_fallidos, columnas: [['ip_origen', 'IP'], ['intentos', 'Intentos'], ['correos', 'Correos distintos'], ['ultimo', 'Último intento']] },
];

function TablaConjunto({ conjunto, reporte, sufijo }) {
  const filas = conjunto.filas(reporte);
  return (
    <section className="rounded-xl border border-slate-200 bg-white">
      <div className="flex items-center justify-between gap-2 border-b border-slate-200 px-4 py-2.5">
        <h3 className="text-sm font-semibold text-slate-800">{conjunto.titulo}</h3>
        <button type="button" onClick={() => exportarExcel(`${conjunto.clave}_${sufijo}`, conjunto.columnas, filas)}
          className="no-imprimir inline-flex items-center gap-1 text-xs text-blue-800 hover:underline">
          <FileSpreadsheet className="h-3.5 w-3.5" aria-hidden="true" />Excel
        </button>
      </div>
      <div className="overflow-x-auto">
        <table className="w-full text-left text-xs">
          <thead className="bg-slate-50 uppercase text-slate-600"><tr>{conjunto.columnas.map(([clave, titulo]) => <th key={clave} className="px-3 py-2">{titulo}</th>)}</tr></thead>
          <tbody className="divide-y divide-slate-100">
            {filas.map((fila, i) => <tr key={i}>{conjunto.columnas.map(([clave]) => <td key={clave} className="px-3 py-1.5 tabular-nums text-slate-700">{fila[clave] ?? '—'}</td>)}</tr>)}
            {!filas.length && <tr><td colSpan={conjunto.columnas.length} className="px-3 py-3 text-slate-500">Sin datos en el periodo.</td></tr>}
          </tbody>
        </table>
      </div>
    </section>
  );
}

/** Reportes estadísticos con filtro de periodo, gráficos, vista en tabla y exportación a Excel y PDF. */
export default function Reportes() {
  const [filtro, setFiltro] = useState({ desde: '', hasta: '' });
  const [aplicado, setAplicado] = useState({});
  const [reporte, setReporte] = useState(null);
  const [error, setError] = useState('');
  const [cargando, setCargando] = useState(true);

  const cargar = useCallback(async (params) => {
    setCargando(true);
    try {
      setReporte({ ...(await obtenerReporte(params)).data, generado: new Date().toLocaleString('es-BO') });
      setError('');
    } catch (err) {
      setError(mensajeErrorApi(err, 'No se pudo generar el reporte.'));
    } finally {
      setCargando(false);
    }
  }, []);

  useEffect(() => { cargar({}); }, [cargar]);

  const aplicar = (event) => {
    event.preventDefault();
    if (filtro.desde && filtro.hasta && filtro.hasta < filtro.desde) { setError('La fecha final debe ser igual o posterior a la inicial.'); return; }
    const params = Object.fromEntries(Object.entries(filtro).filter(([, valor]) => valor));
    setAplicado(params);
    cargar(params);
  };
  const sufijo = `${aplicado.desde || 'inicio'}_${aplicado.hasta || 'hoy'}`;

  return (
    <div className="space-y-5">
      <header className="flex flex-wrap items-start justify-between gap-3">
        <div>
          <h1 className="text-xl font-bold text-slate-800">Reportes y Estadísticas</h1>
          <p className="text-sm text-slate-500">
            Periodo: {aplicado.desde || 'inicio'} a {aplicado.hasta || 'hoy'}{reporte ? ` · Generado el ${reporte.generado}` : ''}
          </p>
        </div>
        <button type="button" onClick={exportarPdf} disabled={!reporte}
          className="no-imprimir inline-flex items-center gap-2 rounded-lg bg-blue-900 px-4 py-2 text-sm font-medium text-white hover:bg-blue-800 disabled:opacity-50">
          <FileDown className="h-4 w-4" aria-hidden="true" />Exportar a PDF
        </button>
      </header>

      <form onSubmit={aplicar} noValidate className="no-imprimir flex flex-wrap items-end gap-3 rounded-xl border border-slate-200 bg-white p-4 text-sm">
        <label className="font-medium text-slate-700">Desde
          <input type="date" value={filtro.desde} max={filtro.hasta || undefined} onChange={(e) => setFiltro({ ...filtro, desde: e.target.value })} className="mt-1 block rounded-lg border border-slate-300 px-3 py-1.5" />
        </label>
        <label className="font-medium text-slate-700">Hasta
          <input type="date" value={filtro.hasta} min={filtro.desde || undefined} onChange={(e) => setFiltro({ ...filtro, hasta: e.target.value })} className="mt-1 block rounded-lg border border-slate-300 px-3 py-1.5" />
        </label>
        <button type="submit" className="inline-flex items-center gap-1.5 rounded-lg border border-slate-300 px-3 py-2 text-slate-700 hover:bg-slate-50">
          <Filter className="h-4 w-4" aria-hidden="true" />Aplicar filtro
        </button>
        <p className="text-xs text-slate-500">Sin fechas: trámites de todo el periodo y accesos de los últimos 14 días.</p>
      </form>

      <Alerta mensaje={error} />
      {cargando && <p className="text-sm text-slate-500">Generando reporte...</p>}
      {reporte && !cargando && (
        <>
          <PanelEstadisticas reporte={reporte} />
          <h2 className="pt-2 text-lg font-semibold text-slate-800">Datos del reporte</h2>
          <div className="grid gap-4 lg:grid-cols-2">
            {CONJUNTOS.map((conjunto) => <TablaConjunto key={conjunto.clave} conjunto={conjunto} reporte={reporte} sufijo={sufijo} />)}
          </div>
        </>
      )}
    </div>
  );
}
