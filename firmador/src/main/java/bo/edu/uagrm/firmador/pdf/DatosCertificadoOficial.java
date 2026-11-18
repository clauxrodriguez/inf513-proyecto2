package bo.edu.uagrm.firmador.pdf;

import java.util.List;

/**
 * Datos del certificado oficial (CL-) o constancia de descuento (CD-) que envía Laravel (CU6).
 * qrContenido: URL del validador público con el código de verificación y el hash del expediente firmado.
 */
public record DatosCertificadoOficial(
        String numero,
        String tipo,
        String codigoTramite,
        String titular,
        String ci,
        String registro,
        String carrera,
        String destino,
        String paisDestino,
        String institucionDestino,
        String fechaEmision,
        String fechaFirma,
        String leyendaFirma,
        String codigoVerificacion,
        String hashExpediente,
        String qrContenido,
        Integer totalFojas,
        List<Documento> documentos,
        Descuento descuento) {

    public record Documento(String nombre, Integer folioInicio, Integer folioFin) {
    }

    public record Descuento(String nombreDiplomado, String unidadPostgrado, String gestion, String financiamiento) {
    }

    public boolean esConstancia() {
        return "CONSTANCIA_DESCUENTO".equals(tipo) || "DESCUENTO_DIPLOMADO".equals(tipo);
    }
}
