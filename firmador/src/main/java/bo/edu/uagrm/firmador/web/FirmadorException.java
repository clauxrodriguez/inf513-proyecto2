package bo.edu.uagrm.firmador.web;

import org.springframework.http.HttpStatus;

/** Error de negocio que se devuelve a Laravel como {"message": "..."} con el código indicado. */
public class FirmadorException extends RuntimeException {

    private final HttpStatus estado;

    public FirmadorException(HttpStatus estado, String mensaje) {
        super(mensaje);
        this.estado = estado;
    }

    public FirmadorException(HttpStatus estado, String mensaje, Throwable causa) {
        super(mensaje, causa);
        this.estado = estado;
    }

    public HttpStatus getEstado() {
        return estado;
    }
}
