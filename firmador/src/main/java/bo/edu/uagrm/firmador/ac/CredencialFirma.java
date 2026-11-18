package bo.edu.uagrm.firmador.ac;

import java.security.PrivateKey;
import java.security.cert.Certificate;
import java.security.cert.X509Certificate;

/** Llave privada del firmante y su cadena de certificados (firmante → AC raíz). */
public record CredencialFirma(PrivateKey clave, Certificate[] cadena) {

    public X509Certificate certificado() {
        return (X509Certificate) cadena[0];
    }
}
