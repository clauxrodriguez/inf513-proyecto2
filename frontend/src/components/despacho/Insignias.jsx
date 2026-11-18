const PRIORIDAD = {
  URGENTE: 'bg-red-100 text-red-700 border-red-200',
  ALTA: 'bg-amber-100 text-amber-800 border-amber-200',
  NORMAL: 'bg-slate-100 text-slate-600 border-slate-200',
};

const SEMAFORO = {
  ROJO: { punto: 'bg-red-500', texto: 'text-red-700', etiqueta: 'Vencido' },
  AMARILLO: { punto: 'bg-amber-400', texto: 'text-amber-700', etiqueta: 'En alerta' },
  VERDE: { punto: 'bg-emerald-500', texto: 'text-emerald-700', etiqueta: 'En plazo' },
};

export function InsigniaPrioridad({ prioridad }) {
  return (
    <span className={`inline-block rounded-full border px-2 py-0.5 text-xs font-semibold ${PRIORIDAD[prioridad] || PRIORIDAD.NORMAL}`}>
      {prioridad}
    </span>
  );
}

export function InsigniaSemaforo({ semaforo, horas, plazo }) {
  const estilo = SEMAFORO[semaforo] || SEMAFORO.VERDE;
  return (
    <span className={`inline-flex items-center gap-1.5 text-xs font-medium ${estilo.texto}`} title={`${estilo.etiqueta}: ${Number(horas || 0).toFixed(1)} de ${plazo || 72} horas hábiles`}>
      <span className={`h-2.5 w-2.5 rounded-full ${estilo.punto}`} />
      {Number(horas || 0).toFixed(1)} / {plazo || 72} h
    </span>
  );
}

export function InsigniaDestino({ destino, pais }) {
  const exterior = destino === 'EXTERIOR';
  return (
    <span className={`inline-block rounded px-2 py-0.5 text-xs font-medium ${exterior ? 'bg-indigo-50 text-indigo-700' : 'bg-sky-50 text-sky-700'}`}>
      {destino}{exterior && pais ? ` · ${pais}` : ''}
    </span>
  );
}

export function InsigniaCalidad({ calidad }) {
  if (!calidad) return <span className="rounded-full bg-slate-100 px-2.5 py-0.5 text-xs font-semibold text-slate-500">SIN HABILITACIÓN</span>;
  return (
    <span className={`rounded-full px-2.5 py-0.5 text-xs font-semibold ${calidad === 'TITULAR' ? 'bg-blue-100 text-blue-800' : 'bg-violet-100 text-violet-800'}`}>
      {calidad}
    </span>
  );
}
