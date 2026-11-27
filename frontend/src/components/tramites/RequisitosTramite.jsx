import { useMemo } from 'react';
import { calcularImporte, calcularMontoTotal, moneda, UNIDAD_COBRO } from './calculosTramite';

/**
 * Construye dinámicamente los pasos reales y requisitos según el tipo de trámite, el destino
 * (NACIONAL / EXTERIOR) y los documentos específicos seleccionados por el estudiante.
 */
export function construirRutaLegalizacion({ documentosCatalogo, seleccionados, destino, paisDestino }) {
  const docsElegidos = seleccionados
    .map((item) => {
      const doc = documentosCatalogo.find((d) => Number(d.id) === Number(item.catalogo_documento_id));
      return doc ? { ...doc, item } : null;
    })
    .filter(Boolean);

  const pasos = [];
  let orden = 1;

  if (docsElegidos.length > 0) {
    const multiples = docsElegidos.length > 1;
    docsElegidos.forEach((doc) => {
      const firmas = doc.firmas_precedentes || [];
      firmas.forEach((firma, idx) => {
        const esEmision = idx === 0;
        const tituloBase = esEmision ? 'Emisión y firma en origen' : 'Visado y firma en origen';
        pasos.push({
          orden: orden++,
          unidad: firma.unidad,
          paso: multiples
            ? `${tituloBase}: ${doc.nombre} (${firma.orden}/${firmas.length})`
            : (firmas.length > 1 ? `${tituloBase} (${firma.orden}/${firmas.length})` : tituloBase),
          descripcion: esEmision
            ? `Emisión digital de «${doc.nombre}» en ${firma.unidad} y firma de ${firma.cargo_firmante}${firma.requiere_sello ? ' con sello institucional' : ''}.`
            : `Revisión y co-firma digital de «${doc.nombre}» en ${firma.unidad} por ${firma.cargo_firmante}${firma.requiere_sello ? ' con sello institucional' : ''}.`,
          firma: firma.cargo_firmante,
          sello: firma.requiere_sello ? `Firma Digital PAdES + Sello (${firma.unidad})` : `Firma Digital PAdES (${firma.unidad})`,
          documento: doc.nombre,
        });
      });
    });
  } else {
    pasos.push({
      orden: orden++,
      unidad: 'Unidad Académica Emisora (según documento)',
      paso: 'Emisión y cadena de firmas en origen',
      descripcion: 'Seleccione un documento abajo para ver exactamente en qué oficina se emite y qué autoridades lo firman y sellan.',
      firma: 'Autoridad de la unidad emisora',
      sello: 'Firma Digital PAdES Origen',
    });
  }

  const total = calcularMontoTotal(documentosCatalogo, seleccionados);
  pasos.push({
    orden: orden++,
    unidad: 'Caja / Tesorería (o Pago en línea)',
    paso: 'Pago de arancel universitario',
    descripcion: docsElegidos.length > 0
      ? `Cancelación del arancel (${ docsElegidos.map((d) => `${d.nombre}: ${moneda(calcularImporte(d, d.item))} ${UNIDAD_COBRO[d.tipo_cobro]}`).join(' + ') } · Total referencial: ${moneda(total)}).`
      : 'Registro y verificación de recibos emitidos por Tesorería o pago en línea según el arancel del documento.',
    firma: null,
    sello: 'Comprobante de Caja Validado',
  });

  pasos.push({
    orden: orden++,
    unidad: 'Vicerrectorado - Ventanilla de Legalizaciones',
    paso: 'Recepción, cotejo y compilación de legajo',
    descripcion: 'Verificación criptográfica de las firmas previas, control de requisitos y ensamblaje del expediente en PDF/A con foliación continua y huella SHA-256.',
    firma: 'Encargado/a de Ventanilla',
    sello: 'Foliación continua y hash SHA-256',
  });

  pasos.push({
    orden: orden++,
    unidad: 'Vicerrectorado',
    paso: 'Firma digital de despacho',
    descripcion: 'Firma digital PAdES de despacho sobre el expediente compilado.',
    firma: 'Vicerrector/a (o Decano/a Suplente habilitado)',
    sello: 'Firma Digital Vicerrectorado',
  });

  if (destino === 'EXTERIOR') {
    pasos.push({
      orden: orden++,
      unidad: 'Rectorado',
      paso: `Visado institucional Rectorado (Exterior${paisDestino?.trim() ? ` · ${paisDestino.trim()}` : ''})`,
      descripcion: 'Firma y sello digital institucional final del Rectorado previo al trámite de Apostilla en Cancillería.',
      firma: 'Rector/a',
      sello: 'Firma Digital y Sello Institucional Rectorado',
    });
  }

  pasos.push({
    orden: orden++,
    unidad: 'Vicerrectorado - Ventanilla de Legalizaciones',
    paso: 'Emisión de Certificado Oficial QR y entrega',
    descripcion: 'Generación del Certificado Oficial PDF/A-2b con código QR verificable públicamente.',
    firma: 'Sello Electrónico Vicerrectorado',
    sello: 'Código QR verificable + PDF/A-2b',
  });

  return { pasos, docsElegidos, total };
}

export default function RequisitosTramite({ requisitos, tipo, documentos = [], seleccionados = [], destino = 'NACIONAL', paisDestino = '' }) {
  const ruta = useMemo(
    () => (tipo === 'LEGALIZACION' ? construirRutaLegalizacion({ documentosCatalogo: documentos, seleccionados, destino, paisDestino }) : null),
    [tipo, documentos, seleccionados, destino, paisDestino],
  );

  const aplicables = useMemo(() => {
    const base = requisitos.filter((requisito) => requisito.tipo === tipo);
    if (tipo !== 'LEGALIZACION') return base;

    const unidadesFirmantes = [...new Set(
      (ruta?.docsElegidos || []).flatMap((doc) => (doc.firmas_precedentes || []).map((f) => f.unidad)),
    )];
    const nombresDocs = (ruta?.docsElegidos || []).map((d) => d.nombre);

    return base
      .filter((req) => req.codigo !== 'FOJAS_SEPARADAS' || destino === 'EXTERIOR')
      .map((req) => {
        if (req.codigo === 'CARTA' && nombresDocs.length > 0) {
          return {
            ...req,
            descripcion: `Solicitud digital dirigida al Vicerrectorado para: ${nombresDocs.join(', ')}${destino === 'EXTERIOR' ? ` (Destino exterior${paisDestino?.trim() ? `: ${paisDestino.trim()}` : ''})` : ' (Destino nacional)'}`,
          };
        }
        if (req.codigo === 'COMPROBANTE' && nombresDocs.length > 0) {
          return {
            ...req,
            descripcion: `Comprobante de caja o pago en línea validado por el importe esperado (referencial: ${moneda(ruta.total)})`,
          };
        }
        if (req.codigo === 'FIRMAS_PREVIAS' && unidadesFirmantes.length > 0) {
          return {
            ...req,
            descripcion: `Firmas y sellos digitales precedentes completos en orden: ${unidadesFirmantes.join(' ? ')}`,
          };
        }
        return req;
      });
  }, [requisitos, tipo, destino, paisDestino, ruta]);

  if (!aplicables.length) return null;

  return (
    <div className="space-y-4">
      <section className="bg-blue-50 border border-blue-100 p-4 rounded-xl">
        <div className="flex flex-wrap items-center justify-between gap-2">
          <h2 className="text-sm font-bold text-blue-900">Requisitos que verificará la Ventanilla</h2>
          {tipo === 'LEGALIZACION' && (
            <span className="rounded-full bg-blue-100 px-2.5 py-0.5 text-xs font-medium text-blue-900">
              {ruta?.docsElegidos.length
                ? `Adaptado a ${ruta.docsElegidos.length} documento(s) · ${destino}`
                : `Legalización ${destino}`}
            </span>
          )}
        </div>
        <ul className="mt-2 space-y-1">
          {aplicables.map((requisito) => (
            <li key={requisito.id} className="text-sm text-slate-700">
              <span className="font-medium">{requisito.obligatorio ? 'Obligatorio' : 'Opcional'}:</span> {requisito.descripcion}
            </li>
          ))}
        </ul>
      </section>

      {tipo === 'LEGALIZACION' && ruta && (
        <section className="rounded-xl border border-slate-200 bg-white p-4">
          <div className="mb-3 flex flex-wrap items-center justify-between gap-2">
            <div>
              <h2 className="text-sm font-bold text-slate-800">Ruta exacta de pasos, firmas y sellos a seguir</h2>
              <p className="text-xs text-slate-500">
                {ruta.docsElegidos.length
                  ? 'Este es el circuito real por donde pasará su trámite según los documentos y el destino seleccionados.'
                  : 'Agregue un documento abajo para ver por cuáles oficinas, firmas y sellos pasará exactamente.'}
              </p>
            </div>
          </div>
          <ol className="grid gap-2.5 md:grid-cols-2">
            {ruta.pasos.map((paso) => (
              <li key={paso.orden} className="rounded-lg border border-slate-200 bg-slate-50 p-3 text-xs">
                <p className="font-semibold text-blue-900">Paso {paso.orden} · Llevar / procesar en: {paso.unidad}</p>
                <p className="mt-0.5 text-sm font-medium text-slate-800">{paso.paso}</p>
                <p className="mt-1 text-slate-600">{paso.descripcion}</p>
                {(paso.firma || paso.sello) && (
                  <p className="mt-1.5 font-medium text-slate-700">
                    {[paso.firma ? `Firma: ${paso.firma}` : null, paso.sello ? `Sello/Respaldo: ${paso.sello}` : null].filter(Boolean).join(' · ')}
                  </p>
                )}
              </li>
            ))}
          </ol>
        </section>
      )}
    </div>
  );
}
