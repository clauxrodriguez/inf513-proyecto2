package bo.edu.uagrm.firmador.pdf;

import java.util.List;

/** Datos de la carátula del legajo que envía Laravel. */
public record MetadatosLegajo(
        String codigo,
        String tipo,
        String destino,
        String paisDestino,
        String estudiante,
        String ci,
        String registro,
        String carrera,
        Integer version,
        List<Documento> documentos) {

    public record Documento(String nombre, Integer folioInicio, Integer folioFin, String nroValorado, String archivo) {
    }
}
