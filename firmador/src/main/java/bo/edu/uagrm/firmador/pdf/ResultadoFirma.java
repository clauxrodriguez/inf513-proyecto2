package bo.edu.uagrm.firmador.pdf;

/**
 * pdfFirmado: PDF con la firma incrustada (guardado incremental).
 * hashDocumento: SHA-256 del legajo original que se firmó.
 * hashFirmado: SHA-256 del PDF resultante.
 * firmaCms: firma CMS desacoplada en Base64 (lo que se guarda en firma_digital.firma_valor).
 */
public record ResultadoFirma(
        byte[] pdfFirmado,
        String hashDocumento,
        String hashFirmado,
        String firmaCms,
        String algoritmo,
        String numeroSerieCertificado,
        String fechaFirma) {
}
