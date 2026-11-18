package bo.edu.uagrm.firmador.pdf;

import java.io.IOException;
import java.security.cert.X509Certificate;
import java.time.ZoneId;
import java.util.ArrayList;
import java.util.Collection;
import java.util.HexFormat;
import java.util.List;

import org.apache.pdfbox.Loader;
import org.apache.pdfbox.pdmodel.PDDocument;
import org.apache.pdfbox.pdmodel.interactive.digitalsignature.PDSignature;
import org.bouncycastle.asn1.ASN1InputStream;
import org.bouncycastle.asn1.ASN1Primitive;
import org.bouncycastle.asn1.cms.AttributeTable;
import org.bouncycastle.asn1.cms.ContentInfo;
import org.bouncycastle.asn1.cms.CMSAttributes;
import org.bouncycastle.asn1.pkcs.PKCSObjectIdentifiers;
import org.bouncycastle.asn1.x500.RDN;
import org.bouncycastle.asn1.x500.X500Name;
import org.bouncycastle.asn1.x500.style.BCStyle;
import org.bouncycastle.asn1.x500.style.IETFUtils;
import org.bouncycastle.cert.X509CertificateHolder;
import org.bouncycastle.cert.jcajce.JcaX509CertificateConverter;
import org.bouncycastle.cms.CMSProcessableByteArray;
import org.bouncycastle.cms.CMSSignedData;
import org.bouncycastle.cms.SignerInformation;
import org.bouncycastle.cms.jcajce.JcaSimpleSignerInfoVerifierBuilder;
import org.springframework.http.HttpStatus;
import org.springframework.stereotype.Service;

import bo.edu.uagrm.firmador.ac.AutoridadCertificadora;
import bo.edu.uagrm.firmador.web.FirmadorException;

/** Verifica cada firma incrustada: integridad criptográfica, cadena de confianza y cobertura del documento. */
@Service
public class VerificadorFirmaService {

    private final AutoridadCertificadora ac;

    public VerificadorFirmaService(AutoridadCertificadora ac) {
        this.ac = ac;
    }

    public record FirmaVerificada(
            int orden,
            String nombre,
            String razon,
            String ubicacion,
            String contacto,
            String fecha,
            String subFiltro,
            String firmante,
            String emisor,
            String numeroSerie,
            boolean integra,
            boolean confiable,
            boolean certificadoVigenteAlFirmar,
            boolean cubreTodoElDocumento,
            boolean signingCertificateV2,
            boolean sinSigningTime,
            String detalle) {
    }

    public record ResultadoVerificacion(boolean valido, int totalFirmas, String hashSha256, List<FirmaVerificada> firmas) {
    }

    public ResultadoVerificacion verificar(byte[] pdf) {
        List<FirmaVerificada> resultado = new ArrayList<>();
        try (PDDocument documento = Loader.loadPDF(pdf)) {
            List<PDSignature> firmas = documento.getSignatureDictionaries();
            int orden = 0;
            for (PDSignature firma : firmas) {
                orden++;
                resultado.add(verificarUna(orden, firma, pdf));
            }
        } catch (IOException e) {
            throw new FirmadorException(HttpStatus.UNPROCESSABLE_ENTITY, "El archivo no es un PDF válido.", e);
        }
        boolean valido = !resultado.isEmpty() && resultado.stream().allMatch(f -> f.integra() && f.confiable())
                && resultado.get(resultado.size() - 1).cubreTodoElDocumento();
        return new ResultadoVerificacion(valido, resultado.size(), FirmaPadesService.sha256(pdf), resultado);
    }

    private FirmaVerificada verificarUna(int orden, PDSignature firma, byte[] pdf) {
        String fecha = firma.getSignDate() == null ? null
                : firma.getSignDate().toInstant().atZone(ZoneId.of("America/La_Paz")).toOffsetDateTime().toString();
        int[] rango = firma.getByteRange();
        boolean cubreTodo = rango != null && rango.length == 4 && (long) rango[2] + rango[3] == pdf.length;
        try {
            byte[] contenidoFirmado = firma.getSignedContent(pdf);
            // /Contents viene rellenado con ceros hasta el tamaño reservado: se lee solo la estructura ASN.1
            ASN1Primitive estructura;
            try (ASN1InputStream lector = new ASN1InputStream(firma.getContents(pdf))) {
                estructura = lector.readObject();
            }
            CMSSignedData datos = new CMSSignedData(new CMSProcessableByteArray(contenidoFirmado),
                    ContentInfo.getInstance(estructura));
            SignerInformation info = datos.getSignerInfos().getSigners().iterator().next();
            @SuppressWarnings("unchecked")
            Collection<X509CertificateHolder> coincidencias = datos.getCertificates().getMatches(info.getSID());
            X509CertificateHolder holder = coincidencias.iterator().next();
            X509Certificate certificado = new JcaX509CertificateConverter().getCertificate(holder);

            boolean integra = info.verify(new JcaSimpleSignerInfoVerifierBuilder().build(holder));
            boolean confiable = ac.emitidoPorEstaAc(certificado);
            boolean vigente = firma.getSignDate() == null || holder.isValidOn(firma.getSignDate().getTime());
            AttributeTable atributos = info.getSignedAttributes();
            boolean scv2 = atributos != null && atributos.get(PKCSObjectIdentifiers.id_aa_signingCertificateV2) != null;
            boolean sinHora = atributos == null || atributos.get(CMSAttributes.signingTime) == null;

            return new FirmaVerificada(orden, firma.getName(), firma.getReason(), firma.getLocation(),
                    firma.getContactInfo(), fecha, firma.getSubFilter(),
                    nombreComun(holder.getSubject()), nombreComun(holder.getIssuer()),
                    HexFormat.of().withUpperCase().formatHex(certificado.getSerialNumber().toByteArray()).replaceFirst("^00", ""),
                    integra, confiable, vigente, cubreTodo, scv2, sinHora,
                    integra ? (confiable ? "Firma íntegra emitida por la AC de prueba." : "Firma íntegra de un emisor no reconocido.")
                            : "El contenido fue alterado después de la firma.");
        } catch (Exception e) {
            String mensaje = String.valueOf(e.getMessage());
            String detalle = mensaje.contains("message-digest")
                    ? "El contenido fue alterado después de la firma (el hash no coincide)."
                    : "No se pudo validar la firma: " + mensaje;
            return new FirmaVerificada(orden, firma.getName(), firma.getReason(), firma.getLocation(),
                    firma.getContactInfo(), fecha, firma.getSubFilter(), null, null, null,
                    false, false, false, cubreTodo, false, false, detalle);
        }
    }

    private static String nombreComun(X500Name nombre) {
        RDN[] cn = nombre.getRDNs(BCStyle.CN);
        return cn.length == 0 ? nombre.toString() : IETFUtils.valueToString(cn[0].getFirst().getValue());
    }
}
