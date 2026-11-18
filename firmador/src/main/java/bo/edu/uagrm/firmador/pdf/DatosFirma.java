package bo.edu.uagrm.firmador.pdf;

/**
 * Datos que el lector de PDF muestra en el panel de firmas.
 * razon: "Vicerrector/a titular" o "Vicerrector/a Interino/a según Resolución N° … del …" (Anexo, punto 4).
 */
public record DatosFirma(
        String nombre,
        String razon,
        String ubicacion,
        String contacto,
        String leyendaVisible,
        boolean visible) {

    /** Firma con sello visible en la última página (legajos). */
    public DatosFirma(String nombre, String razon, String ubicacion, String contacto, String leyendaVisible) {
        this(nombre, razon, ubicacion, contacto, leyendaVisible, true);
    }
}
