package bo.edu.uagrm.firmador.ac;

import java.time.LocalDate;

public record CertificadoEmitido(
        String numeroSerie,
        String emisor,
        String sujeto,
        LocalDate fechaEmision,
        LocalDate fechaExpiracion,
        String certificadoPem) {
}
