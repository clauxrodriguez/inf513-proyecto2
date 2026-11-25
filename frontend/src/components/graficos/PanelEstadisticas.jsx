import { Activity, AlertOctagon, CheckCircle2, Clock, Inbox, Users } from 'lucide-react';
import BarrasHorizontales from './BarrasHorizontales';
import LineasDiarias from './LineasDiarias';
import TarjetaIndicador from './TarjetaIndicador';
import { nombreEstado } from '../tramites/calculosTramite';

const bolivianos = (v) => `Bs ${Number(v || 0).toLocaleString('es-BO', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;

function Cifra({ icono: Icono, etiqueta, valor, detalle }) {
  return (
    <article className="rounded-xl border border-slate-200 bg-white p-4">
      <p className="flex items-center gap-1.5 text-xs text-slate-500"><Icono className="h-3.5 w-3.5" aria-hidden="true" />{etiqueta}</p>
      <p className="mt-1 text-2xl font-bold text-slate-800">{valor}</p>
      {detalle && <p className="text-xs text-slate-500">{detalle}</p>}
    </article>
  );
}

/** Recaudación mensual: una barra por mes con el total de los recibos de todos los conceptos. */
const recaudacionPorMes = (filas) => Object.values(filas.reduce((meses, fila) => {
  const mes = meses[fila.mes] ||= { etiqueta: fila.mes, valor: 0, recibos: 0 };
  mes.valor += Number(fila.total);
  mes.recibos += Number(fila.recibos);
  return meses;
}, {})).map((mes) => ({ ...mes, detalle: `${mes.recibos} recibo(s)` }));

/** Gráficos de la actividad del negocio y del tráfico web (comparten el Dashboard y los Reportes). */
export default function PanelEstadisticas({ reporte }) {
  const { negocio, accesos } = reporte;
  const sla = negocio.sla;

  return (
    <div className="space-y-6">
      <section className="space-y-3">
        <h2 className="text-lg font-semibold text-slate-800">Actividad del negocio</h2>
        <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
          <Cifra icono={Inbox} etiqueta="Trámites ingresados" valor={negocio.totales.ingresados} detalle={`${negocio.totales.concluidos} concluidos`} />
          <Cifra icono={Clock} etiqueta="En curso (SLA 72 h)" valor={sla.en_curso} detalle={`${sla.verde} en plazo · ${sla.amarillo} en alerta · ${sla.rojo} vencidos`} />
          <Cifra icono={AlertOctagon} etiqueta="Detenidos por observación" valor={sla.detenidos} />
          <Cifra icono={CheckCircle2} etiqueta="Recaudación por recibos" valor={bolivianos(negocio.totales.recaudado)} />
        </div>
        <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
          {negocio.indicadores.map((indicador) => <TarjetaIndicador key={indicador.codigo} indicador={indicador} />)}
        </div>
        <div className="grid gap-4 lg:grid-cols-2">
          <BarrasHorizontales titulo="Trámites por estado" datos={negocio.por_estado.map((f) => ({ etiqueta: nombreEstado(f.estado), valor: Number(f.total) }))} />
          <BarrasHorizontales titulo="Trámites por carrera"
            datos={negocio.por_carrera.map((f) => ({ etiqueta: `${f.carrera} (${f.facultad})`, valor: Number(f.total), detalle: `${f.concluidos} concluidos` }))} />
          <BarrasHorizontales titulo="Trámites por tipo y destino" datos={negocio.por_tipo.map((f) => ({ etiqueta: `${f.tipo} · ${f.destino}`, valor: Number(f.total) }))} />
          <BarrasHorizontales titulo="Recaudación mensual (recibos de Caja)" datos={recaudacionPorMes(negocio.recaudacion)} formato={bolivianos} />
        </div>
      </section>

      <section className="space-y-3">
        <h2 className="text-lg font-semibold text-slate-800">Tráfico del sitio web</h2>
        <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
          <Cifra icono={Users} etiqueta="Inicios de sesión" valor={accesos.totales.logins_ok} detalle={`${accesos.totales.usuarios_distintos} usuario(s) distintos`} />
          <Cifra icono={AlertOctagon} etiqueta="Intentos fallidos" valor={accesos.totales.logins_fallidos} detalle={`desde ${accesos.ips_fallidos.length} IP`} />
          <Cifra icono={Activity} etiqueta="Accesos a la API" valor={accesos.totales.accesos} />
          <Cifra icono={Activity} etiqueta="IP distintas" valor={accesos.totales.ips_distintas} />
        </div>
        <LineasDiarias titulo="Inicios de sesión por día" descripcion={`${reporte.periodo.accesos_desde} a ${reporte.periodo.accesos_hasta}`}
          datos={accesos.por_dia.map((d) => ({ dia: d.dia, login_ok: Number(d.login_ok), login_fallido: Number(d.login_fallido) }))}
          series={[
            { clave: 'login_ok', nombre: 'Correctos', color: 'var(--serie-1)' },
            { clave: 'login_fallido', nombre: 'Fallidos', color: 'var(--serie-2)' },
          ]} />
        <div className="grid gap-4 lg:grid-cols-2">
          <BarrasHorizontales titulo="Recursos de la API más accedidos"
            datos={accesos.recursos.map((r) => ({ etiqueta: `${r.metodo_http} ${r.ruta}`, valor: Number(r.total), detalle: `${r.usuarios} usuario(s)` }))} />
          <BarrasHorizontales titulo="Páginas más visitadas (contador de visitas)"
            datos={accesos.paginas.map((p) => ({ etiqueta: p.titulo_pagina, valor: Number(p.visitas), detalle: p.ruta }))} />
        </div>
      </section>
    </div>
  );
}
